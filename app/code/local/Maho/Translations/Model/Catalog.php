<?php

/**
 * Reads translation sources, locations and effective translations.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Catalog
{
    /**
     * @return array<string, mixed>|false
     */
    public function getSource(int $sourceId): array|false
    {
        $read = $this->getReadConnection();
        return $read->fetchRow(
            $read->select()->from($this->table('source'))->where('source_id = ?', $sourceId),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLocations(int $sourceId): array
    {
        $read = $this->getReadConnection();
        return $read->fetchAll(
            $read->select()
                ->from($this->table('location'))
                ->where('source_id = ?', $sourceId)
                ->order('file ASC')
                ->order('line ASC'),
        );
    }

    public function getPackTranslation(string $sourceHash, string $locale): ?string
    {
        $read = $this->getReadConnection();
        $value = $read->fetchOne(
            $read->select()
                ->from($this->table('pack'), ['translation'])
                ->where('locale = ?', $locale)
                ->where('source_hash = ?', $sourceHash),
        );
        return $value === false || $value === null ? null : (string) $value;
    }

    public function getDbTranslation(string $sourceText, string $locale, int $storeId): ?string
    {
        if ($storeId > 0) {
            $value = $this->getStoreOverride($sourceText, $locale, $storeId);
            if ($value !== null) {
                return $value;
            }
        }

        return $this->getGlobalOverride($sourceText, $locale);
    }

    public function getStoreOverride(string $sourceText, string $locale, int $storeId): ?string
    {
        return $this->fetchLongTranslation($sourceText, $locale, $storeId)
            ?? $this->fetchDbTranslation($sourceText, $locale, $storeId);
    }

    public function getGlobalOverride(string $sourceText, string $locale): ?string
    {
        return $this->fetchLongTranslation($sourceText, $locale, 0)
            ?? $this->fetchDbTranslation($sourceText, $locale, 0);
    }

    /**
     * @param array<string, mixed> $source
     */
    public function getEffectiveTranslation(array $source, string $locale, int $storeId): string
    {
        $sourceText = (string) $source['source_text'];
        $database = $this->getDbTranslation($sourceText, $locale, $storeId);
        if ($database !== null) {
            return $database;
        }

        $pack = $this->getPackTranslation((string) $source['source_hash'], $locale);
        if ($pack !== null && trim($pack) !== '') {
            return $pack;
        }

        return $sourceText;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function saveTranslation(array $source, ?string $translation, string $locale, int $storeId): void
    {
        $this->writeTranslation($source, $translation, $locale, $storeId);
        $this->flushTranslationCache();
    }

    public function clearTranslation(string $sourceText, string $locale, int $storeId): void
    {
        $this->writeTranslation(
            ['source_text' => $sourceText, 'source_hash' => md5($sourceText)],
            null,
            $locale,
            $storeId,
        );
        $this->flushTranslationCache();
    }

    /**
     * Write or clear a translation without flushing the cache.
     *
     * @param array<string, mixed> $source
     */
    public function writeTranslation(
        array $source,
        ?string $translation,
        string $locale,
        int $storeId,
    ): void {
        $sourceText = (string) ($source['source_text'] ?? '');
        $sourceHash = (string) ($source['source_hash'] ?? md5($sourceText));

        if (!in_array($locale, $this->getLocales(), true)) {
            throw new Mage_Core_Exception('The selected locale is not valid.');
        }
        if (!array_key_exists($storeId, $this->getStoreOptions())) {
            throw new Mage_Core_Exception('The selected store is not valid.');
        }
        if (mb_strlen($sourceText, 'UTF-8') > 65535) {
            throw new Mage_Core_Exception('The source is longer than 65535 characters.');
        }
        if ($translation !== null && mb_strlen($translation, 'UTF-8') > 65535) {
            throw new Mage_Core_Exception('The translation is longer than 65535 characters.');
        }

        $hasTranslation = $translation !== null && trim($translation) !== '' && $translation !== $sourceText;
        if ($hasTranslation) {
            $validator = new Maho_Translations_Model_Validator_Placeholder();
            $errors = $validator->validate($sourceText, $translation);
            if ($errors !== []) {
                throw new Mage_Core_Exception(implode(' ', $errors));
            }
        }

        $write = $this->getWriteConnection();
        $ownsTransaction = $write->getTransactionLevel() === 0;
        if ($ownsTransaction) {
            $write->beginTransaction();
        }

        try {
            $this->deleteLongTranslation($sourceHash, $locale, $storeId);

            if (!$hasTranslation) {
                Mage::getResourceModel('core/translate_string')->deleteTranslate($sourceText, $locale, $storeId);
            } elseif (mb_strlen($sourceText, 'UTF-8') > 255 || mb_strlen((string) $translation, 'UTF-8') > 255) {
                Mage::getResourceModel('core/translate_string')->deleteTranslate($sourceText, $locale, $storeId);
                $now = Mage::app()->getLocale()->formatDateForDb('now');
                Mage::getResourceModel('translations/long')->upsert([
                    'source_hash' => $sourceHash,
                    'source_text' => $sourceText,
                    'translation' => $translation,
                    'locale' => $locale,
                    'store_id' => $storeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                Mage::getResourceModel('core/translate_string')->saveTranslate(
                    $sourceText,
                    (string) $translation,
                    $locale,
                    $storeId,
                );
            }

            if ($ownsTransaction) {
                $write->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                $write->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getSourceByHash(string $sourceHash): array|false
    {
        $read = $this->getReadConnection();
        return $read->fetchRow(
            $read->select()->from($this->table('source'))->where('source_hash = ?', $sourceHash),
        );
    }

    /**
     * @return array<int, string>
     */
    public function getLocales(): array
    {
        $locales = ['en_US' => true];
        foreach (Maho::listDirectories('app/locale') as $locale) {
            $locales[$locale] = true;
        }

        $read = $this->getReadConnection();
        foreach ([$this->table('pack'), $this->table('core/translate'), $this->table('long')] as $table) {
            $values = $read->fetchCol($read->select()->distinct()->from($table, ['locale']));
            foreach ($values as $locale) {
                if ((string) $locale !== '') {
                    $locales[(string) $locale] = true;
                }
            }
        }

        $result = array_keys($locales);
        sort($result);
        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function getModules(): array
    {
        $read = $this->getReadConnection();
        $values = $read->fetchCol(
            $read->select()->distinct()->from($this->table('source'), ['modules'])->where('modules <> ?', ''),
        );

        $modules = [];
        foreach ($values as $value) {
            foreach (explode(',', (string) $value) as $module) {
                if ($module !== '') {
                    $modules[$module] = true;
                }
            }
        }

        $result = array_keys($modules);
        sort($result);
        return $result;
    }

    /**
     * @return array{count: int, alternatives: array<int, string>}
     */
    public function getPackConflictInfo(string $sourceHash, string $locale): array
    {
        $read = $this->getReadConnection();
        $row = $read->fetchRow(
            $read->select()
                ->from($this->table('pack'), ['conflict_count', 'alternatives'])
                ->where('locale = ?', $locale)
                ->where('source_hash = ?', $sourceHash),
        );

        if (!$row || (int) $row['conflict_count'] === 0) {
            return ['count' => 0, 'alternatives' => []];
        }

        $alternatives = array_values(array_filter(explode("\n", (string) $row['alternatives']), static fn(string $line): bool => $line !== ''));
        return ['count' => (int) $row['conflict_count'], 'alternatives' => $alternatives];
    }

    /**
     * @return array<int, string>
     */
    public function getStoreOptions(): array
    {
        $options = [0 => Mage::helper('translations')->__('All Store Views')];
        foreach (Mage::app()->getWebsites() as $website) {
            foreach ($website->getGroups() as $group) {
                foreach ($group->getStores() as $store) {
                    $options[(int) $store->getId()] = sprintf('%s (%s)', $store->getName(), $website->getName());
                }
            }
        }
        return $options;
    }

    public function hasSources(): bool
    {
        $read = $this->getReadConnection();
        return (bool) $read->fetchOne(
            $read->select()->from($this->table('source'), ['source_id'])->limit(1),
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getScanStatus(): array|false
    {
        $read = $this->getReadConnection();
        return $read->fetchRow(
            $read->select()->from($this->table('scan'))->order('scan_id DESC')->limit(1),
        );
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        $helper = Mage::helper('translations');
        return [
            '' => $helper->__('All statuses'),
            'missing' => $helper->__('Missing'),
            'translated' => $helper->__('Translated'),
            'unused' => $helper->__('Unused'),
            'conflict' => $helper->__('Conflicting pack translations'),
            'long' => $helper->__('Long'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getDraftStatusOptions(): array
    {
        $helper = Mage::helper('translations');
        return [
            '' => $helper->__('No AI draft'),
            Maho_Translations_Model_Draft::STATUS_PENDING => $helper->__('Pending review'),
            Maho_Translations_Model_Draft::STATUS_APPROVED => $helper->__('Approved'),
            Maho_Translations_Model_Draft::STATUS_REJECTED => $helper->__('Rejected'),
            Maho_Translations_Model_Draft::STATUS_FAILED => $helper->__('Failed'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAreaOptions(): array
    {
        $helper = Mage::helper('translations');
        return [
            '' => $helper->__('All areas'),
            'frontend' => $helper->__('Frontend'),
            'adminhtml' => $helper->__('Adminhtml'),
            'install' => $helper->__('Install'),
        ];
    }

    private function fetchLongTranslation(string $sourceText, string $locale, int $storeId): ?string
    {
        $read = $this->getReadConnection();
        $value = $read->fetchOne(
            $read->select()
                ->from($this->table('long'), ['translation'])
                ->where('source_hash = ?', md5($sourceText))
                ->where('locale = ?', $locale)
                ->where('store_id = ?', $storeId),
        );
        return $value === false || $value === null || $value === '' ? null : (string) $value;
    }

    private function deleteLongTranslation(string $sourceHash, string $locale, int $storeId): void
    {
        Mage::getResourceModel('translations/long')->deleteByScope($sourceHash, $locale, $storeId);
    }

    private function fetchDbTranslation(string $sourceText, string $locale, int $storeId): ?string
    {
        $read = $this->getReadConnection();
        $value = $read->fetchOne(
            $read->select()
                ->from($this->table('core/translate'), ['translate'])
                ->where('store_id = ?', $storeId)
                ->where('locale = ?', $locale)
                ->where('crc_string = ?', crc32($sourceText))
                ->where('string = ?', $sourceText),
        );
        return $value === false || $value === null || $value === '' ? null : (string) $value;
    }

    private function flushTranslationCache(): void
    {
        Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);
    }

    private function getWriteConnection(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function getReadConnection(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('core_read');
    }

    private function table(string $name): string
    {
        if (str_contains($name, '/')) {
            return Mage::getSingleton('core/resource')->getTableName($name);
        }
        return Mage::getSingleton('core/resource')->getTableName('translations/' . $name);
    }
}
