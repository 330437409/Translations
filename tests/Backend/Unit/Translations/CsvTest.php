<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function writeTranslationCsv(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'maho-translations-');
    file_put_contents($file, $contents);
    return $file;
}

it('parses a two column csv', function () {
    $file = writeTranslationCsv('"Home page","Pagina iniziale"' . "\n" . '"Add to Cart","Aggiungi al carrello"' . "\n");

    try {
        $result = (new Maho_Translations_Model_Csv())->parse($file);
        expect($result['errors'])->toBe([])
            ->and($result['rows'])->toHaveCount(2)
            ->and($result['rows'][0]['source'])->toBe('Home page')
            ->and($result['rows'][0]['translation'])->toBe('Pagina iniziale')
            ->and($result['rows'][1]['source'])->toBe('Add to Cart');
    } finally {
        unlink($file);
    }
});

it('strips a utf8 bom and skips a header', function () {
    $file = writeTranslationCsv("\xEF\xBB\xBFsource,translation\r\n\"Home page\",\"Pagina iniziale\"\r\n");

    try {
        $result = (new Maho_Translations_Model_Csv())->parse($file);
        expect($result['errors'])->toBe([])
            ->and($result['rows'])->toHaveCount(1)
            ->and($result['rows'][0]['source'])->toBe('Home page');
    } finally {
        unlink($file);
    }
});

it('handles quoted commas and newlines', function () {
    $file = writeTranslationCsv('"Hello, world","Ciao, mondo"' . "\n" . '"Line one' . "\n" . 'Line two","Riga uno' . "\n" . 'Riga due"' . "\n");

    try {
        $result = (new Maho_Translations_Model_Csv())->parse($file);
        expect($result['errors'])->toBe([])
            ->and($result['rows'][0]['source'])->toBe('Hello, world')
            ->and($result['rows'][1]['source'])->toBe("Line one\nLine two")
            ->and($result['rows'][1]['translation'])->toBe("Riga uno\nRiga due");
    } finally {
        unlink($file);
    }
});

it('reports duplicate sources and short rows', function () {
    $file = writeTranslationCsv('"Home page","Pagina iniziale"' . "\n" . '"Home page","Pagina iniziale due"' . "\n" . '"Only one column"' . "\n");

    try {
        $result = (new Maho_Translations_Model_Csv())->parse($file);
        expect($result['rows'])->toHaveCount(2)
            ->and($result['errors'])->toHaveCount(2)
            ->and(implode(' ', $result['errors']))->toContain('duplicate')
            ->and(implode(' ', $result['errors']))->toContain('two columns');
    } finally {
        unlink($file);
    }
});

it('escapes and restores spreadsheet formula prefixes', function () {
    $csv = new Maho_Translations_Model_Csv();
    $stream = fopen('php://temp', 'r+');
    $csv->writeRow($stream, '=SUM(A1)', '+cmd|calc');
    rewind($stream);
    $body = (string) stream_get_contents($stream);
    fclose($stream);

    expect($body)->toContain("'=SUM(A1)")
        ->and($body)->toContain("'+cmd|calc");

    $file = writeTranslationCsv($body);
    try {
        $result = $csv->parse($file);
        expect($result['errors'])->toBe([])
            ->and($result['rows'][0]['source'])->toBe('=SUM(A1)')
            ->and($result['rows'][0]['translation'])->toBe('+cmd|calc');
    } finally {
        unlink($file);
    }
});

it('skips blank rows', function () {
    $file = writeTranslationCsv('"Home page","Pagina iniziale"' . "\n\n" . ',,' . "\n");

    try {
        $result = (new Maho_Translations_Model_Csv())->parse($file);
        expect($result['errors'])->toBe([])
            ->and($result['rows'])->toHaveCount(1);
    } finally {
        unlink($file);
    }
});
