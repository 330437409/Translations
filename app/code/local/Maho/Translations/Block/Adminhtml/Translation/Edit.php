<?php

/**
 * Translation edit container.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit extends Mage_Adminhtml_Block_Widget_Form_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'translations';
        $this->_controller = 'adminhtml_translation';
        $this->_mode = 'edit';
        $this->_headerText = Mage::helper('translations')->__('Edit Translation');

        parent::__construct();

        $this->_removeButton('add');
        if (Mage::getSingleton('admin/session')->isAllowed('system/translations/save')) {
            $helper = Mage::helper('translations');
            $message = $this->jsQuoteEscape($helper->__('Clear this translation override?'));
            $url = $this->jsQuoteEscape($this->getClearUrl());
            $this->_addButton('clear', [
                'label' => $helper->__('Clear Override'),
                'onclick' => "if (confirm('{$message}')) { var form = document.getElementById('edit_form'); "
                    . "if (form) { form.action = '{$url}'; form.submit(); } }",
                'class' => 'delete',
            ], 0, 5);
        } else {
            $this->_removeButton('save');
        }
    }

    #[\Override]
    public function getHeaderText(): string
    {
        $source = Mage::registry('translations_source');
        if (!$source) {
            return parent::getHeaderText();
        }
        return Mage::helper('translations')->__(
            'Edit translation: %s',
            $this->escapeHtml(Mage::helper('core/string')->truncate((string) $source['source_text'], 80)),
        );
    }

    #[\Override]
    public function getFormActionUrl(): string
    {
        return $this->getUrl('*/*/save', $this->getScopeParams() + ['_nosecret' => true]);
    }

    #[\Override]
    public function getBackUrl(): string
    {
        return $this->getUrl('*/*/index', $this->getScopeParams());
    }

    public function getClearUrl(): string
    {
        return $this->getUrl('*/*/delete', $this->getScopeParams() + ['_nosecret' => true]);
    }

    /**
     * @return array<string, int|string>
     */
    private function getScopeParams(): array
    {
        return [
            'source_id' => (int) $this->getRequest()->getParam('source_id', 0),
            'locale' => (string) $this->getRequest()->getParam('locale', 'en_US'),
            'store' => (int) $this->getRequest()->getParam('store', 0),
        ];
    }
}
