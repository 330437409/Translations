<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('restores a submitted translation draft for the same source scope', function () {
    $session = Mage::getSingleton('adminhtml/session');
    $session->setData('translations_form_draft', [
        'source_id' => 42,
        'locale' => 'en_US',
        'store' => 0,
        'translation' => 'Draft translation',
    ]);

    $block = new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Translation();

    expect($block->getDraftTranslation(42, 'en_US', 0))->toBe('Draft translation')
        ->and($block->getDraftTranslation(42, 'en_US', 0))->toBeNull();
});

it('does not restore a draft for a different source scope', function () {
    $session = Mage::getSingleton('adminhtml/session');
    $session->setData('translations_form_draft', [
        'source_id' => 42,
        'locale' => 'en_US',
        'store' => 0,
        'translation' => 'Draft translation',
    ]);

    $block = new Maho_Translations_Block_Adminhtml_Translation_Edit_Tab_Translation();

    expect($block->getDraftTranslation(43, 'en_US', 0))->toBeNull();
    $session->unsetData('translations_form_draft');
});
