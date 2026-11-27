<?php

/**
 * Translation grid.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Block_Adminhtml_Translation_Grid extends Mage_Adminhtml_Block_Widget_Grid
{
    public function __construct()
    {
        parent::__construct();
        $this->setId('translationsGrid');
        $this->setDefaultSort('source_text');
        $this->setDefaultDir('ASC');
        $this->setSaveParametersInSession(false);
        $this->setFilterVisibility(false);
        $this->setPagerVisibility(true);
        $this->setUseAjax(false);
    }

    #[\Override]
    protected function _prepareCollection()
    {
        /** @var Maho_Translations_Model_Resource_Source_Collection $collection */
        $collection = Mage::getResourceModel('translations/source_collection');
        $collection->setTranslationScope(
            (string) $this->getRequest()->getParam('locale', 'en_US'),
            (int) $this->getRequest()->getParam('store', 0),
        );
        $collection->addTranslationFilters([
            'status' => $this->getRequest()->getParam('status'),
            'module' => $this->getRequest()->getParam('module'),
            'area' => $this->getRequest()->getParam('area'),
            'q' => $this->getRequest()->getParam('q'),
        ]);

        $this->setCollection($collection);
        return parent::_prepareCollection();
    }

    #[\Override]
    protected function _prepareColumns()
    {
        $helper = Mage::helper('translations');

        $this->addColumn('source_text', [
            'header' => $helper->__('Source'),
            'index' => 'source_text',
            'renderer' => 'adminhtml/widget_grid_column_renderer_longtext',
            'truncate' => 120,
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('effective_translation', [
            'header' => $helper->__('Translation'),
            'index' => 'effective_translation',
            'renderer' => 'adminhtml/widget_grid_column_renderer_longtext',
            'truncate' => 120,
            'filter' => false,
            'sortable' => true,
        ]);
        $helperCatalog = Mage::getModel('translations/catalog');
        $this->addColumn('ai_draft_status', [
            'header' => $helper->__('AI Draft'),
            'index' => 'ai_draft_status',
            'width' => '120px',
            'type' => 'options',
            'options' => $helperCatalog->getDraftStatusOptions(),
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('ai_draft_translation', [
            'header' => $helper->__('AI Draft Text'),
            'index' => 'ai_draft_translation',
            'renderer' => 'adminhtml/widget_grid_column_renderer_longtext',
            'truncate' => 80,
            'filter' => false,
            'sortable' => false,
        ]);
        $this->addColumn('translation_status', [
            'header' => $helper->__('Status'),
            'index' => 'translation_status',
            'width' => '100px',
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('is_used', [
            'header' => $helper->__('Used'),
            'index' => 'is_used',
            'width' => '70px',
            'type' => 'options',
            'options' => [0 => $helper->__('No'), 1 => $helper->__('Yes')],
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('modules', [
            'header' => $helper->__('Modules'),
            'index' => 'modules',
            'width' => '220px',
            'filter' => false,
            'sortable' => false,
        ]);
        $this->addColumn('areas', [
            'header' => $helper->__('Areas'),
            'index' => 'areas',
            'width' => '160px',
            'filter' => false,
            'sortable' => false,
        ]);
        $this->addColumn('pack_conflict_count', [
            'header' => $helper->__('Conflicts'),
            'index' => 'pack_conflict_count',
            'type' => 'number',
            'width' => '80px',
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('source_length', [
            'header' => $helper->__('Length'),
            'index' => 'source_length',
            'type' => 'number',
            'width' => '70px',
            'filter' => false,
            'sortable' => true,
        ]);
        $this->addColumn('action', [
            'header' => $helper->__('Action'),
            'width' => '150px',
            'type' => 'action',
            'getter' => 'getSourceId',
            'actions' => [
                [
                    'caption' => $helper->__('Edit'),
                    'url' => [
                        'base' => '*/*/edit',
                        'params' => [
                            'locale' => $this->getRequest()->getParam('locale', 'en_US'),
                            'store' => $this->getRequest()->getParam('store', 0),
                        ],
                    ],
                    'field' => 'source_id',
                ],
                [
                    'caption' => $helper->__('Review AI Draft'),
                    'url' => [
                        'base' => '*/*/edit',
                        'params' => [
                            'locale' => $this->getRequest()->getParam('locale', 'en_US'),
                            'store' => $this->getRequest()->getParam('store', 0),
                            'active_tab' => 'ai_draft',
                        ],
                    ],
                    'field' => 'source_id',
                ],
            ],
            'filter' => false,
            'sortable' => false,
        ]);

        return parent::_prepareColumns();
    }

    #[\Override]
    protected function _prepareMassaction()
    {
        $this->setMassactionIdField('source_id');
        $this->setMassactionIdFilter('main_table.source_id');
        $this->getMassactionBlock()->setFormFieldName('source_ids');
        $this->getMassactionBlock()->setUseSelectAll(false);
        $session = Mage::getSingleton('admin/session');
        $helper = Mage::helper('translations');

        if ($session->isAllowed('system/translations/ai')) {
            $this->getMassactionBlock()->addItem('ai_generate', [
                'label' => $helper->__('Generate AI Drafts'),
                'url' => $this->getUrl('*/*/aiGenerate', ['_current' => true, 'mode' => 'selected']),
            ]);
        }
        if ($session->isAllowed('system/translations/save')) {
            $this->getMassactionBlock()->addItem('ai_approve', [
                'label' => $helper->__('Approve AI Drafts'),
                'url' => $this->getUrl('*/*/aiApprove', ['_current' => true]),
                'confirm' => $helper->__('Approve the selected AI drafts?'),
            ]);
            $this->getMassactionBlock()->addItem('ai_reject', [
                'label' => $helper->__('Reject AI Drafts'),
                'url' => $this->getUrl('*/*/aiReject', ['_current' => true]),
                'confirm' => $helper->__('Reject the selected AI drafts?'),
            ]);
        }

        return parent::_prepareMassaction();
    }

    #[\Override]
    public function getRowUrl(\Maho\DataObject $row): string
    {
        return $this->getUrl('*/*/edit', [
            'source_id' => $row->getSourceId(),
            'locale' => $this->getRequest()->getParam('locale', 'en_US'),
            'store' => $this->getRequest()->getParam('store', 0),
        ]);
    }

    #[\Override]
    public function getGridUrl(): string
    {
        return $this->getUrl('*/*/index', ['_current' => true]);
    }
}
