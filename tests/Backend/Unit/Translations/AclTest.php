<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('declares the translation menu and acl resources', function () {
    $file = dirname(__DIR__, 2) . '/../../app/code/local/Maho/Translations/etc/adminhtml.xml';
    $xml = simplexml_load_file($file);

    expect($xml)->not->toBeFalse();

    $menu = $xml->menu->system->children->translations;
    expect((string) $menu->title)->toBe('Translations')
        ->and((string) $menu->action)->toBe('adminhtml/translations/index');

    $acl = $xml->acl->resources->admin->children->system->children->translations;
    expect((string) $acl->title)->toBe('Translations')
        ->and(isset($acl->children->save))->toBeTrue()
        ->and(isset($acl->children->ai))->toBeTrue()
        ->and(isset($acl->children->scan))->toBeTrue()
        ->and(isset($acl->children->import))->toBeTrue()
        ->and(isset($acl->children->export))->toBeTrue();
});

it('maps controller actions to acl resources', function () {
    $reflection = new ReflectionClass(Maho_Translations_Adminhtml_TranslationsController::class);
    $resources = $reflection->getConstant('ACTION_RESOURCES');

    expect($reflection->getConstant('ADMIN_RESOURCE'))->toBe('system/translations')
        ->and($resources['index'])->toBe('system/translations')
        ->and($resources['edit'])->toBe('system/translations')
        ->and($resources['save'])->toBe('system/translations/save')
        ->and($resources['delete'])->toBe('system/translations/save')
        ->and($resources['aigenerate'])->toBe('system/translations/ai')
        ->and($resources['aidraftsave'])->toBe('system/translations/save')
        ->and($resources['aiapprove'])->toBe('system/translations/save')
        ->and($resources['aireject'])->toBe('system/translations/save')
        ->and($resources['scan'])->toBe('system/translations/scan')
        ->and($resources['import'])->toBe('system/translations/import')
        ->and($resources['export'])->toBe('system/translations/export');
});
