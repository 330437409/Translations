<?php

/**
 * Translation grid container.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation extends Mage_Adminhtml_Block_Widget_Grid_Container
{
    public function __construct()
    {
        $this->_blockGroup = 'translations';
        $this->_controller = 'adminhtml_translation';
        $this->_headerText = Mage::helper('translations')->__('Translations');

        parent::__construct();

        $this->_removeButton('add');

        $session = Mage::getSingleton('admin/session');
        if ($session->isAllowed('system/translations/scan')) {
            $this->_addButton('scan', [
                'label' => Mage::helper('translations')->__('Scan'),
                'onclick' => "document.getElementById('translations-scan-form').submit();",
                'class' => 'scan',
            ]);
        }
        if ($session->isAllowed('system/translations/import')) {
            $this->_addButton('import', [
                'label' => Mage::helper('translations')->__('Import'),
                'onclick' => 'setLocation(\'' . $this->getImportUrl() . '\')',
                'class' => 'import',
            ]);
        }
        if ($session->isAllowed('system/translations/export')) {
            $this->_addButton('export', [
                'label' => Mage::helper('translations')->__('Export'),
                'onclick' => 'setLocation(\'' . $this->getExportUrl() . '\')',
                'class' => 'export',
            ]);
        }
        if ($session->isAllowed('system/translations/ai')) {
            $missing = $this->getMissingDraftCount();
            if ($missing > 0) {
                $confirm = Mage::helper('translations')->__(
                    'Generate AI drafts for %s missing translations?',
                    $missing,
                );
                $this->_addButton('ai_generate_missing', [
                    'label' => Mage::helper('translations')->__('Generate missing (%s)', $missing),
                    'onclick' => 'if (confirm(\'' . $this->jsQuoteEscape($confirm)
                        . '\')) { document.getElementById(\'translations-ai-missing-form\').submit(); }',
                    'class' => 'ai',
                ]);
            }
        }

        $this->setTemplate('translations/container.phtml');
    }

    #[\Override]
    protected function _prepareLayout()
    {
        $this->setChild(
            'selector',
            $this->getLayout()->createBlock('translations/adminhtml_translation_selector'),
        );
        return parent::_prepareLayout();
    }

    public function getSelectorHtml(): string
    {
        return $this->getChildHtml('selector');
    }

    public function getScanUrl(): string
    {
        return $this->getUrl('*/*/scan', ['_nosecret' => true]);
    }

    /**
     * @return array<string, int|string>
     */
    public function getScanParams(): array
    {
        $params = [
            'locale' => (string) $this->getRequest()->getParam('locale', 'en_US'),
            'store' => (int) $this->getRequest()->getParam('store', 0),
        ];
        foreach (['status', 'module', 'area', 'q'] as $name) {
            $value = (string) $this->getRequest()->getParam($name, '');
            if ($value !== '') {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getScanStatus(): array|false
    {
        return Mage::getModel('translations/catalog')->getScanStatus();
    }

    public function hasScanData(): bool
    {
        return Mage::getModel('translations/catalog')->hasSources();
    }

    public function getAiGenerateUrl(): string
    {
        return $this->getUrl('*/*/aiGenerate', ['_nosecret' => true]);
    }

    public function isAiAllowed(): bool
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/translations/ai');
    }

    /**
     * @return array{module: string, area: string, q: string}
     */
    public function getAiFilterParams(): array
    {
        return [
            'module' => (string) $this->getRequest()->getParam('module', ''),
            'area' => (string) $this->getRequest()->getParam('area', ''),
            'q' => (string) $this->getRequest()->getParam('q', ''),
        ];
    }

    public function getMissingDraftCount(): int
    {
        try {
            return Mage::getService('translations/aiDraft')->countMissing(
                (string) $this->getRequest()->getParam('locale', 'en_US'),
                (int) $this->getRequest()->getParam('store', 0),
                $this->getAiFilterParams(),
            );
        } catch (Throwable $e) {
            Mage::logException($e);
            return 0;
        }
    }

    public function getImportUrl(): string
    {
        return $this->getUrl('*/*/import', [
            'locale' => (string) $this->getRequest()->getParam('locale', 'en_US'),
            'store' => (int) $this->getRequest()->getParam('store', 0),
        ]);
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('*/*/export', [
            'locale' => (string) $this->getRequest()->getParam('locale', 'en_US'),
            'store' => (int) $this->getRequest()->getParam('store', 0),
        ]);
    }
}
