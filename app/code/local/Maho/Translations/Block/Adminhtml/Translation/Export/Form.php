<?php

/**
 * Translation CSV export form.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Export_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[\Override]
    protected function _prepareForm()
    {
        $helper = Mage::helper('translations');
        $catalog = Mage::getModel('translations/catalog');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);
        $scope = (string) $this->getRequest()->getParam('scope', 'effective');
        if (!in_array($scope, ['effective', 'missing', 'overrides'], true)) {
            $scope = 'effective';
        }

        $locales = [];
        foreach ($catalog->getLocales() as $value) {
            $locales[] = ['value' => $value, 'label' => $value];
        }
        $stores = [];
        foreach ($catalog->getStoreOptions() as $value => $label) {
            $stores[] = ['value' => $value, 'label' => $label];
        }

        $form = new \Maho\Data\Form();
        $form->setAction($this->getUrl('*/*/export'));
        $form->setMethod('get');
        $form->setId('edit_form');
        $form->setUseContainer(true);

        $fieldset = $form->addFieldset('base', ['legend' => $helper->__('Export Translations')]);
        $fieldset->addField('download', 'hidden', ['name' => 'download', 'value' => 1]);
        $fieldset->addField('locale', 'select', [
            'name' => 'locale',
            'label' => $helper->__('Locale'),
            'values' => $locales,
            'value' => $locale,
        ]);
        $fieldset->addField('store', 'select', [
            'name' => 'store',
            'label' => $helper->__('Store'),
            'values' => $stores,
            'value' => $storeId,
        ]);
        $fieldset->addField('scope', 'select', [
            'name' => 'scope',
            'label' => $helper->__('Scope'),
            'values' => [
                ['value' => 'effective', 'label' => $helper->__('Effective translations')],
                ['value' => 'missing', 'label' => $helper->__('Missing translations')],
                ['value' => 'overrides', 'label' => $helper->__('Database overrides')],
            ],
            'value' => $scope,
        ]);
        $fieldset->addField('note', 'note', [
            'name' => 'note',
            'text' => $helper->__('The export contains two columns: source and translation. Missing translations have an empty second column.'),
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
