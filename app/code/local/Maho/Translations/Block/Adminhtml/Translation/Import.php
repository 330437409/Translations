<?php

/**
 * Translation CSV import container.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Import extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'translations';
        $this->_controller = 'adminhtml_translation';
        $this->_mode = 'import';
        $this->_headerText = Mage::helper('translations')->__('Import Translations');

        parent::__construct();

        $this->_removeButton('add');
        $this->_removeButton('reset');
        $this->_updateButton('save', 'label', Mage::helper('translations')->__('Import'));
    }

    #[\Override]
    public function getFormActionUrl(): string
    {
        return $this->getUrl('*/*/import', ['_nosecret' => true]);
    }

    #[\Override]
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index');
    }
}
