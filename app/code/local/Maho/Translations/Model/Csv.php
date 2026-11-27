<?php

/**
 * Parses and writes two column translation CSV files.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Csv
{
    /**
     * @return array{rows: array<int, array{line: int, source: string, translation: ?string}>, errors: array<int, string>}
     */
    public function parse(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            return ['rows' => [], 'errors' => ['Unable to read the uploaded file.']];
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ['rows' => [], 'errors' => ['Unable to read the uploaded file.']];
        }
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        $errors = [];
        $seen = [];
        $firstRow = true;

        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $offset = ftell($stream);
            $line = $offset === false
                ? 0
                : substr_count($contents, "\n", 0, max(0, $offset - 1)) + 1;

            if ($this->isBlankRow($row)) {
                continue;
            }

            if ($firstRow) {
                $firstRow = false;
                if ($this->isHeaderRow($row)) {
                    continue;
                }
            }

            if (count($row) < 2) {
                $errors[] = "Row $line: expected two columns.";
                continue;
            }

            $source = $this->restoreCell((string) $row[0]);
            $translation = $this->restoreCell((string) $row[1]);

            if ($source === '') {
                $errors[] = "Row $line: source is empty.";
                continue;
            }

            if (isset($seen[$source])) {
                $errors[] = "Row $line: duplicate source \"$source\".";
            }
            $seen[$source] = true;

            $rows[] = [
                'line' => $line,
                'source' => $source,
                'translation' => $translation === '' ? null : $translation,
            ];
        }

        fclose($stream);

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param resource $handle
     */
    public function writeRow(mixed $handle, string $source, ?string $translation): void
    {
        fputcsv(
            $handle,
            [$this->sanitizeCell($source), $this->sanitizeCell($translation ?? '')],
            ',',
            '"',
            '\\',
        );
    }

    private function sanitizeCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    private function restoreCell(string $value): string
    {
        if (str_starts_with($value, "'") && isset($value[1])
            && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return substr($value, 1);
        }
        return $value;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isBlankRow(array $row): bool
    {
        return array_all($row, fn($value) => trim((string) $value) === '');
    }

    /**
     * @param array<int, mixed> $row
     */
    private function isHeaderRow(array $row): bool
    {
        return strtolower(trim((string) ($row[0] ?? ''))) === 'source'
            && strtolower(trim((string) ($row[1] ?? ''))) === 'translation';
    }
}
