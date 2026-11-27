<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('passes the current locale and store to the export entry point', function () {
    $request = Mage::app()->getRequest();
    $request->setParam('locale', 'it_IT');
    $request->setParam('store', '1');

    $block = new Maho_Translations_Block_Adminhtml_Translation();
    $url = $block->getExportUrl();
    $importUrl = $block->getImportUrl();

    expect($url)->toContain('locale/it_IT')
        ->and($url)->toContain('store/1')
        ->and($importUrl)->toContain('locale/it_IT')
        ->and($importUrl)->toContain('store/1')
        ->and(class_exists('Maho_Translations_Block_Adminhtml_Translation_Export'))->toBeTrue()
        ->and(class_exists('Maho_Translations_Block_Adminhtml_Translation_Export_Form'))->toBeTrue();
});
