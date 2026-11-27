<?php

/**
 * Builds prompts for AI translation drafts.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Ai_Prompt
{
    /**
     * @param array<string, mixed> $source
     * @return array{system: string, user: string}
     */
    public function build(array $source, string $locale, int $storeId): array
    {
        $sourceText = (string) ($source['source_text'] ?? '');
        $validator = new Maho_Translations_Model_Validator_Placeholder();
        $placeholders = array_values(array_unique(array_merge(
            $validator->extractPrintf($sourceText),
            $validator->extractTemplate($sourceText),
            array_map(static fn(string $tag): string => '<' . $tag . '>', $validator->extractHtml($sourceText)),
        )));

        $storeName = $storeId > 0
            ? (string) Mage::app()->getStore($storeId)->getName()
            : 'All Store Views';
        $module = (string) ($source['modules'] ?? '');
        $area = (string) ($source['areas'] ?? '');
        $baseTranslation = trim((string) ($source['base_translation'] ?? ''));

        $lines = [
            'Target locale: ' . $locale,
            'Store: ' . $storeName,
            'Module: ' . ($module !== '' ? $module : '-'),
            'Area: ' . ($area !== '' ? $area : '-'),
            'Placeholders to preserve exactly: ' . ($placeholders === [] ? 'none' : implode(' ', $placeholders)),
            '',
            'Source string:',
            $sourceText,
        ];
        if ($baseTranslation !== '' && $baseTranslation !== $sourceText) {
            $lines[] = '';
            $lines[] = 'Current translation, for reference only:';
            $lines[] = $baseTranslation;
        }
        $lines[] = '';
        $lines[] = 'Return only the translated string.';

        return [
            'system' => 'You are a professional software localization translator. '
                . 'Translate the source string into the target locale. '
                . 'Preserve every printf placeholder, template directive and HTML tag exactly. '
                . 'Do not add quotes, labels, Markdown or explanations. '
                . 'If the source is already in the target language, return it unchanged.',
            'user' => implode("\n", $lines),
        ];
    }
}
