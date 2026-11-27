<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('escapes the source string in the edit page header', function () {
    Mage::register('translations_source', [
        'source_text' => '<img src=x onerror=alert(1)>',
    ]);

    try {
        $block = new Maho_Translations_Block_Adminhtml_Translation_Edit();
        $header = $block->getHeaderText();
        $html = $block->getHeaderHtml();

        expect($header)->toContain('&lt;img')
            ->and($header)->not->toContain('<img src=x')
            ->and($html)->not->toContain('<img src=x onerror');
    } finally {
        Mage::unregister('translations_source');
    }
});
