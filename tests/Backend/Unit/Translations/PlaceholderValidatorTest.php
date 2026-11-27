<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function placeholderValidator(): Maho_Translations_Model_Validator_Placeholder
{
    return new Maho_Translations_Model_Validator_Placeholder();
}

it('accepts matching printf placeholders', function () {
    $errors = placeholderValidator()->validate('Hello %s', 'Ciao %s');
    expect($errors)->toBe([]);
});

it('rejects a missing printf placeholder', function () {
    $errors = placeholderValidator()->validate('Hello %s', 'Ciao');
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('%s');
});

it('rejects an extra printf placeholder', function () {
    $errors = placeholderValidator()->validate('Hello', 'Ciao %d');
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('%d');
});

it('accepts reordered positional placeholders', function () {
    $errors = placeholderValidator()->validate('Hello %1$s and %2$s', 'Ciao %2$s e %1$s');
    expect($errors)->toBe([]);
});

it('rejects a changed positional placeholder', function () {
    $errors = placeholderValidator()->validate('Total %1$02d', 'Totale %02d');
    expect($errors)->not->toBe([]);
});

it('ignores literal percent signs', function () {
    $errors = placeholderValidator()->validate('100% sure', '100% sicuro');
    expect($errors)->toBe([]);
});

it('accepts matching template directives', function () {
    $errors = placeholderValidator()->validate('Sku {{var sku}}', 'Codice {{var sku}}');
    expect($errors)->toBe([]);
});

it('rejects a missing template directive', function () {
    $errors = placeholderValidator()->validate('Sku {{var sku}}', 'Codice');
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('{{var sku}}');
});

it('accepts matching html tags', function () {
    $errors = placeholderValidator()->validate('<p>Hello <strong>world</strong></p>', '<p>Ciao <strong>mondo</strong></p>');
    expect($errors)->toBe([]);
});

it('rejects a missing html closing tag', function () {
    $errors = placeholderValidator()->validate('<p>Hello</p>', '<p>Ciao');
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('p');
});

it('rejects an extra html tag', function () {
    $errors = placeholderValidator()->validate('Hello', 'Ciao<br>');
    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('br');
});

it('accepts multibyte translations', function () {
    $errors = placeholderValidator()->validate('Hello %s', '你好 %s');
    expect($errors)->toBe([]);
});
