<?php

/**
 * Translation source index collection with effective translation joins.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Resource_Source_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    public const EFFECTIVE_SQL = "COALESCE(NULLIF(long_store.translation, ''), NULLIF(long_global.translation, ''), NULLIF(db_store.translate, ''), NULLIF(db_global.translate, ''), NULLIF(pack.translation, ''), main_table.source_text)";

    private ?string $translationLocale = null;

    #[\Override]
    protected function _construct(): void
    {
        $this->_init('translations/source');
    }

    public function setTranslationScope(string $locale, int $storeId): static
    {
        if ($this->translationLocale !== null) {
            return $this;
        }

        $this->translationLocale = $locale;

        $resource = Mage::getSingleton('core/resource');
        $adapter = $this->getConnection();
        $select = $this->getSelect();

        $packTable = $resource->getTableName('translations/pack');
        $coreTable = $resource->getTableName('core/translate');

        $select->joinLeft(
            ['pack' => $packTable],
            $adapter->quoteInto('pack.source_hash = main_table.source_hash AND pack.locale = ?', $locale),
            [
                'pack_translation' => 'translation',
                'pack_conflict_count' => 'conflict_count',
                'pack_alternatives' => 'alternatives',
            ],
        );
        $select->joinLeft(
            ['db_global' => $coreTable],
            $adapter->quoteInto('db_global.store_id = 0 AND db_global.locale = ?', $locale)
                . ' AND db_global.crc_string = main_table.source_crc AND db_global.string = main_table.source_text',
            ['global_translation' => 'translate'],
        );
        $select->joinLeft(
            ['db_store' => $coreTable],
            $adapter->quoteInto('db_store.store_id = ?', $storeId)
                . $adapter->quoteInto(' AND db_store.locale = ?', $locale)
                . ' AND db_store.crc_string = main_table.source_crc AND db_store.string = main_table.source_text',
            ['store_translation' => 'translate'],
        );

        $longTable = $resource->getTableName('translations/long');
        $select->joinLeft(
            ['long_global' => $longTable],
            $adapter->quoteInto('long_global.source_hash = main_table.source_hash AND long_global.locale = ?', $locale)
                . ' AND long_global.store_id = 0',
            [],
        );
        $select->joinLeft(
            ['long_store' => $longTable],
            $adapter->quoteInto('long_store.source_hash = main_table.source_hash AND long_store.locale = ?', $locale)
                . $adapter->quoteInto(' AND long_store.store_id = ?', $storeId),
            [],
        );

        $draftTable = $resource->getTableName('translations/draft');
        $select->joinLeft(
            ['ai_draft' => $draftTable],
            $adapter->quoteInto('ai_draft.source_hash = main_table.source_hash AND ai_draft.locale = ?', $locale)
                . $adapter->quoteInto(' AND ai_draft.store_id = ?', $storeId),
            [
                'ai_draft_id' => 'draft_id',
                'ai_draft_translation' => 'draft_translation',
                'ai_draft_status' => 'status',
                'ai_draft_error' => 'error_message',
                'ai_draft_updated_at' => 'updated_at',
            ],
        );

        $select->columns([
            'effective_translation' => new \Maho\Db\Expr(self::EFFECTIVE_SQL),
            'translation_status' => new \Maho\Db\Expr(
                "CASE WHEN COALESCE(pack.conflict_count, 0) > 0 THEN 'conflict' "
                . 'WHEN ' . self::EFFECTIVE_SQL . " = main_table.source_text THEN 'missing' "
                . "ELSE 'translated' END",
            ),
        ]);

        return $this;
    }

    /**
     * @param array{status?: string, module?: string, area?: string, q?: string} $filters
     */
    public function addTranslationFilters(array $filters): static
    {
        $adapter = $this->getConnection();
        $select = $this->getSelect();

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $select->where(
                '(' . $adapter->quoteInto('main_table.source_text LIKE ?', $like)
                . ' OR ' . $adapter->quoteInto(self::EFFECTIVE_SQL . ' LIKE ?', $like) . ')',
            );
        }

        $module = trim((string) ($filters['module'] ?? ''));
        if ($module !== '') {
            $select->where($adapter->quoteInto("CONCAT(',', main_table.modules, ',') LIKE ?", '%,' . $module . ',%'));
        }

        $area = trim((string) ($filters['area'] ?? ''));
        if ($area !== '') {
            $select->where($adapter->quoteInto("CONCAT(',', main_table.areas, ',') LIKE ?", '%,' . $area . ',%'));
        }

        $status = (string) ($filters['status'] ?? '');
        match ($status) {
            'long' => $select->where('main_table.source_length > 255'),
            'unused' => $select->where('main_table.is_used = 0'),
            'missing' => $select->where(self::EFFECTIVE_SQL . ' = main_table.source_text'),
            'translated' => $select->where(self::EFFECTIVE_SQL . ' <> main_table.source_text'),
            'conflict' => $select->where('COALESCE(pack.conflict_count, 0) > 0'),
            default => $this,
        };

        return $this;
    }
}
