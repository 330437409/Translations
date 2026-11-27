<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function scannerEdgeRoot(): string
{
    $root = sys_get_temp_dir() . '/maho-translations-edge-' . uniqid('', true);
    mkdir($root . '/app/code/local/Acme/Edge/Block', 0777, true);
    mkdir($root . '/app/locale/en_US', 0777, true);
    return $root;
}

function scannerEdgeCleanup(string $root): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}

it('ignores comments, interpolation and concatenation while keeping literal calls', function () {
    $root = scannerEdgeRoot();
    $php = <<<'PHP'
    <?php
    __('Plain');
    __('With arg %s', $name);
    __("Interpolated $name");
    __('Concat ' . $name);
    // __('Commented');
    /* __('Block comment') */
    __($dynamic);
    PHP;
    file_put_contents($root . '/app/code/local/Acme/Edge/Block/View.php', $php);

    try {
        $result = (new Maho_Translations_Model_Scanner($root))->collect();
        $texts = array_column($result['sources'], 'text');

        expect($texts)->toContain('Plain')
            ->and($texts)->toContain('With arg %s')
            ->and($texts)->not->toContain('Interpolated $name')
            ->and($texts)->not->toContain('Concat')
            ->and($texts)->not->toContain('Commented')
            ->and($texts)->not->toContain('Block comment')
            ->and($result['stats']['skipped_dynamic'])->toBe(3);
    } finally {
        scannerEdgeCleanup($root);
    }
});

it('records conflicting language pack translations', function () {
    $root = scannerEdgeRoot();
    mkdir($root . '/app/locale/zz_ZZ', 0777, true);
    file_put_contents($root . '/app/locale/zz_ZZ/Acme_First.csv', "\"Shared\",\"Prima\"\n");
    file_put_contents($root . '/app/locale/zz_ZZ/Acme_Second.csv', "\"Shared\",\"Seconda\"\n");

    try {
        $result = (new Maho_Translations_Model_Scanner($root))->collect();
        $pack = $result['packs']['zz_ZZ'][md5('Shared')];

        expect($pack['translation'])->toBe('Prima')
            ->and($pack['conflict_count'])->toBe(1)
            ->and($pack['alternatives'])->toContain('Acme_Second: Seconda');
    } finally {
        scannerEdgeCleanup($root);
    }
});

it('strips a utf8 bom from language pack csv files', function () {
    $root = scannerEdgeRoot();
    file_put_contents(
        $root . '/app/locale/en_US/Acme_Edge.csv',
        "\xEF\xBB\xBF\"Home\",\"Home\"\n",
    );

    try {
        $result = (new Maho_Translations_Model_Scanner($root))->collect();
        $texts = array_column($result['sources'], 'text');

        expect($texts)->toContain('Home')
            ->and($texts)->not->toContain("\u{FEFF}\"Home\"");
    } finally {
        scannerEdgeCleanup($root);
    }
});
