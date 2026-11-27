<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('keeps the grid filters when scanning', function () {
    $request = Mage::app()->getRequest();
    $params = [
        'locale' => 'zh_CN',
        'store' => '1',
        'status' => 'missing',
        'module' => 'Maho_PageCache',
        'area' => 'adminhtml',
        'q' => 'cache',
    ];

    foreach ($params as $name => $value) {
        $request->setParam($name, $value);
    }

    try {
        $block = new Maho_Translations_Block_Adminhtml_Translation();

        expect($block->getScanParams())->toBe([
            'locale' => 'zh_CN',
            'store' => 1,
            'status' => 'missing',
            'module' => 'Maho_PageCache',
            'area' => 'adminhtml',
            'q' => 'cache',
        ]);
    } finally {
        foreach (array_keys($params) as $name) {
            $request->setParam($name, null);
        }
    }
});
