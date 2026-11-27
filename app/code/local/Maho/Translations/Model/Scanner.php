<?php

/**
 * Scans Maho translation sources and language packs.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Model_Scanner
{
    private const int MAX_FILE_SIZE = 2097152;
    private const int BATCH_SIZE = 500;
    private const int MAX_SCAN_ROWS = 20;
    private const int SCAN_TIMEOUT_SECONDS = 600;

    private string $rootDir;
    private bool $explicitRoot;

    /**
     * @param array{root_dir?: string}|string|null $rootDir
     */
    public function __construct(array|string|null $rootDir = null)
    {
        if (is_array($rootDir)) {
            $rootDir = $rootDir['root_dir'] ?? null;
        }
        $this->explicitRoot = $rootDir !== null;
        $this->rootDir = rtrim($rootDir ?? Mage::getBaseDir(), '/\\');
    }

    /**
     * @return array{
     *     sources: array<string, array{text: string, locations: array<int, array<string, mixed>>, modules: array<int, string>, areas: array<int, string>, is_used: bool, length: int, is_supported: bool}>,
     *     packs: array<string, array<string, array{translation: ?string, module: string, file: string, conflict_count: int, alternatives: array<int, string>}>>,
     *     stats: array<string, int|float>
     * }
     */
    public function collect(): array
    {
        $startedAt = microtime(true);
        $sources = [];
        $packs = [];
        $stats = [
            'files_scanned' => 0,
            'skipped_files' => 0,
            'skipped_dynamic' => 0,
            'skipped_xml' => 0,
            'pack_conflicts' => 0,
        ];

        foreach ($this->getSourceFiles() as $file) {
            $stats['files_scanned']++;
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            if ($extension === 'xml') {
                $this->parseXmlFile($file, $sources, $stats);
            } else {
                $this->parsePhpFile($file, $sources, $stats);
            }
        }

        $this->parseCsvFiles($sources, $packs, $stats);

        foreach ($sources as &$source) {
            $source['locations'] = array_values($source['locations']);
            usort(
                $source['locations'],
                static fn(array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']],
            );
            $source['modules'] = array_values(array_unique(array_filter($source['modules'], static fn(string $value): bool => $value !== '')));
            sort($source['modules']);
            $source['areas'] = array_values(array_unique(array_filter($source['areas'], static fn(string $value): bool => $value !== '')));
            sort($source['areas']);
            $source['length'] = mb_strlen($source['text'], 'UTF-8');
            $source['is_used'] = count($source['locations']) > 0;
            $source['is_supported'] = $source['length'] > 0 && $source['length'] <= 255;
        }
        unset($source);

        $stats['sources'] = count($sources);
        $stats['locations'] = array_sum(array_map(
            static fn(array $source): int => count($source['locations']),
            $sources,
        ));
        $stats['pack_translations'] = array_sum(array_map(count(...), $packs));
        $stats['long_strings'] = count(array_filter(
            $sources,
            static fn(array $source): bool => !$source['is_supported'],
        ));
        $stats['duration'] = microtime(true) - $startedAt;

        return [
            'sources' => $sources,
            'packs' => $packs,
            'stats' => $stats,
        ];
    }

    /**
     * Rebuilds the scan index. Returns the scan statistics plus the scan id.
     *
     * @return array<string, int|float>
     */
    public function scan(): array
    {
        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $scanTable = $resource->getTableName('translations/scan');
        $sourceTable = $resource->getTableName('translations/source');
        $locationTable = $resource->getTableName('translations/location');
        $packTable = $resource->getTableName('translations/pack');

        $scan = $this->beginScan($write, $scanTable, $sourceTable);

        try {
            $data = $this->collect();
            $this->writeIndex(
                $write,
                $data,
                $scan['scan_id'],
                $scan['started_at'],
                $scan['first_seen_by_hash'],
                $scanTable,
                $sourceTable,
                $locationTable,
                $packTable,
            );
        } catch (Throwable $e) {
            $this->markScanFailed($write, $scanTable, $scan['scan_id'], $e);
            throw $e;
        }

        $this->cleanupScans($write, $scanTable);

        return $data['stats'] + ['scan_id' => $scan['scan_id']];
    }

    /**
     * @return array{scan_id: int, started_at: string, first_seen_by_hash: array<string, mixed>}
     */
    private function beginScan(
        \Maho\Db\Adapter\AdapterInterface $write,
        string $scanTable,
        string $sourceTable,
    ): array {
        $latest = $write->fetchRow(
            $write->select()->from($scanTable)->order('scan_id DESC')->limit(1),
        );

        if ($latest && $latest['status'] === 'running' && strtotime((string) $latest['started_at']) > time() - self::SCAN_TIMEOUT_SECONDS) {
            throw new Mage_Core_Exception('A translation scan is already running.');
        }

        if ($latest && $latest['status'] === 'running') {
            $write->update($scanTable, ['status' => 'failed', 'message' => 'Scan timed out.'], ['scan_id = ?' => $latest['scan_id']]);
        }

        $now = date('Y-m-d H:i:s');
        $write->insert($scanTable, [
            'status' => 'running',
            'started_at' => $now,
            'source_count' => 0,
            'location_count' => 0,
            'pack_count' => 0,
            'skipped_count' => 0,
        ]);
        $scanId = (int) $write->lastInsertId($scanTable, 'scan_id');
        $firstSeenByHash = $write->fetchPairs(
            $write->select()->from($sourceTable, ['source_hash', 'first_seen_at']),
        );

        return [
            'scan_id' => $scanId,
            'started_at' => $now,
            'first_seen_by_hash' => $firstSeenByHash,
        ];
    }

    /**
     * @param array{
     *     sources: array<string, array{text: string, locations: array<int, array<string, mixed>>, modules: array<int, string>, areas: array<int, string>, is_used: bool, length: int, is_supported: bool}>,
     *     packs: array<string, array<string, array{translation: ?string, module: string, file: string, conflict_count: int, alternatives: array<int, string>}>>,
     *     stats: array<string, int|float>
     * } $data
     * @param array<string, mixed> $firstSeenByHash
     */
    private function writeIndex(
        \Maho\Db\Adapter\AdapterInterface $write,
        array $data,
        int $scanId,
        string $now,
        array $firstSeenByHash,
        string $scanTable,
        string $sourceTable,
        string $locationTable,
        string $packTable,
    ): void {
        $write->beginTransaction();

        $write->delete($locationTable);
        $write->delete($packTable);
        $write->delete($sourceTable);

        $this->insertRows($write, $sourceTable, $this->buildSourceRows($data['sources'], $firstSeenByHash, $now));

        $idByHash = $write->fetchPairs(
            $write->select()->from($sourceTable, ['source_hash', 'source_id']),
        );

        $this->insertRows($write, $locationTable, $this->buildLocationRows($data['sources'], $idByHash));
        $this->insertRows($write, $packTable, $this->buildPackRows($data['packs'], $data['sources']));

        $write->update($scanTable, [
            'status' => 'complete',
            'finished_at' => date('Y-m-d H:i:s'),
            'source_count' => $data['stats']['sources'],
            'location_count' => $data['stats']['locations'],
            'pack_count' => $data['stats']['pack_translations'],
            'skipped_count' => $data['stats']['skipped_dynamic'] + $data['stats']['skipped_xml'] + $data['stats']['skipped_files'],
        ], ['scan_id = ?' => $scanId]);

        $write->commit();
    }

    private function markScanFailed(
        \Maho\Db\Adapter\AdapterInterface $write,
        string $scanTable,
        int $scanId,
        Throwable $e,
    ): void {
        $write->rollBack();
        Mage::logException($e);
        $write->update($scanTable, [
            'status' => 'failed',
            'finished_at' => date('Y-m-d H:i:s'),
            'message' => $e->getMessage(),
        ], ['scan_id = ?' => $scanId]);
    }

    /**
     * @param array<int, array<string, int|string|null>> $rows
     */
    private function insertRows(\Maho\Db\Adapter\AdapterInterface $write, string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $write->insertMultiple($table, $chunk);
        }
    }

    /**
     * @param array<string, array{text: string, locations: array<int, array<string, mixed>>, modules: array<int, string>, areas: array<int, string>, is_used: bool, length: int, is_supported: bool}> $sources
     * @param array<string, mixed> $firstSeenByHash
     * @return array<int, array<string, int|string>>
     */
    private function buildSourceRows(array $sources, array $firstSeenByHash, string $now): array
    {
        $rows = [];
        foreach ($sources as $source) {
            $hash = md5($source['text']);
            $rows[] = [
                'source_hash' => $hash,
                'source_crc' => crc32($source['text']),
                'source_text' => $source['text'],
                'source_length' => $source['length'],
                'is_used' => $source['is_used'] ? 1 : 0,
                'is_supported' => $source['is_supported'] ? 1 : 0,
                'modules' => implode(',', $source['modules']),
                'areas' => implode(',', $source['areas']),
                'locations_count' => count($source['locations']),
                'first_seen_at' => $firstSeenByHash[$hash] ?? $now,
                'last_seen_at' => $now,
            ];
        }
        return $rows;
    }

    /**
     * @param array<string, array{text: string, locations: array<int, array<string, mixed>>, modules: array<int, string>, areas: array<int, string>, is_used: bool, length: int, is_supported: bool}> $sources
     * @param array<string, mixed> $idByHash
     * @return array<int, array<string, int|string|null>>
     */
    private function buildLocationRows(array $sources, array $idByHash): array
    {
        $rows = [];
        foreach ($sources as $source) {
            $sourceId = $idByHash[md5($source['text'])] ?? null;
            if ($sourceId === null) {
                continue;
            }
            foreach ($source['locations'] as $location) {
                $rows[] = [
                    'source_id' => $sourceId,
                    'module' => $location['module'],
                    'area' => $location['area'],
                    'file' => $location['file'],
                    'line' => $location['line'],
                    'path' => $location['path'],
                ];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, array<string, array{translation: ?string, module: string, file: string, conflict_count: int, alternatives: array<int, string>}>> $packs
     * @param array<string, array{text: string, locations: array<int, array<string, mixed>>, modules: array<int, string>, areas: array<int, string>, is_used: bool, length: int, is_supported: bool}> $sources
     * @return array<int, array<string, int|string|null>>
     */
    private function buildPackRows(array $packs, array $sources): array
    {
        $textByHash = [];
        foreach ($sources as $source) {
            $textByHash[md5($source['text'])] = $source['text'];
        }

        $rows = [];
        foreach ($packs as $locale => $translations) {
            foreach ($translations as $hash => $pack) {
                $rows[] = [
                    'locale' => $locale,
                    'source_hash' => $hash,
                    'source_text' => $textByHash[$hash] ?? '',
                    'translation' => $pack['translation'],
                    'module' => $pack['module'],
                    'file' => $pack['file'],
                    'conflict_count' => $pack['conflict_count'],
                    'alternatives' => $pack['alternatives'] === [] ? null : implode("\n", $pack['alternatives']),
                ];
            }
        }
        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function getSourceFiles(): array
    {
        $files = [];
        foreach ($this->getScanRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    static function (SplFileInfo $current): bool {
                        if ($current->isDir()) {
                            return !in_array($current->getFilename(), ['.git', 'node_modules', 'var', 'media'], true);
                        }
                        return in_array(strtolower($current->getExtension()), ['php', 'phtml', 'xml'], true)
                            && $current->getSize() <= self::MAX_FILE_SIZE;
                    },
                ),
            );
            foreach ($iterator as $file) {
                $files[] = $file->getPathname();
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function getScanRoots(): array
    {
        if ($this->explicitRoot) {
            return [
                $this->rootDir . '/app/code',
                $this->rootDir . '/app/design',
            ];
        }

        $roots = [];
        foreach (Maho::getInstalledPackages() as $package) {
            foreach (['app/code', 'app/design'] as $subdir) {
                $path = $package['path'] . '/' . $subdir;
                if (is_dir($path)) {
                    $roots[] = $path;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, int|float> $stats
     */
    private function parsePhpFile(string $file, array &$sources, array &$stats): void
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            $stats['skipped_files']++;
            return;
        }

        if (!str_contains($contents, '__')) {
            return;
        }

        $module = $this->moduleFromPath($file);
        $area = $this->areaFromPath($file);
        $relativeFile = $this->relativePath($file);
        $tokens = token_get_all($contents);
        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') {
                continue;
            }

            $open = $this->nextSignificantToken($tokens, $index + 1);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $argument = $this->nextSignificantToken($tokens, $open + 1);
            if ($argument === null) {
                $stats['skipped_dynamic']++;
                continue;
            }

            $argumentToken = $tokens[$argument];
            if (!is_array($argumentToken) || $argumentToken[0] !== T_CONSTANT_ENCAPSED_STRING) {
                $stats['skipped_dynamic']++;
                continue;
            }

            $after = $this->nextSignificantToken($tokens, $argument + 1);
            if ($after === null || !in_array($tokens[$after], [',', ')'], true)) {
                $stats['skipped_dynamic']++;
                continue;
            }

            $this->addSource(
                $sources,
                $this->decodePhpString($argumentToken[1]),
                $relativeFile,
                $argumentToken[2],
                null,
                $module,
                $area,
            );
        }
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function nextSignificantToken(array $tokens, int $start): ?int
    {
        $count = count($tokens);
        for ($index = $start; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $index;
        }
        return null;
    }

    private function decodePhpString(string $literal): string
    {
        if ($literal !== '' && in_array($literal[0], ['b', 'B'], true)
            && isset($literal[1]) && in_array($literal[1], ["'", '"'], true)) {
            $literal = substr($literal, 1);
        }

        $quote = $literal[0] ?? '';
        if (strlen($literal) < 2 || !in_array($quote, ["'", '"'], true)) {
            return $literal;
        }

        $inner = substr($literal, 1, -1);
        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }
        return stripcslashes($inner);
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, int|float> $stats
     */
    private function parseXmlFile(string $file, array &$sources, array &$stats): void
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument();
            $loaded = $document->load($file, LIBXML_NONET | LIBXML_NOBLANKS);
            if (!$loaded) {
                $stats['skipped_xml']++;
                return;
            }

            $module = $this->moduleFromPath($file);
            $area = $this->areaFromPath($file);
            $relativeFile = $this->relativePath($file);
            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//*[@translate]');
            if ($nodes === false) {
                return;
            }

            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $tokens = preg_split('/\s+/', trim($node->getAttribute('translate'))) ?: [];
                foreach ($tokens as $token) {
                    if ($token === '') {
                        continue;
                    }
                    foreach ($node->childNodes as $child) {
                        if (!$child instanceof DOMElement || $child->nodeName !== $token) {
                            continue;
                        }
                        $nodePath = $node->getNodePath();
                        $this->addSource(
                            $sources,
                            $child->textContent,
                            $relativeFile,
                            $child->getLineNo(),
                            $nodePath . '/' . $token,
                            $module,
                            $this->areaFromNodePath($nodePath) ?? $area,
                        );
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * @param array<string, mixed> $sources
     * @param array<string, array<string, array{translation: ?string, module: string, file: string, conflict_count: int, alternatives: array<int, string>}>> $packs
     * @param array<string, int|float> $stats
     */
    private function parseCsvFiles(array &$sources, array &$packs, array &$stats): void
    {
        foreach ($this->getLocaleDirectories() as $directory) {
            $locale = basename($directory);
            foreach (glob($directory . '/*.csv') ?: [] as $file) {
                $module = basename($file, '.csv');
                $relativeFile = $this->relativePath($file);
                $contents = file_get_contents($file);
                if ($contents === false) {
                    $stats['skipped_files']++;
                    continue;
                }
                if (str_starts_with($contents, "\xEF\xBB\xBF")) {
                    $contents = substr($contents, 3);
                }

                $stream = fopen('php://temp', 'r+');
                if ($stream === false) {
                    $stats['skipped_files']++;
                    continue;
                }
                fwrite($stream, $contents);
                rewind($stream);

                while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
                    $text = (string) ($row[0] ?? '');
                    if ($text === '') {
                        continue;
                    }
                    $translation = isset($row[1]) ? (string) $row[1] : null;
                    $this->addSource($sources, $text, null, null, null, $module, '');
                    $hash = md5($text);
                    if (isset($packs[$locale][$hash])) {
                        $stats['pack_conflicts']++;
                        $packs[$locale][$hash]['conflict_count']++;
                        $alternative = $module . ': ' . ($translation ?? '');
                        if (!in_array($alternative, $packs[$locale][$hash]['alternatives'], true)) {
                            $packs[$locale][$hash]['alternatives'][] = $alternative;
                        }
                        continue;
                    }
                    $packs[$locale][$hash] = [
                        'translation' => $translation,
                        'module' => $module,
                        'file' => $relativeFile,
                        'conflict_count' => 0,
                        'alternatives' => [],
                    ];
                }
                fclose($stream);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function getLocaleDirectories(): array
    {
        $directories = [];
        $roots = $this->explicitRoot
            ? [$this->rootDir . '/app/locale']
            : array_map(static fn(array $package): string => $package['path'] . '/app/locale', Maho::getInstalledPackages());

        foreach ($roots as $root) {
            foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $directories[] = $directory;
            }
        }

        $directories = array_values(array_unique($directories));
        sort($directories);
        return $directories;
    }

    /**
     * @param array<string, mixed> $sources
     */
    private function addSource(
        array &$sources,
        string $text,
        ?string $file,
        ?int $line,
        ?string $path,
        string $module,
        string $area,
    ): void {
        if ($text === '') {
            return;
        }

        $hash = md5($text);
        $sources[$hash] ??= [
            'text' => $text,
            'locations' => [],
            'modules' => [],
            'areas' => [],
        ];

        if ($module !== '') {
            $sources[$hash]['modules'][] = $module;
        }
        if ($area !== '') {
            $sources[$hash]['areas'][] = $area;
        }

        if ($file === null) {
            return;
        }

        $key = implode('|', [$module, $area, $file, (string) $line]);
        $sources[$hash]['locations'][$key] = [
            'module' => $module,
            'area' => $area,
            'file' => $file,
            'line' => $line,
            'path' => $path,
        ];
    }

    private function moduleFromPath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        if (preg_match('#/app/code/(?:core|community|local)/([^/]+)/([^/]+)/#', $normalized, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        return '';
    }

    private function areaFromPath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $basename = basename($normalized);

        if (str_contains($normalized, '/app/design/adminhtml/')
            || str_contains($normalized, '/Adminhtml/')
            || $basename === 'adminhtml.xml') {
            return 'adminhtml';
        }
        if (str_contains($normalized, '/app/design/install/')
            || str_contains($normalized, '/Install/')
            || $basename === 'install.xml') {
            return 'install';
        }
        return 'frontend';
    }

    private function areaFromNodePath(string $nodePath): ?string
    {
        if (str_contains($nodePath, '/adminhtml/')) {
            return 'adminhtml';
        }
        if (str_contains($nodePath, '/frontend/')) {
            return 'frontend';
        }
        if (str_contains($nodePath, '/install/')) {
            return 'install';
        }
        return null;
    }

    private function relativePath(string $file): string
    {
        $normalized = str_replace('\\', '/', $file);
        $root = $this->rootDir . '/';
        if (str_starts_with($normalized, $root)) {
            return substr($normalized, strlen($root));
        }
        return $normalized;
    }

    private function cleanupScans(\Maho\Db\Adapter\AdapterInterface $write, string $scanTable): void
    {
        $ids = $write->fetchCol(
            $write->select()->from($scanTable, ['scan_id'])->order('scan_id DESC')->limit(self::MAX_SCAN_ROWS),
        );
        if (count($ids) < self::MAX_SCAN_ROWS) {
            return;
        }
        $write->delete($scanTable, ['scan_id < ?' => (int) end($ids)]);
    }
}
