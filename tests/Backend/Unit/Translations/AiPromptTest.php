<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

it('builds a prompt with locale, context, placeholders and base translation', function () {
    $prompt = new Maho_Translations_Model_Ai_Prompt();
    $result = $prompt->build([
        'source_text' => 'Hello %s, <strong>welcome</strong>',
        'modules' => 'Acme_Shop',
        'areas' => 'frontend',
        'base_translation' => 'Ciao %s',
    ], 'it_IT', 0);

    expect($result['system'])->toContain('professional software localization translator')
        ->and($result['system'])->toContain('Preserve every printf placeholder')
        ->and($result['user'])->toContain('Return only')
        ->and($result['user'])->toContain('Target locale: it_IT')
        ->and($result['user'])->toContain('Store: All Store Views')
        ->and($result['user'])->toContain('Module: Acme_Shop')
        ->and($result['user'])->toContain('Area: frontend')
        ->and($result['user'])->toContain('Hello %s, <strong>welcome</strong>')
        ->and($result['user'])->toContain('%s')
        ->and($result['user'])->toContain('<strong>')
        ->and($result['user'])->toContain('Current translation, for reference only:')
        ->and($result['user'])->toContain('Ciao %s');
});
