<?php

/**
 * Imports and exports translation CSV data.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Transfer
{
    /**
     * @param array<int, array{line: int, source: string, translation: ?string}> $rows
     * @return array<int, string>
     */
    public function validate(array $rows, string $locale, int $storeId): array
    {
        $errors = [];
        $catalog = Mage::getModel('translations/catalog');
        if (!in_array($locale, $catalog->getLocales(), true)) {
            return ['The selected locale is not valid.'];
        }
        if (!array_key_exists($storeId, $catalog->getStoreOptions())) {
            return ['The selected store is not valid.'];
        }

        $sourceMap = $this->getSourceMap();
        $validator = new Maho_Translations_Model_Validator_Placeholder();

        foreach ($rows as $row) {
            $line = $row['line'];
            $source = $row['source'];
            $translation = $row['translation'];

            if (!isset($sourceMap[$source])) {
                $errors[] = "Row $line: source was not found in the scan index. Run a scan first.";
                continue;
            }

            if ($translation !== null && mb_strlen($translation, 'UTF-8') > 65535) {
                $errors[] = "Row $line: translation is longer than 65535 characters.";
                continue;
            }

            if ($translation === null || $translation === $source) {
                continue;
            }

            foreach ($validator->validate($source, $translation) as $error) {
                $errors[] = "Row $line: $error";
            }
        }

        return $errors;
    }

    /**
     * @param array<int, array{line: int, source: string, translation: ?string}> $rows
     * @return array{errors: array<int, string>, created: int, updated: int, cleared: int, unchanged: int}
     */
    public function import(array $rows, string $locale, int $storeId): array
    {
        $errors = $this->validate($rows, $locale, $storeId);
        if ($errors !== []) {
            return [
                'errors' => $errors,
                'created' => 0,
                'updated' => 0,
                'cleared' => 0,
                'unchanged' => 0,
            ];
        }

        $write = $this->getWriteConnection();
        $sourceMap = $this->getSourceMap();
        $catalog = Mage::getModel('translations/catalog');
        $counts = ['created' => 0, 'updated' => 0, 'cleared' => 0, 'unchanged' => 0];

        $write->beginTransaction();
        try {
            foreach ($rows as $row) {
                $source = $row['source'];
                $translation = $row['translation'];
                $sourceRow = $sourceMap[$source];
                $existing = $catalog->getDbTranslation($source, $locale, $storeId);

                if ($translation === null || $translation === $source) {
                    if ($existing !== null) {
                        $catalog->writeTranslation($sourceRow, null, $locale, $storeId);
                        $counts['cleared']++;
                    } else {
                        $counts['unchanged']++;
                    }
                    continue;
                }

                if ($existing === null) {
                    $catalog->writeTranslation($sourceRow, $translation, $locale, $storeId);
                    $counts['created']++;
                } elseif ($existing === $translation) {
                    $counts['unchanged']++;
                } else {
                    $catalog->writeTranslation($sourceRow, $translation, $locale, $storeId);
                    $counts['updated']++;
                }
            }
            $write->commit();
        } catch (Throwable $e) {
            $write->rollBack();
            throw $e;
        }

        $this->flushTranslationCache();

        return ['errors' => []] + $counts;
    }

    /**
     * @return array<int, array{source: string, translation: ?string}>
     */
    public function export(string $scope, string $locale, int $storeId): array
    {
        if ($scope === 'overrides') {
            return $this->exportOverrides($locale, $storeId);
        }

        $read = $this->getReadConnection();
        $adapter = $read;
        $coreTable = $this->table('core/translate');
        $longTable = $this->table('long');

        $select = $read->select()
            ->from(['main_table' => $this->table('source')], ['source_text'])
            ->columns(['effective' => new \Maho\Db\Expr(Maho_Translations_Model_Resource_Source_Collection::EFFECTIVE_SQL)])
            ->joinLeft(
                ['long_global' => $longTable],
                $adapter->quoteInto('long_global.source_hash = main_table.source_hash AND long_global.locale = ?', $locale)
                    . ' AND long_global.store_id = 0',
                [],
            )
            ->joinLeft(
                ['long_store' => $longTable],
                $adapter->quoteInto('long_store.source_hash = main_table.source_hash AND long_store.locale = ?', $locale)
                    . $adapter->quoteInto(' AND long_store.store_id = ?', $storeId),
                [],
            )
            ->joinLeft(
                ['pack' => $this->table('pack')],
                $adapter->quoteInto('pack.source_hash = main_table.source_hash AND pack.locale = ?', $locale),
                [],
            )
            ->joinLeft(
                ['db_global' => $coreTable],
                $adapter->quoteInto('db_global.store_id = 0 AND db_global.locale = ?', $locale)
                    . ' AND db_global.crc_string = main_table.source_crc AND db_global.string = main_table.source_text',
                [],
            )
            ->joinLeft(
                ['db_store' => $coreTable],
                $adapter->quoteInto('db_store.store_id = ?', $storeId)
                    . $adapter->quoteInto(' AND db_store.locale = ?', $locale)
                    . ' AND db_store.crc_string = main_table.source_crc AND db_store.string = main_table.source_text',
                [],
            )
            ->order('main_table.source_text ASC');

        if ($scope === 'missing') {
            $select->where(Maho_Translations_Model_Resource_Source_Collection::EFFECTIVE_SQL . ' = main_table.source_text');
        }

        $rows = [];
        foreach ($read->fetchAll($select) as $row) {
            $source = (string) $row['source_text'];
            $effective = (string) $row['effective'];
            $rows[] = [
                'source' => $source,
                'translation' => $effective === $source ? null : $effective,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array{source: string, translation: ?string}>
     */
    private function exportOverrides(string $locale, int $storeId): array
    {
        $read = $this->getReadConnection();
        $long = $read->fetchAll(
            $read->select()
                ->from($this->table('long'), ['source' => 'source_text', 'translation' => 'translation'])
                ->where('store_id = ?', $storeId)
                ->where('locale = ?', $locale),
        );

        $rows = [];
        foreach ($long as $row) {
            $rows[(string) $row['source']] = [
                'source' => (string) $row['source'],
                'translation' => (string) $row['translation'],
            ];
        }

        $core = $read->fetchAll(
            $read->select()
                ->from($this->table('core/translate'), ['source' => 'string', 'translation' => 'translate'])
                ->where('store_id = ?', $storeId)
                ->where('locale = ?', $locale),
        );
        foreach ($core as $row) {
            $source = (string) $row['source'];
            if (isset($rows[$source])) {
                continue;
            }
            $rows[$source] = [
                'source' => $source,
                'translation' => $row['translation'] === null ? null : (string) $row['translation'],
            ];
        }

        ksort($rows);
        return array_values($rows);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getSourceMap(): array
    {
        $read = $this->getReadConnection();
        $rows = $read->fetchAll(
            $read->select()->from($this->table('source'), ['source_text', 'source_hash', 'source_length']),
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['source_text']] = $row;
        }
        return $map;
    }

    private function flushTranslationCache(): void
    {
        Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);
    }

    private function getReadConnection(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function getWriteConnection(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function table(string $name): string
    {
        if (str_contains($name, '/')) {
            return Mage::getSingleton('core/resource')->getTableName($name);
        }
        return Mage::getSingleton('core/resource')->getTableName('translations/' . $name);
    }
}
