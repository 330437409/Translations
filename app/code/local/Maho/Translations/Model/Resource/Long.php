<?php

/**
 * Long translation resource.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Resource_Long extends Mage_Core_Model_Resource_Db_Abstract
{
    #[\Override]
    protected function _construct(): void
    {
        $this->_init('translations/long', 'long_id');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(array $data): int
    {
        $adapter = $this->_getWriteAdapter();
        $adapter->insertOnDuplicate(
            $this->getMainTable(),
            $data,
            ['source_text', 'translation', 'updated_at'],
        );

        return (int) $adapter->fetchOne(
            $adapter->select()
                ->from($this->getMainTable(), ['long_id'])
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

    public function deleteByScope(string $sourceHash, string $locale, int $storeId): int
    {
        return $this->_getWriteAdapter()->delete(
            $this->getMainTable(),
            [
                'source_hash = ?' => $sourceHash,
                'locale = ?' => $locale,
                'store_id = ?' => $storeId,
            ],
        );
    }
}
