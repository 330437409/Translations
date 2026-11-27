<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function validationTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function validationCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(validationTable('location'));
    $write->delete(validationTable('pack'));
    $write->delete(validationTable('source'));
    $write->delete(validationTable('scan'));
    $write->delete(validationTable('long'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale IN (?)' => ['zz_ZZ', 'xx_XX'],
    ]);
}

function validationSource(string $text): array
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow(
        $read->select()->from(validationTable('source'))->where('source_text = ?', $text),
    );
    expect($row)->not->toBeFalse();
    return $row;
}

function validationCoreCount(): int
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return (int) $read->fetchOne(
        $read->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('core/translate'), ['c' => new Maho\Db\Expr('COUNT(*)')])
            ->where('locale IN (?)', ['zz_ZZ', 'xx_XX']),
    );
}

beforeEach(function () {
    validationCleanup();
    (new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures'))->scan();

});

afterEach(function () {
    validationCleanup();
});

it('stores translations longer than the core column in the long table', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    $translation = str_repeat('x', 300);

    $catalog->saveTranslation(validationSource('Home page'), $translation, 'zz_ZZ', 0);

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $stored = $read->fetchOne(
        $read->select()
            ->from(validationTable('long'), ['translation'])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', 0)
            ->where('source_hash = ?', md5('Home page')),
    );

    expect($stored)->toBe($translation)
        ->and(validationCoreCount())->toBe(0);
});

it('rejects placeholder mismatches from the model layer', function () {
    $catalog = new Maho_Translations_Model_Catalog();

    expect(fn() => $catalog->saveTranslation(validationSource('Hello %s'), 'Ciao', 'zz_ZZ', 0))
        ->toThrow(Mage_Core_Exception::class);

    expect(validationCoreCount())->toBe(0);
});

it('rejects invalid locales and stores from the model layer', function () {
    $catalog = new Maho_Translations_Model_Catalog();
    $source = validationSource('Home page');

    expect(fn() => $catalog->saveTranslation($source, 'Pagina', 'xx_XX', 0))
        ->toThrow(Mage_Core_Exception::class);
    expect(fn() => $catalog->saveTranslation($source, 'Pagina', 'zz_ZZ', 999))
        ->toThrow(Mage_Core_Exception::class);

    expect(validationCoreCount())->toBe(0);
});

it('falls back to the source when a pack translation is empty', function () {
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->update(validationTable('pack'), ['translation' => ''], [
        'locale = ?' => 'zz_ZZ',
        'source_hash = ?' => md5('Home page'),
    ]);

    $catalog = new Maho_Translations_Model_Catalog();
    expect($catalog->getEffectiveTranslation(validationSource('Home page'), 'zz_ZZ', 0))->toBe('Home page');
});

it('rejects invalid import scopes from the import service', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $row = ['line' => 1, 'source' => 'Home page', 'translation' => 'Pagina'];

    $badLocale = $transfer->import([$row], 'xx_XX', 0);
    $badStore = $transfer->import([$row], 'zz_ZZ', 999);

    expect($badLocale['errors'])->not->toBe([])
        ->and($badStore['errors'])->not->toBe([])
        ->and(validationCoreCount())->toBe(0);
});
