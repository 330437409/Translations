<?php

/**
 * Creates, reviews and applies AI translation drafts.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Translations
 */

declare(strict_types=1);

class Maho_Translations_Service_AiDraft
{
    public const SYNC_LIMIT = 3;
    public const MAX_BATCH = 100;
    public const RECONCILE_LIMIT = 200;
    public const CONSUMER = 'translations';
    public const ACTION = 'draft';

    private ?Maho_Translations_Model_Ai_Prompt $prompt = null;

    /**
     * @param array<int, int> $sourceIds
     * @param array{module?: string, area?: string, q?: string} $filters
     * @return array{queued: int, generated: int, skipped: int, failed: int, errors: array<int, string>}
     */
    public function requestGeneration(
        array $sourceIds,
        bool $missingOnly,
        string $locale,
        int $storeId,
        array $filters,
        ?int $adminUserId,
    ): array {
        $this->assertAiReady($storeId);
        $sources = $this->getSourcesForGeneration($sourceIds, $missingOnly, $locale, $storeId, $filters);
        $this->assertBatchAllowed(count($sources), $storeId);

        $sync = count($sources) <= self::SYNC_LIMIT;
        $result = [
            'queued' => 0,
            'generated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($sources as $source) {
            if (trim((string) ($source['source_text'] ?? '')) === '') {
                $result['skipped']++;
                continue;
            }

            $draftId = null;
            try {
                $draftId = $this->prepareDraft($source, $locale, $storeId, $adminUserId);
                if ($sync) {
                    $response = $this->invokeAi($source, $locale, $storeId);
                    if ($this->applyResponse($draftId, $response, null, null)) {
                        $result['generated']++;
                    } else {
                        $result['failed']++;
                        $result['errors'][] = 'AI returned an empty translation.';
                    }
                } else {
                    $taskId = $this->submitTask($draftId, $source, $locale, $storeId);
                    $this->resource()->setTaskId($draftId, $taskId);
                    $result['queued']++;
                }
            } catch (Throwable $e) {
                if ($draftId !== null) {
                    $this->markFailed($draftId, $e->getMessage());
                }
                $result['failed']++;
                $result['errors'][] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param array{module?: string, area?: string, q?: string} $filters
     */
    public function countMissing(string $locale, int $storeId, array $filters): int
    {
        $collection = Mage::getResourceModel('translations/source_collection');
        $collection->setTranslationScope($locale, $storeId);
        $collection->addTranslationFilters(['status' => 'missing'] + $filters);
        return (int) $collection->getSize();
    }

    public function saveDraft(int $draftId, ?string $translation): void
    {
        $draft = $this->getDraft($draftId);
        if (!$draft) {
            throw new Mage_Core_Exception('The AI draft was not found.');
        }
        if ($draft['status'] !== Maho_Translations_Model_Draft::STATUS_PENDING) {
            throw new Mage_Core_Exception('Only pending AI drafts can be edited.');
        }

        $text = $translation ?? '';
        $errors = $this->validateTranslation((string) $draft['source_text'], $text);
        $updated = $this->resource()->updatePending($draftId, [
            'draft_translation' => $text,
            'error_message' => $errors === [] ? null : implode(' ', $errors),
            'updated_at' => $this->now(),
        ]);
        if ($updated !== 1) {
            throw new Mage_Core_Exception('The AI draft changed. Reload and try again.');
        }
    }

    public function approveDraft(int $draftId, int $adminUserId): void
    {
        $draft = $this->getDraft($draftId);
        if (!$draft) {
            throw new Mage_Core_Exception('The AI draft was not found.');
        }
        if ($draft['status'] !== Maho_Translations_Model_Draft::STATUS_PENDING) {
            throw new Mage_Core_Exception('Only pending AI drafts can be approved.');
        }

        $translation = (string) ($draft['draft_translation'] ?? '');
        $errors = $this->validateTranslation((string) $draft['source_text'], $translation);
        if ($errors !== []) {
            $this->resource()->updatePending($draftId, [
                'error_message' => implode(' ', $errors),
                'updated_at' => $this->now(),
            ]);
            throw new Mage_Core_Exception(implode(' ', $errors));
        }

        $source = [
            'source_text' => (string) $draft['source_text'],
            'source_hash' => (string) $draft['source_hash'],
        ];
        $now = $this->now();
        $write = Mage::getSingleton('core/resource')->getConnection('core_write');
        $write->beginTransaction();

        try {
            Mage::getModel('translations/catalog')->saveTranslation(
                $source,
                $translation,
                (string) $draft['locale'],
                (int) $draft['store_id'],
            );
            $updated = $this->resource()->updatePending($draftId, [
                'status' => Maho_Translations_Model_Draft::STATUS_APPROVED,
                'reviewed_by' => $adminUserId,
                'reviewed_at' => $now,
                'error_message' => null,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new Mage_Core_Exception('The AI draft changed while it was being approved. Reload and try again.');
            }
            $write->commit();
        } catch (Throwable $e) {
            $write->rollBack();
            throw $e;
        }
    }

    public function rejectDraft(int $draftId, int $adminUserId): void
    {
        $updated = $this->resource()->updatePending($draftId, [
            'status' => Maho_Translations_Model_Draft::STATUS_REJECTED,
            'reviewed_by' => $adminUserId,
            'reviewed_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        if ($updated !== 1) {
            throw new Mage_Core_Exception('Only pending AI drafts can be rejected.');
        }
    }

    /**
     * @param array<int, int> $draftIds
     * @return array{succeeded: int, failed: int, errors: array<int, string>}
     */
    public function approveDrafts(array $draftIds, int $adminUserId): array
    {
        return $this->reviewDrafts($draftIds, $adminUserId, true);
    }

    /**
     * @param array<int, int> $draftIds
     * @return array{succeeded: int, failed: int, errors: array<int, string>}
     */
    public function rejectDrafts(array $draftIds, int $adminUserId): array
    {
        return $this->reviewDrafts($draftIds, $adminUserId, false);
    }

    /**
     * @param array<int, int> $sourceIds
     * @return array{succeeded: int, failed: int, errors: array<int, string>}
     */
    public function approveBySourceIds(array $sourceIds, string $locale, int $storeId, int $adminUserId): array
    {
        return $this->approveDrafts($this->draftIdsBySourceIds($sourceIds, $locale, $storeId), $adminUserId);
    }

    /**
     * @param array<int, int> $sourceIds
     * @return array{succeeded: int, failed: int, errors: array<int, string>}
     */
    public function rejectBySourceIds(array $sourceIds, string $locale, int $storeId, int $adminUserId): array
    {
        return $this->rejectDrafts($this->draftIdsBySourceIds($sourceIds, $locale, $storeId), $adminUserId);
    }

    public function applyResponse(int $draftId, string $response, ?string $platform, ?string $model): bool
    {
        $draft = $this->getDraft($draftId);
        if (!$draft || $draft['status'] !== Maho_Translations_Model_Draft::STATUS_PENDING) {
            return false;
        }
        if (trim((string) ($draft['draft_translation'] ?? '')) !== '') {
            return true;
        }

        $response = trim($response);
        if ($response === '') {
            $this->markFailed($draftId, 'AI returned an empty translation.');
            return false;
        }

        $errors = $this->validateTranslation((string) $draft['source_text'], $response);
        $updated = $this->resource()->updateEmptyPending($draftId, [
            'draft_translation' => $response,
            'error_message' => $errors === [] ? null : implode(' ', $errors),
            'platform' => $platform,
            'model' => $model,
            'updated_at' => $this->now(),
        ]);

        if ($updated === 1) {
            return true;
        }
        $current = $this->getDraft($draftId);
        return $current !== false && trim((string) ($current['draft_translation'] ?? '')) !== '';
    }

    public function applyTaskResponse(Maho_Ai_Model_Task $task, string $response): void
    {
        $draftId = (int) ($task->getContextArray()['draft_id'] ?? 0);
        if ($draftId <= 0) {
            return;
        }
        $draft = $this->getDraft($draftId);
        if (!$draft || (int) ($draft['task_id'] ?? 0) !== (int) $task->getId()) {
            return;
        }
        $this->applyResponse(
            $draftId,
            $response,
            $task->getData('platform') !== null ? (string) $task->getData('platform') : null,
            $task->getData('model') !== null ? (string) $task->getData('model') : null,
        );
    }

    public function markFailed(int $draftId, string $error): void
    {
        $this->resource()->updateEmptyPending($draftId, [
            'status' => Maho_Translations_Model_Draft::STATUS_FAILED,
            'error_message' => $error,
            'updated_at' => $this->now(),
        ]);
    }

    public function reconcile(): void
    {
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $taskTable = Mage::getSingleton('core/resource')->getTableName('ai/task');
        $cutoff = Mage::app()->getLocale()->formatDateForDb('-7 days');

        $tasks = $read->fetchAll(
            $read->select()
                ->from($taskTable)
                ->where('consumer = ?', self::CONSUMER)
                ->where('action = ?', self::ACTION)
                ->where('created_at >= ?', $cutoff)
                ->where('status IN (?)', [
                    Maho_Ai_Model_Task::STATUS_COMPLETE,
                    Maho_Ai_Model_Task::STATUS_FAILED,
                    Maho_Ai_Model_Task::STATUS_CANCELLED,
                ])
                ->order('task_id ASC')
                ->limit(self::RECONCILE_LIMIT),
        );

        foreach ($tasks as $task) {
            $context = [];
            $rawContext = (string) ($task['context'] ?? '');
            if ($rawContext !== '') {
                try {
                    $context = Mage::helper('core')->jsonDecode($rawContext);
                } catch (\JsonException) {
                    continue;
                }
            }
            $draftId = (int) (is_array($context) ? ($context['draft_id'] ?? 0) : 0);
            if ($draftId <= 0) {
                continue;
            }

            $draft = $this->getDraft($draftId);
            if (!$draft
                || $draft['status'] !== Maho_Translations_Model_Draft::STATUS_PENDING
                || (int) ($draft['task_id'] ?? 0) !== (int) $task['task_id']) {
                continue;
            }

            $response = trim((string) ($task['response'] ?? ''));
            $taskStatus = (string) $task['status'];
            $platform = ($task['platform'] ?? null) !== null ? (string) $task['platform'] : null;
            $model = ($task['model'] ?? null) !== null ? (string) $task['model'] : null;

            if ($response !== '') {
                $this->applyResponse($draftId, $response, $platform, $model);
                continue;
            }

            $error = trim((string) ($task['error_message'] ?? ''));
            $this->markFailed($draftId, $error !== '' ? $error : 'The AI translation task ' . $taskStatus . '.');
        }
    }

    /**
     * @param array<int, int> $sourceIds
     * @param array{module?: string, area?: string, q?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    private function getSourcesForGeneration(
        array $sourceIds,
        bool $missingOnly,
        string $locale,
        int $storeId,
        array $filters,
    ): array {
        $collection = Mage::getResourceModel('translations/source_collection');
        $collection->setTranslationScope($locale, $storeId);

        if ($missingOnly) {
            $collection->addTranslationFilters(['status' => 'missing'] + $filters);
        } else {
            $sourceIds = array_values(array_unique(array_filter(array_map(intval(...), $sourceIds), static fn(int $id): bool => $id > 0)));
            if ($sourceIds === []) {
                throw new Mage_Core_Exception('Select at least one translation.');
            }
            $collection->addFieldToFilter('main_table.source_id', ['in' => $sourceIds]);
        }

        $collection->setPageSize(self::MAX_BATCH + 1);
        $sources = [];
        foreach ($collection as $source) {
            $sources[] = $source->getData();
        }
        if (count($sources) > self::MAX_BATCH) {
            throw new Mage_Core_Exception(
                sprintf('Select at most %d translations for one AI draft request.', self::MAX_BATCH),
            );
        }

        return $sources;
    }

    protected function assertAiReady(int $storeId): void
    {
        if (!Mage::helper('ai')->isEnabled($storeId)) {
            throw new Mage_Core_Exception('Maho AI is disabled. Enable it in System > Configuration > AI.');
        }

        $platform = (string) Mage::getStoreConfig('ai/general/default_platform', $storeId);
        if ($platform === '') {
            throw new Mage_Core_Exception('Select a default AI platform in System > Configuration > AI.');
        }
        $model = (string) Mage::getStoreConfig('ai/general/' . $platform . '_model', $storeId);
        if ($model === '') {
            throw new Mage_Core_Exception('Select a default AI model in System > Configuration > AI.');
        }
    }

    protected function assertBatchAllowed(int $count, int $storeId): void
    {
        if ($count === 0) {
            throw new Mage_Core_Exception('No translations match the current filter.');
        }
        if ($count > self::MAX_BATCH) {
            throw new Mage_Core_Exception(
                sprintf('Select at most %d translations for one AI draft request.', self::MAX_BATCH),
            );
        }

        if ($count > self::SYNC_LIMIT && !Mage::getStoreConfigFlag('ai/queue/enabled', $storeId)) {
            throw new Mage_Core_Exception(
                'Enable the AI queue and cron before generating more than ' . self::SYNC_LIMIT . ' drafts at once.',
            );
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function prepareDraft(array $source, string $locale, int $storeId, ?int $adminUserId): int
    {
        $sourceText = (string) $source['source_text'];
        $base = Mage::getModel('translations/catalog')->getEffectiveTranslation($source, $locale, $storeId);
        $now = $this->now();

        return $this->resource()->upsertForScope([
            'task_id' => null,
            'source_hash' => (string) $source['source_hash'],
            'source_text' => $sourceText,
            'locale' => $locale,
            'store_id' => $storeId,
            'base_translation' => $base === $sourceText ? null : $base,
            'draft_translation' => null,
            'status' => Maho_Translations_Model_Draft::STATUS_PENDING,
            'platform' => null,
            'model' => null,
            'error_message' => null,
            'requested_by' => $adminUserId,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $source
     */
    protected function invokeAi(array $source, string $locale, int $storeId): string
    {
        $prompt = $this->getPrompt()->build($source, $locale, $storeId);
        return Mage::helper('ai')->invoke(
            userMessage: $prompt['user'],
            systemPrompt: $prompt['system'],
            storeId: $storeId,
            consumer: self::CONSUMER,
        );
    }

    /**
     * @param array<string, mixed> $source
     */
    protected function submitTask(int $draftId, array $source, string $locale, int $storeId): int
    {
        $prompt = $this->getPrompt()->build($source, $locale, $storeId);
        return Mage::helper('ai')->submitTask([
            'consumer' => self::CONSUMER,
            'action' => self::ACTION,
            'system_prompt' => $prompt['system'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'context' => ['draft_id' => $draftId],
            'callback_class' => 'Maho_Translations_Model_Ai_Callback',
            'callback_method' => 'onComplete',
            'store_id' => $storeId,
            'priority' => Maho_Ai_Model_Task::PRIORITY_BACKGROUND,
        ]);
    }

    /**
     * @param array<int, int> $draftIds
     * @return array{succeeded: int, failed: int, errors: array<int, string>}
     */
    private function reviewDrafts(array $draftIds, int $adminUserId, bool $approve): array
    {
        $draftIds = array_values(array_unique(array_filter(array_map(intval(...), $draftIds), static fn(int $id): bool => $id > 0)));
        if ($draftIds === []) {
            throw new Mage_Core_Exception('Select at least one AI draft.');
        }
        if (count($draftIds) > self::MAX_BATCH) {
            throw new Mage_Core_Exception(sprintf('Select at most %d AI drafts.', self::MAX_BATCH));
        }

        $result = ['succeeded' => 0, 'failed' => 0, 'errors' => []];
        foreach ($draftIds as $draftId) {
            try {
                if ($approve) {
                    $this->approveDraft($draftId, $adminUserId);
                } else {
                    $this->rejectDraft($draftId, $adminUserId);
                }
                $result['succeeded']++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param array<int, int> $sourceIds
     * @return array<int, int>
     */
    private function draftIdsBySourceIds(array $sourceIds, string $locale, int $storeId): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map(intval(...), $sourceIds), static fn(int $id): bool => $id > 0)));
        if ($sourceIds === []) {
            throw new Mage_Core_Exception('Select at least one translation.');
        }

        return $this->resource()->getDraftIdsBySourceIds($sourceIds, $locale, $storeId);
    }

    /**
     * @return array<string, mixed>|false
     */
    private function getDraft(int $draftId): array|false
    {
        /** @var Maho_Translations_Model_Draft $draft */
        $draft = Mage::getModel('translations/draft')->load($draftId);
        return $draft->getId() ? $draft->getData() : false;
    }

    /**
     * @return array<int, string>
     */
    private function validateTranslation(string $sourceText, string $translation): array
    {
        if (mb_strlen($sourceText, 'UTF-8') > 65535) {
            return ['This source is longer than 65535 characters and cannot be edited.'];
        }
        if (trim($translation) === '') {
            return ['The translation is empty.'];
        }
        if (mb_strlen($translation, 'UTF-8') > 65535) {
            return ['The translation is longer than 65535 characters.'];
        }

        return new Maho_Translations_Model_Validator_Placeholder()->validate($sourceText, $translation);
    }

    private function getPrompt(): Maho_Translations_Model_Ai_Prompt
    {
        return $this->prompt ??= new Maho_Translations_Model_Ai_Prompt();
    }

    private function resource(): Maho_Translations_Model_Resource_Draft
    {
        /** @var Maho_Translations_Model_Resource_Draft $resource */
        $resource = Mage::getResourceModel('translations/draft');
        return $resource;
    }

    private function now(): string
    {
        return Mage::app()->getLocale()->formatDateForDb('now');
    }
}
