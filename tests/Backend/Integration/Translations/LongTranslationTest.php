<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function longTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function longCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(longTable('long'));
    $write->delete(longTable('location'));
    $write->delete(longTable('pack'));
    $write->delete(longTable('source'));
    $write->delete(longTable('scan'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale = ?' => 'zz_ZZ',
    ]);
}

function longSource(string $text): array
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow(
        $read->select()->from(longTable('source'))->where('source_text = ?', $text),
    );
    expect($row)->not->toBeFalse();
    return $row;
}

function longInsertSource(string $text): array
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(longTable('source'), [
        'source_hash' => md5($text),
        'source_crc' => crc32($text),
        'source_text' => $text,
        'source_length' => mb_strlen($text, 'UTF-8'),
        'is_used' => 1,
        'is_supported' => 0,
        'modules' => 'Acme_Shop',
        'areas' => 'frontend',
        'locations_count' => 0,
    ]);
    return longSource($text);
}

function longStored(string $sourceText, int $storeId = 0): mixed
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return $read->fetchOne(
        $read->select()
            ->from(longTable('long'), ['translation'])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', $storeId)
            ->where('source_hash = ?', md5($sourceText)),
    );
}

function longCoreCount(string $sourceText): int
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return (int) $read->fetchOne(
        $read->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('core/translate'), ['c' => new Maho\Db\Expr('COUNT(*)')])
            ->where('locale = ?', 'zz_ZZ')
            ->where('string = ?', $sourceText),
    );
}

beforeEach(function () {
    longCleanup();
    (new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures'))->scan();
});

afterEach(function () {
    longCleanup();
});

it('stores a long translation in the long table and returns it as effective', function () {
    $source = longSource('Home page');
    $translation = str_repeat('Long translation ', 20);
    $catalog = new Maho_Translations_Model_Catalog();

    $catalog->saveTranslation($source, $translation, 'zz_ZZ', 0);

    expect(longStored('Home page'))->toBe($translation)
        ->and(longCoreCount('Home page'))->toBe(0)
        ->and($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe($translation);
});

it('stores a long source translation in the long table', function () {
    $text = str_repeat('Long source string ', 20);
    $source = longInsertSource($text);
    $catalog = new Maho_Translations_Model_Catalog();

    $catalog->saveTranslation($source, 'Traduzione lunga', 'zz_ZZ', 0);

    expect(longStored($text))->toBe('Traduzione lunga')
        ->and(longCoreCount($text))->toBe(0)
        ->and($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe('Traduzione lunga');

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $effective = $read->fetchOne(
        $read->select()
            ->from(longTable('long'), ['translation'])
            ->where('source_hash = ?', md5($text)),
    );
    expect($effective)->toBe('Traduzione lunga');
});

it('clears long and core records when saving the other storage type or clearing', function () {
    $source = longSource('Home page');
    $catalog = new Maho_Translations_Model_Catalog();

    $catalog->saveTranslation($source, str_repeat('x', 300), 'zz_ZZ', 0);
    expect(longStored('Home page'))->not->toBeFalse();

    $catalog->saveTranslation($source, 'Pagina breve', 'zz_ZZ', 0);
    expect(longStored('Home page'))->toBeFalse()
        ->and(longCoreCount('Home page'))->toBe(1);

    $catalog->saveTranslation($source, str_repeat('y', 300), 'zz_ZZ', 0);
    expect(longStored('Home page'))->not->toBeFalse()
        ->and(longCoreCount('Home page'))->toBe(0);

    $catalog->clearTranslation('Home page', 'zz_ZZ', 0);
    expect(longStored('Home page'))->toBeFalse()
        ->and(longCoreCount('Home page'))->toBe(0);
});

it('prefers store long over global long', function () {
    $source = longSource('Home page');
    $catalog = new Maho_Translations_Model_Catalog();

    $catalog->saveTranslation($source, str_repeat('Global ', 40), 'zz_ZZ', 0);
    $catalog->saveTranslation($source, str_repeat('Store ', 40), 'zz_ZZ', 1);

    expect($catalog->getEffectiveTranslation($source, 'zz_ZZ', 0))->toBe(str_repeat('Global ', 40))
        ->and($catalog->getEffectiveTranslation($source, 'zz_ZZ', 1))->toBe(str_repeat('Store ', 40));
});

it('merges long translations into the core translation resource', function () {
    $source = longSource('Home page');
    $translation = str_repeat('Resource ', 40);
    $catalog = new Maho_Translations_Model_Catalog();
    $catalog->saveTranslation($source, $translation, 'zz_ZZ', 0);
    $catalog->saveTranslation($source, $translation . ' store', 'zz_ZZ', 1);

    $resource = Mage::getResourceSingleton('core/translate');
    $global = $resource->getTranslationArray(0, 'zz_ZZ');
    $store = $resource->getTranslationArray(1, 'zz_ZZ');
    $byStrings = $resource->getTranslationArrayByStrings(['Home page'], 0);

    expect($resource)->toBeInstanceOf(Maho_Translations_Model_Resource_Translate::class)
        ->and($global['Home page'])->toBe($translation)
        ->and($store['Home page'])->toBe($translation . ' store')
        ->and($byStrings['Home page'])->toBe($translation);
});

it('shows long sources with normal status and the Long filter', function () {
    $text = str_repeat('Long source string ', 20);
    $source = longInsertSource($text);
    Mage::getModel('translations/catalog')->saveTranslation($source, 'Traduzione lunga', 'zz_ZZ', 0);

    $collection = Mage::getResourceModel('translations/source_collection');
    $collection->setTranslationScope('zz_ZZ', 0);
    $items = [];
    foreach ($collection as $item) {
        $items[(string) $item->getData('source_text')] = $item;
    }

    $longCollection = Mage::getResourceModel('translations/source_collection');
    $longCollection->setTranslationScope('zz_ZZ', 0);
    $longCollection->addTranslationFilters(['status' => 'long']);
    $longItems = [];
    foreach ($longCollection as $item) {
        $longItems[] = (string) $item->getData('source_text');
    }

    expect($items[$text]->getData('effective_translation'))->toBe('Traduzione lunga')
        ->and($items[$text]->getData('translation_status'))->toBe('translated')
        ->and($longItems)->toContain($text)
        ->and($longItems)->not->toContain('Home page');
});

it('keeps short translations in core_translate and includes them in the resource', function () {
    $source = longSource('Home page');
    $catalog = new Maho_Translations_Model_Catalog();
    $catalog->saveTranslation($source, 'Pagina breve', 'zz_ZZ', 0);

    $resource = Mage::getResourceSingleton('core/translate');
    $translations = $resource->getTranslationArray(0, 'zz_ZZ');

    expect(longStored('Home page'))->toBeFalse()
        ->and(longCoreCount('Home page'))->toBe(1)
        ->and($translations['Home page'])->toBe('Pagina breve');
});
