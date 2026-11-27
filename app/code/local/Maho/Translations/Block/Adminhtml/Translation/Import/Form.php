<?php

/**
 * Translation CSV import form.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Import_Form extends Mage_Adminhtml_Block_Widget_Form
{
    #[\Override]
    protected function _prepareForm()
    {
        $helper = Mage::helper('translations');
        $catalog = Mage::getModel('translations/catalog');
        $locale = (string) $this->getRequest()->getParam('locale', 'en_US');
        $storeId = (int) $this->getRequest()->getParam('store', 0);

        $locales = [];
        foreach ($catalog->getLocales() as $value) {
            $locales[] = ['value' => $value, 'label' => $value];
        }
        $stores = [];
        foreach ($catalog->getStoreOptions() as $value => $label) {
            $stores[] = ['value' => $value, 'label' => $label];
        }

        $form = new \Maho\Data\Form();
        $form->setAction($this->getUrl('*/*/import', ['_nosecret' => true]));
        $form->setMethod('post');
        $form->setId('edit_form');
        $form->setUseContainer(true);
        $form->setEnctype('multipart/form-data');

        $fieldset = $form->addFieldset('base', ['legend' => $helper->__('CSV file')]);
        $fieldset->addField('file', 'file', [
            'name' => 'file',
            'label' => $helper->__('CSV file'),
            'required' => true,
            'accept' => '.csv,text/csv',
        ]);
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
        $fieldset->addField('note', 'note', [
            'name' => 'note',
            'text' => $helper->__('The file must have two columns: source and translation. The second column may be empty to clear an override.'),
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
