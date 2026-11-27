<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function collectionTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function collectionCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(collectionTable('location'));
    $write->delete(collectionTable('pack'));
    $write->delete(collectionTable('source'));
    $write->delete(collectionTable('scan'));
    $write->delete(collectionTable('long'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale = ?' => 'zz_ZZ',
        'string IN (?)' => ['Add to Cart', 'Home page', 'Welcome', 'Unused string', 'Hello %s', 'Acme Shop'],
    ]);
}

/**
 * @return array<string, Maho_Translations_Model_Source>
 */
function collectionItems(string $locale, int $storeId, array $filters = []): array
{
    /** @var Maho_Translations_Model_Resource_Source_Collection $collection */
    $collection = Mage::getResourceModel('translations/source_collection');
    $collection->setTranslationScope($locale, $storeId);
    $collection->addTranslationFilters($filters);

    $items = [];
    foreach ($collection as $item) {
        $items[(string) $item->getData('source_text')] = $item;
    }
    return $items;
}

beforeEach(function () {
    collectionCleanup();
    (new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures'))->scan();
});

afterEach(function () {
    collectionCleanup();
});

it('computes effective translations and status in the collection', function () {
    $items = collectionItems('zz_ZZ', 0);

    expect($items['Home page']->getData('effective_translation'))->toBe('Pagina iniziale')
        ->and($items['Home page']->getData('translation_status'))->toBe('translated')
        ->and($items['Welcome']->getData('effective_translation'))->toBe('Welcome')
        ->and($items['Welcome']->getData('translation_status'))->toBe('missing');
});

it('applies store and global overrides in the collection', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $source = $read->fetchRow($read->select()->from(collectionTable('source'))->where('source_text = ?', 'Home page'));

    $catalog->saveTranslation($source, 'Pagina home', 'zz_ZZ', 0);
    $catalog->saveTranslation($source, 'Pagina store', 'zz_ZZ', 1);

    $global = collectionItems('zz_ZZ', 0);
    $store = collectionItems('zz_ZZ', 1);

    expect($global['Home page']->getData('effective_translation'))->toBe('Pagina home')
        ->and($store['Home page']->getData('effective_translation'))->toBe('Pagina store');
});

it('marks pack conflicts in the collection and filter', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->update(collectionTable('pack'), [
        'conflict_count' => 1,
        'alternatives' => 'Other_Module: Alternativa',
    ], [
        'locale = ?' => 'zz_ZZ',
        'source_hash = ?' => md5('Home page'),
    ]);

    $items = collectionItems('zz_ZZ', 0);
    expect($items['Home page']->getData('translation_status'))->toBe('conflict');

    $conflicts = collectionItems('zz_ZZ', 0, ['status' => 'conflict']);
    expect(array_keys($conflicts))->toBe(['Home page']);
});

it('filters by status, module, area and search', function () {
    $missing = collectionItems('zz_ZZ', 0, ['status' => 'missing']);
    expect(array_keys($missing))->toContain('Welcome')
        ->and(array_keys($missing))->not->toContain('Home page');

    $frontend = collectionItems('zz_ZZ', 0, ['area' => 'frontend']);
    expect(array_keys($frontend))->toContain('Welcome');

    $search = collectionItems('zz_ZZ', 0, ['q' => 'Welcome']);
    expect(array_keys($search))->toBe(['Welcome']);

    $module = collectionItems('zz_ZZ', 0, ['module' => 'Acme_Shop']);
    expect(array_keys($module))->toContain('Home page');
});
