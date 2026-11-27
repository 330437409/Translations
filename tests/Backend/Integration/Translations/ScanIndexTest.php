<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Maho\Db\Expr;
use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function translationScanFixtureRoot(): string
{
    return dirname(__DIR__, 2) . '/Unit/Translations/fixtures';
}

function translationTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function translationCount(string $entity, ?string $where = null, array $bind = []): int
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $select = $write->select()->from(translationTable($entity), ['count' => new Expr('COUNT(*)')]);
    if ($where !== null) {
        $select->where($where, $bind);
    }
    return (int) $write->fetchOne($select);
}

function translationCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(translationTable('location'));
    $write->delete(translationTable('pack'));
    $write->delete(translationTable('source'));
    $write->delete(translationTable('scan'));
    $write->delete(translationTable('long'));
}

it('writes the scan index tables', function () {
    translationCleanup();

    $scanner = new Maho_Translations_Model_Scanner(translationScanFixtureRoot());
    $stats = $scanner->scan();

    expect($stats['sources'])->toBe(6)
        ->and($stats['locations'])->toBe(6)
        ->and($stats['pack_translations'])->toBe(9);

    expect(translationCount('source'))->toBe(6)
        ->and(translationCount('location'))->toBe(6)
        ->and(translationCount('pack'))->toBe(9);

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $addToCart = $write->fetchRow(
        $write->select()->from(translationTable('source'))->where('source_text = ?', 'Add to Cart'),
    );
    expect((int) $addToCart['is_used'])->toBe(1)
        ->and((int) $addToCart['is_supported'])->toBe(1)
        ->and((int) $addToCart['locations_count'])->toBe(2);

    $pack = $write->fetchRow(
        $write->select()
            ->from(translationTable('pack'))
            ->where('locale = ?', 'it_IT')
            ->where('source_hash = ?', md5('Add to Cart')),
    );
    expect($pack['translation'])->toBe('Aggiungi al carrello');

    $unused = $write->fetchRow(
        $write->select()->from(translationTable('source'))->where('source_text = ?', 'Unused string'),
    );
    expect((int) $unused['is_used'])->toBe(0);

    $scan = $write->fetchRow(
        $write->select()->from(translationTable('scan'))->order('scan_id DESC')->limit(1),
    );
    expect($scan['status'])->toBe('complete');

    translationCleanup();
});

it('keeps the index stable across repeated scans', function () {
    translationCleanup();

    $scanner = new Maho_Translations_Model_Scanner(translationScanFixtureRoot());
    $first = $scanner->scan();
    $second = $scanner->scan();

    expect($second['sources'])->toBe($first['sources'])
        ->and($second['locations'])->toBe($first['locations'])
        ->and(translationCount('source'))->toBe($first['sources'])
        ->and(translationCount('location'))->toBe($first['locations']);

    translationCleanup();
});

it('keeps the first seen timestamp across rescans', function () {
    translationCleanup();

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $scanner = new Maho_Translations_Model_Scanner(translationScanFixtureRoot());
    $scanner->scan();
    $write->update(translationTable('source'), ['first_seen_at' => '2020-01-01 00:00:00']);
    $scanner->scan();

    $firstSeen = $write->fetchOne(
        $write->select()->from(translationTable('source'), ['first_seen_at'])->where('source_text = ?', 'Home page'),
    );
    expect((string) $firstSeen)->toStartWith('2020-01-01');

    translationCleanup();
});

it('stores module lists longer than the old varchar limit', function () {
    translationCleanup();

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $modules = implode(',', array_map(static fn(int $index): string => 'Module_' . $index, range(1, 100)));
    $write->insert(translationTable('source'), [
        'source_hash' => md5('Long module list'),
        'source_crc' => crc32('Long module list'),
        'source_text' => 'Long module list',
        'source_length' => 16,
        'is_used' => 0,
        'is_supported' => 1,
        'modules' => $modules,
        'areas' => 'frontend',
        'locations_count' => 0,
    ]);

    $stored = $write->fetchOne(
        $write->select()->from(translationTable('source'), ['modules'])->where('source_text = ?', 'Long module list'),
    );
    expect(strlen((string) $stored))->toBeGreaterThan(512);

    translationCleanup();
});

it('refuses to start a second scan while one is running', function () {
    translationCleanup();

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(translationTable('scan'), [
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $scanner = new Maho_Translations_Model_Scanner(translationScanFixtureRoot());

    expect(fn() => $scanner->scan())->toThrow(Mage_Core_Exception::class, 'already running');

    translationCleanup();
});

it('recovers a stale running scan', function () {
    translationCleanup();

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(translationTable('scan'), [
        'status' => 'running',
        'started_at' => date('Y-m-d H:i:s', time() - 601),
    ]);

    $stats = (new Maho_Translations_Model_Scanner(translationScanFixtureRoot()))->scan();

    $scans = $write->fetchAll(
        $write->select()->from(translationTable('scan'))->order('scan_id ASC'),
    );

    expect($stats['sources'])->toBe(6)
        ->and($scans)->toHaveCount(2)
        ->and($scans[0]['status'])->toBe('failed')
        ->and($scans[0]['message'])->toBe('Scan timed out.')
        ->and($scans[1]['status'])->toBe('complete');

    translationCleanup();
});

it('marks a scan as failed when indexing throws', function () {
    translationCleanup();

    $scanner = new class (translationScanFixtureRoot()) extends Maho_Translations_Model_Scanner {
        public function collect(): array
        {
            return [
                'sources' => [
                    'broken' => [
                        'text' => ['not-a-string'],
                        'locations' => [],
                        'modules' => [],
                        'areas' => [],
                        'length' => 1,
                        'is_used' => false,
                        'is_supported' => true,
                    ],
                ],
                'packs' => [],
                'stats' => [
                    'sources' => 1,
                    'locations' => 0,
                    'pack_translations' => 0,
                    'skipped_dynamic' => 0,
                    'skipped_xml' => 0,
                    'skipped_files' => 0,
                ],
            ];
        }
    };

    expect(fn() => $scanner->scan())->toThrow(TypeError::class);

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $scan = $write->fetchRow(
        $write->select()->from(translationTable('scan'))->order('scan_id DESC')->limit(1),
    );

    expect($scan['status'])->toBe('failed')
        ->and((string) $scan['message'])->toContain('md5');

    translationCleanup();
});
