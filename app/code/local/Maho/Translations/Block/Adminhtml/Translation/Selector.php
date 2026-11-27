<?php

/**
 * Translation grid selector.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Selector extends Mage_Adminhtml_Block_Template
{
    private ?Maho_Translations_Model_Catalog $catalog = null;

    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('translations/selector.phtml');
    }

    public function getLocale(): string
    {
        return (string) $this->getRequest()->getParam('locale', 'en_US');
    }

    public function getStoreId(): int
    {
        return (int) $this->getRequest()->getParam('store', 0);
    }

    public function getStatus(): string
    {
        return (string) $this->getRequest()->getParam('status', '');
    }

    public function getModule(): string
    {
        return (string) $this->getRequest()->getParam('module', '');
    }

    public function getAreaFilter(): string
    {
        return (string) $this->getRequest()->getParam('area', '');
    }

    public function getQuery(): string
    {
        return (string) $this->getRequest()->getParam('q', '');
    }

    /**
     * @return array<int, string>
     */
    public function getLocaleOptions(): array
    {
        return $this->getCatalog()->getLocales();
    }

    /**
     * @return array<int, string>
     */
    public function getStoreOptions(): array
    {
        return $this->getCatalog()->getStoreOptions();
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return $this->getCatalog()->getStatusOptions();
    }

    /**
     * @return array<int, string>
     */
    public function getModuleOptions(): array
    {
        return $this->getCatalog()->getModules();
    }

    /**
     * @return array<string, string>
     */
    public function getAreaOptions(): array
    {
        return $this->getCatalog()->getAreaOptions();
    }

    public function getFilterUrl(): string
    {
        return $this->getUrl('*/*/index');
    }

    private function getCatalog(): Maho_Translations_Model_Catalog
    {
        return $this->catalog ??= Mage::getModel('translations/catalog');
    }
}
