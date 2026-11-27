<?php

/**
 * AI translation draft resource.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Resource_Draft extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('translations/draft', 'draft_id');
    }

    /**
     * Create or replace the current draft for one source and scope.
     *
     * @param array<string, mixed> $data
     */
    public function upsertForScope(array $data): int
    {
        $adapter = $this->_getWriteAdapter();
        $adapter->insertOnDuplicate(
            $this->getMainTable(),
            $data,
            [
                'task_id',
                'source_text',
                'base_translation',
                'draft_translation',
                'status',
                'platform',
                'model',
                'error_message',
                'requested_by',
                'reviewed_by',
                'reviewed_at',
                'updated_at',
            ],
        );

        return (int) $adapter->fetchOne(
            $adapter->select()
                ->from($this->getMainTable(), ['draft_id'])
                ->where('source_hash = ?', $data['source_hash'])
                ->where('locale = ?', $data['locale'])
                ->where('store_id = ?', $data['store_id']),
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getByScope(string $sourceHash, string $locale, int $storeId): array|false
    {
        $adapter = $this->_getReadAdapter();
        return $adapter->fetchRow(
            $adapter->select()
                ->from($this->getMainTable())
                ->where('source_hash = ?', $sourceHash)
                ->where('locale = ?', $locale)
                ->where('store_id = ?', $storeId),
        );
    }

    /**
     * Update a draft only while it is still pending.
     *
     * @param array<string, mixed> $data
     */
    public function updatePending(int $draftId, array $data): int
    {
        return $this->_getWriteAdapter()->update(
            $this->getMainTable(),
            $data,
            [
                'draft_id = ?' => $draftId,
                'status = ?' => Maho_Translations_Model_Draft::STATUS_PENDING,
            ],
        );
    }

    /**
     * Update a draft only while it has no generated text yet.
     *
     * @param array<string, mixed> $data
     */
    public function updateEmptyPending(int $draftId, array $data): int
    {
        $adapter = $this->_getWriteAdapter();
        return $adapter->update(
            $this->getMainTable(),
            $data,
            [
                'draft_id = ?' => $draftId,
                'status = ?' => Maho_Translations_Model_Draft::STATUS_PENDING,
                'draft_translation IS NULL',
            ],
        );
    }

    public function setTaskId(int $draftId, int $taskId): int
    {
        return $this->updatePending($draftId, ['task_id' => $taskId]);
    }

    /**
     * @param array<int, int> $sourceIds
     * @return array<int, int>
     */
    public function getDraftIdsBySourceIds(array $sourceIds, string $locale, int $storeId): array
    {
        $adapter = $this->_getReadAdapter();
        $sourceTable = Mage::getSingleton('core/resource')->getTableName('translations/source');
        $ids = $adapter->fetchCol(
            $adapter->select()
                ->from(['draft' => $this->getMainTable()], ['draft_id'])
                ->join(['source' => $sourceTable], 'source.source_hash = draft.source_hash', [])
                ->where('source.source_id IN (?)', $sourceIds)
                ->where('draft.locale = ?', $locale)
                ->where('draft.store_id = ?', $storeId),
        );

        return array_map(intval(...), $ids);
    }
}
