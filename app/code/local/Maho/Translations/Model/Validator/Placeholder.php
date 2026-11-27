<?php

/**
 * Validates runtime placeholders in translated strings.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Validator_Placeholder
{
    /**
     * @return array<int, string>
     */
    public function validate(string $source, string $translation): array
    {
        $errors = [];

        $sourcePrintf = $this->extractPrintf($source);
        $translationPrintf = $this->extractPrintf($translation);
        $errors = array_merge($errors, $this->compareTokens('Placeholder', $sourcePrintf, $translationPrintf));

        $sourceTemplate = $this->extractTemplate($source);
        $translationTemplate = $this->extractTemplate($translation);
        $errors = array_merge($errors, $this->compareTokens('Template directive', $sourceTemplate, $translationTemplate));

        $sourceHtml = $this->extractHtml($source);
        $translationHtml = $this->extractHtml($translation);
        $errors = array_merge($errors, $this->compareHtml($sourceHtml, $translationHtml));

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    public function extractPrintf(string $text): array
    {
        preg_match_all('/%(\d+\$)?[-+0#\']*(\d+)?(?:\.(\d+))?([bcdeEfFgGosuxX%])/', $text, $matches);
        return $matches[0];
    }

    /**
     * @return array<int, string>
     */
    public function extractTemplate(string $text): array
    {
        preg_match_all('/{{.*?}}/s', $text, $matches);
        $tokens = [];
        foreach ($matches[0] as $token) {
            $tokens[] = (string) preg_replace('/\s+/', ' ', trim($token));
        }
        return $tokens;
    }

    /**
     * @return array<int, string>
     */
    public function extractHtml(string $text): array
    {
        preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9:-]*)\b[^>]*>/', $text, $matches);
        return array_map(strtolower(...), $matches[1]);
    }

    /**
     * @param array<int, string> $source
     * @param array<int, string> $translation
     * @return array<int, string>
     */
    private function compareTokens(string $label, array $source, array $translation): array
    {
        $sourceCounts = array_count_values($source);
        $translationCounts = array_count_values($translation);

        $missing = [];
        $extra = [];
        foreach ($sourceCounts as $token => $count) {
            $diff = $count - ($translationCounts[$token] ?? 0);
            if ($diff > 0) {
                $missing[] = $count > 1 ? $token . ' x' . $diff : $token;
            }
        }
        foreach ($translationCounts as $token => $count) {
            $diff = $count - ($sourceCounts[$token] ?? 0);
            if ($diff > 0) {
                $extra[] = $count > 1 ? $token . ' x' . $diff : $token;
            }
        }

        if ($missing === [] && $extra === []) {
            return [];
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing ' . implode(', ', $missing);
        }
        if ($extra !== []) {
            $parts[] = 'extra ' . implode(', ', $extra);
        }
        return [$label . ' mismatch: ' . implode('; ', $parts)];
    }

    /**
     * @param array<int, string> $source
     * @param array<int, string> $translation
     * @return array<int, string>
     */
    private function compareHtml(array $source, array $translation): array
    {
        $sourceCounts = array_count_values($source);
        $translationCounts = array_count_values($translation);

        $missing = [];
        $extra = [];
        foreach ($sourceCounts as $tag => $count) {
            $diff = $count - ($translationCounts[$tag] ?? 0);
            if ($diff > 0) {
                $missing[] = '<' . $tag . '>' . ($diff > 1 ? ' x' . $diff : '');
            }
        }
        foreach ($translationCounts as $tag => $count) {
            $diff = $count - ($sourceCounts[$tag] ?? 0);
            if ($diff > 0) {
                $extra[] = '<' . $tag . '>' . ($diff > 1 ? ' x' . $diff : '');
            }
        }

        if ($missing === [] && $extra === []) {
            return [];
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = 'missing ' . implode(', ', $missing);
        }
        if ($extra !== []) {
            $parts[] = 'extra ' . implode(', ', $extra);
        }
        return ['HTML tag mismatch: ' . implode('; ', $parts)];
    }
}
