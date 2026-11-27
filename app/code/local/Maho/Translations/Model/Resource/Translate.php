<?php

/**
 * Merges long translations into the core translation resource.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Resource_Translate extends Mage_Core_Model_Resource_Translate
{
    /**
     * @return array<string, string>
     */
    #[\Override]
    public function getTranslationArray($storeId = null, $locale = null): array
    {
        $translations = parent::getTranslationArray($storeId, $locale);
        return array_merge($translations, $this->getLongTranslationArray($storeId, $locale));
    }

    /**
     * @param array<array-key, mixed> $strings
     * @return array<string, string>
     */
    #[\Override]
    public function getTranslationArrayByStrings(array $strings, $storeId = null): array
    {
        $translations = parent::getTranslationArrayByStrings($strings, $storeId);
        if ($strings === []) {
            return $translations;
        }

        return array_merge($translations, $this->getLongTranslationArrayByStrings($strings, $storeId));
    }

    /**
     * @return array<string, string>
     */
    private function getLongTranslationArray(?int $storeId, ?string $locale): array
    {
        if (!Mage::isInstalled()) {
            return [];
        }

        $storeId ??= Mage::app()->getStore()->getId();
        $locale ??= Mage::app()->getLocale()->getLocaleCode();
        $adapter = $this->_getReadAdapter();
        if (!$adapter) {
            return [];
        }

        $select = $adapter->select()
            ->from($this->getLongTable(), ['source_text', 'translation'])
            ->where('store_id IN (0, :store_id)')
            ->where('locale = :locale')
            ->order('store_id');

        return $adapter->fetchPairs($select, [':store_id' => $storeId, ':locale' => $locale]);
    }

    /**
     * @param array<int, string> $strings
     * @return array<string, string>
     */
    private function getLongTranslationArrayByStrings(array $strings, ?int $storeId): array
    {
        if (!Mage::isInstalled()) {
            return [];
        }

        $storeId ??= Mage::app()->getStore()->getId();
        $adapter = $this->_getReadAdapter();
        if (!$adapter) {
            return [];
        }

        $select = $adapter->select()
            ->from($this->getLongTable(), ['source_text', 'translation'])
            ->where('source_text IN (?)', $strings)
            ->where('store_id IN (0, :store_id)')
            ->order('store_id');

        return $adapter->fetchPairs($select, [':store_id' => $storeId]);
    }

    private function getLongTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('translations/long');
    }
}
