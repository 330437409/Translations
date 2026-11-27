<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function transferTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function transferCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(transferTable('location'));
    $write->delete(transferTable('pack'));
    $write->delete(transferTable('source'));
    $write->delete(transferTable('scan'));
    $write->delete(transferTable('long'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale = ?' => 'zz_ZZ',
        'store_id = ?' => 0,
        'string IN (?)' => ['Add to Cart', 'Home page', 'Welcome', 'Unused string', 'Hello %s', 'Acme Shop'],
    ]);
}


function transferFixtureSources(): array
{
    return ['Add to Cart', 'Home page', 'Welcome', 'Unused string', 'Hello %s', 'Acme Shop'];
}

function transferCoreCount(): int
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return (int) $read->fetchOne(
        $read->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('core/translate'), ['c' => new Maho\Db\Expr('COUNT(*)')])
            ->where('locale = ?', 'zz_ZZ')
            ->where('string IN (?)', transferFixtureSources()),
    );
}

function transferRows(string $source, ?string $translation, int $line = 1): array
{
    return ['line' => $line, 'source' => $source, 'translation' => $translation];
}

function transferInsertLongSource(string $text): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(transferTable('source'), [
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
}

function transferLongCount(string $text): int
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return (int) $read->fetchOne(
        $read->select()
            ->from(transferTable('long'), ['c' => new Maho\Db\Expr('COUNT(*)')])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', 0)
            ->where('source_hash = ?', md5($text)),
    );
}

beforeEach(function () {
    transferCleanup();
    $scanner = new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures');
    $scanner->scan();
});

afterEach(function () {
    transferCleanup();
});

it('imports new translations and reports counts', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $result = $transfer->import([
        transferRows('Home page', 'Pagina iniziale'),
        transferRows('Add to Cart', 'Aggiungi al carrello'),
    ], 'zz_ZZ', 0);

    expect($result['errors'])->toBe([])
        ->and($result['created'])->toBe(2)
        ->and($result['updated'])->toBe(0);

    expect(transferCoreCount())->toBe(2);
});

it('is idempotent and updates changed translations', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $rows = [transferRows('Home page', 'Pagina iniziale')];

    $first = $transfer->import($rows, 'zz_ZZ', 0);
    $second = $transfer->import($rows, 'zz_ZZ', 0);
    $third = $transfer->import([transferRows('Home page', 'Pagina home')], 'zz_ZZ', 0);

    expect($first['created'])->toBe(1)
        ->and($second['unchanged'])->toBe(1)
        ->and($third['updated'])->toBe(1);
});

it('clears a translation when the second column is empty', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $transfer->import([transferRows('Home page', 'Pagina iniziale')], 'zz_ZZ', 0);
    $result = $transfer->import([transferRows('Home page', null)], 'zz_ZZ', 0);

    expect($result['cleared'])->toBe(1);

    expect(transferCoreCount())->toBe(0);
});

it('imports and exports long translations', function () {
    $source = str_repeat('Long source string ', 20);
    $translation = str_repeat('Traduzione lunga ', 20);
    transferInsertLongSource($source);
    $transfer = new Maho_Translations_Model_Transfer();

    $result = $transfer->import([transferRows($source, $translation)], 'zz_ZZ', 0);
    $effective = array_column($transfer->export('effective', 'zz_ZZ', 0), 'translation', 'source');
    $overrides = $transfer->export('overrides', 'zz_ZZ', 0);

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toBe([])
        ->and(transferLongCount($source))->toBe(1)
        ->and($effective[$source])->toBe($translation)
        ->and($overrides)->toHaveCount(1)
        ->and($overrides[0]['source'])->toBe($source)
        ->and($overrides[0]['translation'])->toBe($translation);

    $clear = $transfer->import([transferRows($source, null)], 'zz_ZZ', 0);

    expect($clear['cleared'])->toBe(1)
        ->and(transferLongCount($source))->toBe(0);
});

it('rejects the whole batch when a placeholder does not match', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $result = $transfer->import([
        transferRows('Home page', 'Pagina iniziale'),
        transferRows('Hello %s', 'Ciao'),
    ], 'zz_ZZ', 0);

    expect($result['errors'])->not->toBe([])
        ->and($result['created'])->toBe(0);

    expect(transferCoreCount())->toBe(0);
});

it('round trips an override export through import', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $transfer->import([transferRows('Home page', 'Pagina iniziale')], 'zz_ZZ', 0);

    $exported = $transfer->export('overrides', 'zz_ZZ', 0);
    $rows = [];
    foreach ($exported as $index => $row) {
        $rows[] = ['line' => $index + 1, 'source' => $row['source'], 'translation' => $row['translation']];
    }
    $result = $transfer->import($rows, 'zz_ZZ', 0);

    expect($exported)->toHaveCount(1)
        ->and($exported[0]['source'])->toBe('Home page')
        ->and($exported[0]['translation'])->toBe('Pagina iniziale')
        ->and($result['errors'])->toBe([])
        ->and($result['unchanged'])->toBe(1)
        ->and($transfer->export('overrides', 'zz_ZZ', 0))->toBe($exported);
});

it('rejects sources that are not in the scan index', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $result = $transfer->import([transferRows('Not scanned', 'Non scansionato')], 'zz_ZZ', 0);

    expect($result['errors'])->not->toBe([])
        ->and(implode(' ', $result['errors']))->toContain('scan index');
});

it('exports effective, missing and override rows', function () {
    $transfer = new Maho_Translations_Model_Transfer();
    $transfer->import([transferRows('Welcome', 'Benvenuto')], 'zz_ZZ', 0);

    $effective = $transfer->export('effective', 'zz_ZZ', 0);
    $missing = $transfer->export('missing', 'zz_ZZ', 0);
    $overrides = $transfer->export('overrides', 'zz_ZZ', 0);

    $effectiveBySource = array_column($effective, 'translation', 'source');
    expect($effectiveBySource['Home page'])->toBe('Pagina iniziale')
        ->and($effectiveBySource['Welcome'])->toBe('Benvenuto')
        ->and($effectiveBySource['Hello %s'])->toBeNull();

    $missingSources = array_column($missing, 'source');
    expect($missingSources)->toContain('Hello %s')
        ->and($missingSources)->not->toContain('Welcome');

    expect($overrides)->toHaveCount(1)
        ->and($overrides[0]['source'])->toBe('Welcome')
        ->and($overrides[0]['translation'])->toBe('Benvenuto');
});
