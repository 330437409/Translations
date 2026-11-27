<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function translationFixtureRoot(): string
{
    return __DIR__ . '/fixtures';
}

/**
 * @param array<string, array<string, mixed>> $sources
 * @return array<string, mixed>
 */
function translationSourceByText(array $sources, string $text): array
{
    foreach ($sources as $source) {
        if ($source['text'] === $text) {
            return $source;
        }
    }
    throw new RuntimeException("Source not found: $text");
}

it('collects source strings and locations from php, phtml and xml', function () {
    $scanner = new Maho_Translations_Model_Scanner(translationFixtureRoot());
    $result = $scanner->collect();
    $sources = $result['sources'];

    expect($sources)->toHaveCount(6);

    $addToCart = translationSourceByText($sources, 'Add to Cart');
    expect($addToCart['is_used'])->toBeTrue()
        ->and($addToCart['modules'])->toContain('Acme_Shop')
        ->and($addToCart['areas'])->toContain('frontend')
        ->and($addToCart['locations'])->toHaveCount(2);

    $lines = array_unique(array_column($addToCart['locations'], 'line'));
    expect($lines)->toContain(9);

    $welcome = translationSourceByText($sources, 'Welcome');
    expect($welcome['locations'])->toHaveCount(1)
        ->and($welcome['locations'][0]['file'])->toEndWith('template/acme/view.phtml');

    $xmlSource = translationSourceByText($sources, 'Acme Shop');
    expect($xmlSource['locations'])->toHaveCount(1)
        ->and($xmlSource['locations'][0]['path'])->toContain('acme')
        ->and($xmlSource['locations'][0]['area'])->toBe('adminhtml')
        ->and($xmlSource['locations'][0]['line'])->toBeGreaterThan(0);
});

it('loads csv definitions and pack translations', function () {
    $scanner = new Maho_Translations_Model_Scanner(translationFixtureRoot());
    $result = $scanner->collect();

    $unused = translationSourceByText($result['sources'], 'Unused string');
    expect($unused['is_used'])->toBeFalse();

    $hash = md5('Add to Cart');
    expect($result['packs']['it_IT'][$hash]['translation'])->toBe('Aggiungi al carrello')
        ->and($result['packs']['en_US'][$hash]['translation'])->toBe('Add to Cart');
});

it('skips dynamic strings and counts them', function () {
    $scanner = new Maho_Translations_Model_Scanner(translationFixtureRoot());
    $result = $scanner->collect();

    expect($result['stats']['skipped_dynamic'])->toBeGreaterThanOrEqual(1);
});

it('merges the same string from multiple files into one source', function () {
    $scanner = new Maho_Translations_Model_Scanner(translationFixtureRoot());
    $result = $scanner->collect();

    $addToCart = translationSourceByText($result['sources'], 'Add to Cart');
    $files = array_column($addToCart['locations'], 'file');

    expect($files)->toHaveCount(2)
        ->and($files[0])->not->toBe($files[1]);
});

it('marks strings longer than 255 characters for long storage', function () {
    $root = sys_get_temp_dir() . '/maho-translations-' . uniqid('', true);
    mkdir($root . '/app/locale/en_US', 0777, true);

    $long = str_repeat('Long translation ', 20);
    file_put_contents($root . '/app/locale/en_US/Acme_Shop.csv', '"' . $long . '","' . $long . '"' . "\n");

    try {
        $scanner = new Maho_Translations_Model_Scanner($root);
        $result = $scanner->collect();
        $source = translationSourceByText($result['sources'], $long);

        expect($source['is_supported'])->toBeFalse();
    } finally {
        unlink($root . '/app/locale/en_US/Acme_Shop.csv');
        rmdir($root . '/app/locale/en_US');
        rmdir($root . '/app/locale');
        rmdir($root . '/app');
        rmdir($root);
    }
});
