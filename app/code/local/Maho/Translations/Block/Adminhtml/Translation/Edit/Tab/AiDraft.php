<?php

/**
 * AI draft review tab.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AiDraft extends Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AbstractTab
{
    /** @var array<string, mixed>|false|null */
    private array|false|null $draft = null;

    #[\Override]
    public function getTabLabel(): string
    {
        return Mage::helper('translations')->__('AI Draft');
    }

    /**
     * @return array<string, mixed>|false
     */
    public function getDraft(): array|false
    {
        if ($this->draft !== null) {
            return $this->draft;
        }

        $source = $this->getSource();
        if (!$source) {
            return $this->draft = false;
        }

        return $this->draft = Mage::getResourceModel('translations/draft')->getByScope(
            (string) $source['source_hash'],
            $this->getLocale(),
            $this->getStoreId(),
        );
    }

    public function getDraftStatusLabel(string $status): string
    {
        return Mage::getModel('translations/catalog')->getDraftStatusOptions()[$status]
            ?? $status;
    }

    #[\Override]
    protected function _prepareForm()
    {
        $source = $this->getSource();
        if (!$source) {
            return parent::_prepareForm();
        }

        $helper = Mage::helper('translations');
        $form = new \Maho\Data\Form();
        $fieldset = $form->addFieldset('ai_draft_fieldset', ['legend' => $helper->__('AI Draft')]);
        $draft = $this->getDraft();

        if (!$draft) {
            $fieldset->addField('ai_draft_empty', 'note', [
                'name' => 'ai_draft_empty',
                'text' => $this->escapeHtml($helper->__('No AI draft for this source. Generate one from the translation grid.')),
            ]);
            $this->setForm($form);
            return parent::_prepareForm();
        }

        $draftId = (int) $draft['draft_id'];
        $status = (string) $draft['status'];
        $translation = (string) ($draft['draft_translation'] ?? '');
        $isPending = $status === Maho_Translations_Model_Draft::STATUS_PENDING;
        $canReview = $isPending && Mage::getSingleton('admin/session')->isAllowed('system/translations/save');

        $fieldset->addField('draft_id', 'hidden', ['name' => 'draft_id', 'value' => $draftId]);

        $statusText = $this->getDraftStatusLabel($status);
        if (($draft['platform'] ?? '') !== '' || ($draft['model'] ?? '') !== '') {
            $statusText .= ' (' . trim((string) $draft['platform'] . ' ' . (string) $draft['model']) . ')';
        }
        $fieldset->addField('ai_draft_status', 'note', [
            'name' => 'ai_draft_status',
            'label' => $helper->__('Status'),
            'text' => $this->escapeHtml($statusText),
        ]);

        $sourceField = $fieldset->addField('ai_draft_source', 'textarea', [
            'name' => 'ai_draft_source',
            'label' => $helper->__('Source'),
            'value' => (string) $source['source_text'],
            'readonly' => true,
        ]);
        $sourceField->setRows(3);

        if (($draft['base_translation'] ?? '') !== '') {
            $baseField = $fieldset->addField('ai_draft_base', 'textarea', [
                'name' => 'ai_draft_base',
                'label' => $helper->__('Current translation'),
                'value' => (string) $draft['base_translation'],
                'readonly' => true,
            ]);
            $baseField->setRows(2);
        }

        if (($draft['error_message'] ?? '') !== '') {
            $fieldset->addField('ai_draft_error', 'note', [
                'name' => 'ai_draft_error',
                'label' => $helper->__('Validation error'),
                'text' => $this->escapeHtml((string) $draft['error_message']),
            ]);
        }

        $translationAttributes = [
            'name' => 'ai_draft_translation',
            'label' => $helper->__('AI draft translation'),
            'value' => $translation,
            'note' => $isPending
                ? $helper->__('Review and edit the AI draft before approving it.')
                : null,
        ];
        if (!$isPending) {
            $translationAttributes['readonly'] = true;
        }
        $translationField = $fieldset->addField('ai_draft_translation', 'textarea', $translationAttributes);
        $translationField->setRows(5);

        if ($isPending && $translation === '') {
            $fieldset->addField('ai_draft_pending', 'note', [
                'name' => 'ai_draft_pending',
                'text' => $this->escapeHtml($helper->__('The AI draft is still being generated. Refresh the page to check again.')),
            ]);
        }

        if ($canReview && $translation !== '') {
            $fieldset->addField('ai_draft_actions', 'note', [
                'name' => 'ai_draft_actions',
                'text' => $this->getActionButtonsHtml(),
            ]);
        }

        $form->addValues([
            'draft_id' => $draftId,
            'ai_draft_translation' => $translation,
            'ai_draft_source' => (string) $source['source_text'],
            'ai_draft_base' => (string) ($draft['base_translation'] ?? ''),
        ]);

        $this->setForm($form);
        return parent::_prepareForm();
    }

    private function getActionButtonsHtml(): string
    {
        $helper = Mage::helper('translations');
        $saveUrl = $this->escapeUrl($this->getUrl('*/*/aiDraftSave', ['_nosecret' => true]));
        $approveUrl = $this->escapeUrl($this->getUrl('*/*/aiApprove', ['_nosecret' => true]));
        $rejectUrl = $this->escapeUrl($this->getUrl('*/*/aiReject', ['_nosecret' => true]));
        $approveConfirm = $this->jsQuoteEscape($helper->__('Approve this AI draft?'));
        $rejectConfirm = $this->jsQuoteEscape($helper->__('Reject this AI draft?'));

        return '<button type="submit" class="scalable save" formaction="' . $saveUrl . '">'
            . $this->escapeHtml($helper->__('Save Draft'))
            . '</button> '
            . '<button type="submit" class="scalable" formaction="' . $approveUrl . '" onclick="return confirm(\''
            . $approveConfirm . '\');">'
            . $this->escapeHtml($helper->__('Approve'))
            . '</button> '
            . '<button type="submit" class="scalable delete" formaction="' . $rejectUrl . '" onclick="return confirm(\''
            . $rejectConfirm . '\');">'
            . $this->escapeHtml($helper->__('Reject'))
            . '</button>';
    }
}
