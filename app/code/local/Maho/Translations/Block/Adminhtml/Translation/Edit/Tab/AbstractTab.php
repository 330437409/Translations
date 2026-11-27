<?php

/**
 * Base block for translation edit tabs.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

abstract class Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AbstractTab extends Mage_Adminhtml_Block_Widget_Form implements Mage_Adminhtml_Block_Widget_Tab_Interface
{
    private ?Maho_Translations_Model_Catalog $catalog = null;

    /**
     * @return array<string, mixed>|null
     */
    protected function getSource(): ?array
    {
        $source = Mage::registry('translations_source');
        return is_array($source) ? $source : null;
    }

    protected function getLocale(): string
    {
        return (string) $this->getRequest()->getParam('locale', 'en_US');
    }

    protected function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    protected function getCatalog(): Maho_Translations_Model_Catalog
    {
        return $this->catalog ??= Mage::getModel('translations/catalog');
    }

    public function getDraftTranslation(int $sourceId, string $locale, int $storeId): ?string
    {
        $session = Mage::getSingleton('adminhtml/session');
        $draft = $session->getData('translations_form_draft');
        if (!is_array($draft)
            || (int) ($draft['source_id'] ?? 0) !== $sourceId
            || (string) ($draft['locale'] ?? '') !== $locale
            || (int) ($draft['store'] ?? 0) !== $storeId) {
            return null;
        }

        $session->unsetData('translations_form_draft');
        return isset($draft['translation']) ? (string) $draft['translation'] : null;
    }

    #[\Override]
    public function canShowTab(): bool
    {
        return true;
    }

    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }

    #[\Override]
    public function getTabTitle(): string
    {
        return $this->getTabLabel();
    }

    #[\Override]
    abstract public function getTabLabel(): string;
}
