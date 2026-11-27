<?php

/**
 * Translation context tab.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Context extends Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AbstractTab
{
    #[\Override]
    public function getTabLabel(): string
    {
        return Mage::helper('translations')->__('Context');
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
        $locale = $this->getLocale();
        $sourceText = (string) $source['source_text'];
        $sourceHash = (string) $source['source_hash'];
        $packTranslation = $catalog->getPackTranslation($sourceHash, $locale);
        $globalTranslation = $catalog->getGlobalOverride($sourceText, $locale);
        $packConflicts = $catalog->getPackConflictInfo($sourceHash, $locale);

        $form = new \Maho\Data\Form();
        $fieldset = $form->addFieldset('context_fieldset', ['legend' => $helper->__('Context')]);

        $globalField = $fieldset->addField('global_translation', 'textarea', [
            'name' => 'global_translation',
            'label' => $helper->__('Global override'),
            'value' => $globalTranslation ?? '',
            'readonly' => true,
        ]);
        $globalField->setRows(2);

        $packField = $fieldset->addField('pack_translation', 'textarea', [
            'name' => 'pack_translation',
            'label' => $helper->__('Language pack translation'),
            'value' => $packTranslation ?? '',
            'readonly' => true,
        ]);
        $packField->setRows(2);

        if ($packConflicts['count'] > 0) {
            $conflictField = $fieldset->addField('pack_conflicts', 'textarea', [
                'name' => 'pack_conflicts',
                'label' => $helper->__('Language pack conflicts'),
                'value' => implode("\n", $packConflicts['alternatives']),
                'readonly' => true,
                'note' => $helper->__('%s conflicting language pack translations were found.', $packConflicts['count']),
            ]);
            $conflictField->setRows(4);
        }

        $form->addValues([
            'global_translation' => $globalTranslation ?? '',
            'pack_translation' => $packTranslation ?? '',
            'pack_conflicts' => $packConflicts['count'] > 0 ? implode("\n", $packConflicts['alternatives']) : '',
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
