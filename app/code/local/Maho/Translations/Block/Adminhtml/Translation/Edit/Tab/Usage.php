<?php

/**
 * Translation usage tab.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Usage extends Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AbstractTab
{
    #[\Override]
    public function getTabLabel(): string
    {
        return Mage::helper('translations')->__('Usage');
    }

    #[\Override]
    protected function _prepareForm()
    {
        $source = $this->getSource();
        if (!$source) {
            return parent::_prepareForm();
        }

        $helper = Mage::helper('translations');
        $catalog = $this->getCatalog();
        $sourceId = (int) $source['source_id'];

        $locations = [];
        foreach ($catalog->getLocations($sourceId) as $location) {
            $locations[] = sprintf(
                '%s %s %s%s',
                $location['module'] !== '' ? $location['module'] : '-',
                $location['area'] !== '' ? $location['area'] : '-',
                $location['file'],
                $location['line'] !== null ? ':' . $location['line'] : '',
            );
        }

        $form = new \Maho\Data\Form();
        $fieldset = $form->addFieldset('usage_fieldset', ['legend' => $helper->__('Usage')]);
        $locationsField = $fieldset->addField('locations', 'textarea', [
            'name' => 'locations',
            'label' => $helper->__('Used in'),
            'value' => implode("\n", $locations),
            'readonly' => true,
        ]);
        $locationsField->setRows(8);

        $form->addValues(['locations' => implode("\n", $locations)]);
        $this->setForm($form);
        return parent::_prepareForm();
    }
}
