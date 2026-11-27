<?php

/**
 * Translation tab.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Translation extends Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AbstractTab
{
    #[\Override]
    public function getTabLabel(): string
    {
        return Mage::helper('translations')->__('Translation');
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
        $storeId = $this->getStoreId();
        $sourceId = (int) $source['source_id'];
        $sourceText = (string) $source['source_text'];

        $translation = $storeId > 0
            ? $catalog->getStoreOverride($sourceText, $locale, $storeId)
            : $catalog->getGlobalOverride($sourceText, $locale);
        $draft = $this->getDraftTranslation($sourceId, $locale, $storeId);
        if ($draft !== null) {
            $translation = $draft;
        }

        $validator = new Maho_Translations_Model_Validator_Placeholder();
        $placeholders = array_values(array_unique(array_merge(
            $validator->extractPrintf($sourceText),
            $validator->extractTemplate($sourceText),
            array_map(static fn(string $tag): string => '<' . $tag . '>', $validator->extractHtml($sourceText)),
        )));

        $form = new \Maho\Data\Form();
        $fieldset = $form->addFieldset('translation_fieldset', ['legend' => $helper->__('Translation')]);
        $fieldset->addField('source_id', 'hidden', ['name' => 'source_id', 'value' => $sourceId]);
        $fieldset->addField('locale', 'hidden', ['name' => 'locale', 'value' => $locale]);
        $fieldset->addField('store', 'hidden', ['name' => 'store', 'value' => $storeId]);

        $sourceField = $fieldset->addField('source_text', 'textarea', [
            'name' => 'source_text',
            'label' => $helper->__('Source'),
            'value' => $sourceText,
            'readonly' => true,
        ]);
        $sourceField->setRows(3);

        $translationField = $fieldset->addField('translation', 'textarea', [
            'name' => 'translation',
            'label' => $helper->__('Translation'),
            'value' => $translation ?? '',
            'note' => $storeId > 0
                ? $helper->__('Leave empty to inherit the global or language pack translation.')
                : $helper->__('Leave empty to inherit the language pack translation.'),
        ]);
        $translationField->setRows(5);

        $effectiveField = $fieldset->addField('effective_translation', 'textarea', [
            'name' => 'effective_translation',
            'label' => $helper->__('Current effective translation'),
            'value' => $catalog->getEffectiveTranslation($source, $locale, $storeId),
            'readonly' => true,
        ]);
        $effectiveField->setRows(3);

        $fieldset->addField('placeholders', 'note', [
            'name' => 'placeholders',
            'label' => $helper->__('Placeholders'),
            'text' => $placeholders === [] ? $helper->__('None') : $this->escapeHtml(implode(' ', $placeholders)),
        ]);

        $form->addValues([
            'source_id' => $sourceId,
            'locale' => $locale,
            'store' => $storeId,
            'source_text' => $sourceText,
            'translation' => $translation ?? '',
            'effective_translation' => $catalog->getEffectiveTranslation($source, $locale, $storeId),
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }
}
