<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function catalogTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function catalogCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(catalogTable('location'));
    $write->delete(catalogTable('pack'));
    $write->delete(catalogTable('source'));
    $write->delete(catalogTable('scan'));
    $write->delete(catalogTable('long'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale = ?' => 'zz_ZZ',
        'string IN (?)' => ['Add to Cart', 'Home page', 'Welcome', 'Unused string', 'Hello %s', 'Acme Shop'],
    ]);
}

function catalogHomePageSource(): array
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow(
        $read->select()->from(catalogTable('source'))->where('source_text = ?', 'Home page'),
    );
    expect($row)->not->toBeFalse();
    return $row;
}

beforeEach(function () {
    catalogCleanup();
    (new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures'))->scan();
});

afterEach(function () {
    catalogCleanup();
});

it('resolves store, global, pack and source translations in order', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    $source = catalogHomePageSource();

    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe('Pagina iniziale');

    $catalog->saveTranslation($source, 'Pagina home', 'zz_ZZ', 0);
    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe('Pagina home');

    $catalog->saveTranslation($source, 'Pagina store', 'zz_ZZ', 1);
    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 1))->toBe('Pagina store')
        ->and($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe('Pagina home');

    $catalog->clearTranslation('Home page', 'zz_ZZ', 1);
    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 1))->toBe('Pagina home');

    $catalog->clearTranslation('Home page', 'zz_ZZ', 0);
    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe('Pagina iniziale');
});

it('clears the translate cache tag when saving', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    $source = catalogHomePageSource();

    Mage::app()->saveCache('cached', 'translation_cache_probe', [Mage_Core_Model_Translate::CACHE_TAG]);
    expect(Mage::app()->loadCache('translation_cache_probe'))->toBe('cached');

    $catalog->saveTranslation($source, 'Pagina home', 'zz_ZZ', 0);

    expect(Mage::app()->loadCache('translation_cache_probe'))->toBeFalse();
});

it('detects whether the index has sources', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    expect($catalog->hasSources())->toBeTrue();

    Mage::getSingleton('core/resource')->getConnection('core_write')->delete(catalogTable('source'));
    expect($catalog->hasSources())->toBeFalse();
});

it('lists locales, modules, stores and scan status', function () {
    $catalog = new Maho_Translations_Model_Catalog();

    expect($catalog->getLocales())->toContain('en_US')
        ->and($catalog->getLocales())->toContain('zz_ZZ')
        ->and($catalog->getModules())->toContain('Acme_Shop')
        ->and(array_key_exists(0, $catalog->getStoreOptions()))->toBeTrue()
        ->and($catalog->getScanStatus()['status'])->toBe('complete');
});
