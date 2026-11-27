<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('registers the translation, context, usage and AI draft tabs', function () {
    Mage::getDesign()->applyAdminDesign();

    $layout = Mage::getSingleton('core/layout');
    $layout->getUpdate()
        ->addHandle('default')
        ->addHandle('adminhtml')
        ->addHandle('adminhtml_translations_edit')
        ->load();
    $layout->generateXml();
    $layout->generateBlocks();

    $tabs = $layout->getBlock('translations.edit.tabs');
    expect($tabs)->toBeInstanceOf(Maho_Translations_Block_Adminhtml_Translation_Edit_Tabs::class);

    $tabs->toHtml();

    expect($tabs->getDestElementId())->toBe('edit_form')
        ->and($tabs->getTab('translation'))->toBeInstanceOf(
            Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Translation::class,
        )
        ->and($tabs->getTab('context'))->toBeInstanceOf(
            Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Context::class,
        )
        ->and($tabs->getTab('usage'))->toBeInstanceOf(
            Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Usage::class,
        )
        ->and($tabs->getTab('ai_draft'))->toBeInstanceOf(
            Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AiDraft::class,
        );
});

it('uses the tab labels in the left navigation', function () {
    expect(new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Translation()->getTabLabel())->toBe('Translation')
        ->and(new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Context()->getTabLabel())->toBe('Context')
        ->and(new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Usage()->getTabLabel())->toBe('Usage')
        ->and(new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_AiDraft()->getTabLabel())->toBe('AI Draft');
});
