<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 */

declare(strict_types=1);

use Tests\MahoBackendTestCase;

uses(MahoBackendTestCase::class);

function aiDraftTable(string $entity): string
{
    return Mage::getSingleton('core/resource')->getTableName('translations/' . $entity);
}

function aiDraftCleanup(): void
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->delete(aiDraftTable('draft'));
    $write->delete(aiDraftTable('long'));
    $write->delete(Mage::getSingleton('core/resource')->getTableName('ai/task'), [
        'consumer = ?' => Maho_Translations_Service_AiDraft::CONSUMER,
    ]);
    $write->delete(Mage::getSingleton('core/resource')->getTableName('core/translate'), [
        'locale = ?' => 'zz_ZZ',
        'string IN (?)' => ['Welcome', 'Home page', 'Hello %s', 'Add to Cart', 'Unused string'],
    ]);
}

function aiDraftSource(string $text): array
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $row = $read->fetchRow(
        $read->select()->from(aiDraftTable('source'))->where('source_text = ?', $text),
    );
    expect($row)->not->toBeFalse();
    return $row;
}

function aiDraftGet(string $hash, string $locale = 'zz_ZZ', int $storeId = 0): array|false
{
    return Mage::getResourceModel('translations/draft')->getByScope($hash, $locale, $storeId);
}

function aiDraftInsertLongSource(string $text): array
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $write->insert(aiDraftTable('source'), [
        'source_hash' => md5($text),
        'source_crc' => crc32($text),
        'source_text' => $text,
        'source_length' => mb_strlen($text, 'UTF-8'),
        'is_used' => 1,
        'is_supported' => 0,
        'modules' => 'Acme_Shop',
        'areas' => 'frontend',
        'locations_count' => 0,
    ]);
    return aiDraftSource($text);
}

function aiDraftLongStored(string $text): mixed
{
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    return $read->fetchOne(
        $read->select()
            ->from(aiDraftTable('long'), ['translation'])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', 0)
            ->where('source_hash = ?', md5($text)),
    );
}

function aiDraftFakeService(string $response = 'Benvenuto', int $taskId = 7000): Maho_Translations_Service_AiDraft
{
    return new class ($response, $taskId) extends Maho_Translations_Service_AiDraft {
        public function __construct(
            private string $fakeResponse,
            private int $fakeTaskId,
        ) {}

        protected function assertAiReady(int $storeId): void {}

        protected function assertBatchAllowed(int $count, int $storeId): void {}

        protected function invokeAi(array $source, string $locale, int $storeId): string
        {
            return $this->fakeResponse;
        }

        protected function submitTask(int $draftId, array $source, string $locale, int $storeId): int
        {
            return $this->fakeTaskId + $draftId;
        }
    };
}

beforeEach(function () {
    aiDraftCleanup();
    (new Maho_Translations_Model_Scanner(dirname(__DIR__, 2) . '/Unit/Translations/fixtures'))->scan();
});

afterEach(function () {
    aiDraftCleanup();
});

it('generates a synchronous draft for a missing translation', function () {
    $source = aiDraftSource('Welcome');
    $result = aiDraftFakeService('Benvenuto')->requestGeneration(
        [(int) $source['source_id']],
        false,
        'zz_ZZ',
        0,
        [],
        1,
    );

    $draft = aiDraftGet((string) $source['source_hash']);

    expect($result['generated'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($draft)->not->toBeFalse()
        ->and($draft['status'])->toBe(Maho_Translations_Model_Draft::STATUS_PENDING)
        ->and($draft['draft_translation'])->toBe('Benvenuto')
        ->and($draft['base_translation'])->toBeNull()
        ->and($draft['task_id'])->toBeNull();
});

it('queues more than three drafts and records the task id', function () {
    $sourceIds = [];
    foreach (['Welcome', 'Unused string', 'Home page', 'Add to Cart'] as $text) {
        $sourceIds[] = (int) aiDraftSource($text)['source_id'];
    }

    $result = aiDraftFakeService('Ignored')->requestGeneration(
        $sourceIds,
        false,
        'zz_ZZ',
        0,
        [],
        1,
    );

    $drafts = Mage::getResourceModel('translations/draft_collection')->load();

    expect($result['queued'])->toBe(4)
        ->and($result['generated'])->toBe(0)
        ->and($drafts->getSize())->toBe(4);

    foreach ($drafts as $draft) {
        expect($draft->getData('status'))->toBe(Maho_Translations_Model_Draft::STATUS_PENDING)
            ->and((int) $draft->getData('task_id'))->toBeGreaterThan(7000)
            ->and($draft->getData('draft_translation'))->toBeNull();
    }
});

it('approves a draft and writes core_translate', function () {
    $source = aiDraftSource('Welcome');
    aiDraftFakeService('Benvenuto')->requestGeneration([(int) $source['source_id']], false, 'zz_ZZ', 0, [], 1);
    $draft = aiDraftGet((string) $source['source_hash']);

    Mage::getService('translations/aiDraft')->approveDraft((int) $draft['draft_id'], 1);

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $translation = $write->fetchOne(
        $write->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('core/translate'), ['translate'])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', 0)
            ->where('string = ?', 'Welcome'),
    );

    expect($translation)->toBe('Benvenuto')
        ->and(aiDraftGet((string) $source['source_hash'])['status'])->toBe(Maho_Translations_Model_Draft::STATUS_APPROVED);
});

it('rejects a draft without changing core_translate', function () {
    $source = aiDraftSource('Welcome');
    aiDraftFakeService('Benvenuto')->requestGeneration([(int) $source['source_id']], false, 'zz_ZZ', 0, [], 1);
    $draft = aiDraftGet((string) $source['source_hash']);

    Mage::getService('translations/aiDraft')->rejectDraft((int) $draft['draft_id'], 1);

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $count = $write->fetchOne(
        $write->select()
            ->from(Mage::getSingleton('core/resource')->getTableName('core/translate'), ['COUNT(*)'])
            ->where('locale = ?', 'zz_ZZ')
            ->where('store_id = ?', 0)
            ->where('string = ?', 'Welcome'),
    );

    expect((int) $count)->toBe(0)
        ->and(aiDraftGet((string) $source['source_hash'])['status'])->toBe(Maho_Translations_Model_Draft::STATUS_REJECTED);
});

it('keeps the current translation until an existing translation draft is approved', function () {
    $source = aiDraftSource('Home page');
    aiDraftFakeService('Pagina casa AI')->requestGeneration([(int) $source['source_id']], false, 'zz_ZZ', 0, [], 1);
    $draft = aiDraftGet((string) $source['source_hash']);

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $table = Mage::getSingleton('core/resource')->getTableName('core/translate');
    $before = $write->fetchOne(
        $write->select()->from($table, ['COUNT(*)'])->where('locale = ?', 'zz_ZZ')->where('string = ?', 'Home page'),
    );

    expect($before)->toBe(0)
        ->and($draft['base_translation'])->toBe('Pagina iniziale');

    Mage::getService('translations/aiDraft')->approveDraft((int) $draft['draft_id'], 1);

    $after = $write->fetchOne(
        $write->select()->from($table, ['translate'])->where('locale = ?', 'zz_ZZ')->where('string = ?', 'Home page'),
    );

    expect($after)->toBe('Pagina casa AI');
});

it('blocks approval when placeholders do not match', function () {
    $source = aiDraftSource('Hello %s');
    aiDraftFakeService('Ciao')->requestGeneration([(int) $source['source_id']], false, 'zz_ZZ', 0, [], 1);
    $draft = aiDraftGet((string) $source['source_hash']);

    expect(fn() => Mage::getService('translations/aiDraft')->approveDraft((int) $draft['draft_id'], 1))
        ->toThrow(Mage_Core_Exception::class, 'Placeholder mismatch');

    $updated = aiDraftGet((string) $source['source_hash']);
    expect($updated['status'])->toBe(Maho_Translations_Model_Draft::STATUS_PENDING)
        ->and((string) $updated['error_message'])->toContain('Placeholder mismatch');
});

it('approves valid drafts and leaves invalid drafts pending in a batch', function () {
    $welcome = aiDraftSource('Welcome');
    $hello = aiDraftSource('Hello %s');
    aiDraftFakeService('Benvenuto')->requestGeneration([(int) $welcome['source_id']], false, 'zz_ZZ', 0, [], 1);
    aiDraftFakeService('Ciao')->requestGeneration([(int) $hello['source_id']], false, 'zz_ZZ', 0, [], 1);

    $valid = aiDraftGet((string) $welcome['source_hash']);
    $invalid = aiDraftGet((string) $hello['source_hash']);
    $result = Mage::getService('translations/aiDraft')->approveDrafts([
        (int) $valid['draft_id'],
        (int) $invalid['draft_id'],
    ], 1);

    expect($result['succeeded'])->toBe(1)
        ->and($result['failed'])->toBe(1)
        ->and(aiDraftGet((string) $welcome['source_hash'])['status'])->toBe(Maho_Translations_Model_Draft::STATUS_APPROVED)
        ->and(aiDraftGet((string) $hello['source_hash'])['status'])->toBe(Maho_Translations_Model_Draft::STATUS_PENDING);
});

it('approves drafts by source ids for grid batch actions', function () {
    $welcome = aiDraftSource('Welcome');
    $home = aiDraftSource('Home page');
    aiDraftFakeService('Benvenuto')->requestGeneration([(int) $welcome['source_id']], false, 'zz_ZZ', 0, [], 1);
    aiDraftFakeService('Pagina casa AI')->requestGeneration([(int) $home['source_id']], false, 'zz_ZZ', 0, [], 1);

    $result = Mage::getService('translations/aiDraft')->approveBySourceIds(
        [(int) $welcome['source_id'], (int) $home['source_id']],
        'zz_ZZ',
        0,
        1,
    );

    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $table = Mage::getSingleton('core/resource')->getTableName('core/translate');
    $translations = $write->fetchPairs(
        $write->select()->from($table, ['string', 'translate'])->where('locale = ?', 'zz_ZZ')->where('store_id = ?', 0),
    );

    expect($result['succeeded'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($translations['Welcome'])->toBe('Benvenuto')
        ->and($translations['Home page'])->toBe('Pagina casa AI');
});

it('saves an edited draft and records validation errors', function () {
    $source = aiDraftSource('Hello %s');
    aiDraftFakeService('Ciao')->requestGeneration([(int) $source['source_id']], false, 'zz_ZZ', 0, [], 1);
    $draft = aiDraftGet((string) $source['source_hash']);

    Mage::getService('translations/aiDraft')->saveDraft((int) $draft['draft_id'], 'Ciao %s');

    $updated = aiDraftGet((string) $source['source_hash']);
    expect($updated['draft_translation'])->toBe('Ciao %s')
        ->and($updated['error_message'])->toBeNull();
});

it('generates and approves a long translation', function () {
    $source = str_repeat('Long source string ', 20);
    $translation = rtrim(str_repeat('Traduzione lunga ', 20));
    $row = aiDraftInsertLongSource($source);

    $result = aiDraftFakeService($translation)->requestGeneration(
        [(int) $row['source_id']],
        false,
        'zz_ZZ',
        0,
        [],
        1,
    );
    $draft = aiDraftGet((string) $row['source_hash']);

    expect($result['generated'])->toBe(1)
        ->and($draft['draft_translation'])->toBe($translation);

    Mage::getService('translations/aiDraft')->approveDraft((int) $draft['draft_id'], 1);

    expect(aiDraftLongStored($source))->toBe($translation)
        ->and(aiDraftGet((string) $row['source_hash'])['status'])->toBe(Maho_Translations_Model_Draft::STATUS_APPROVED);
});

it('applies a completed async task through reconcile', function () {
    $source = aiDraftSource('Welcome');
    $resource = Mage::getResourceModel('translations/draft');
    $draftId = $resource->upsertForScope(aiDraftRow($source, null));
    $taskId = aiDraftInsertTask((int) $source['source_id'], $draftId, 'complete', 'Benvenuto');
    $resource->setTaskId($draftId, $taskId);

    Mage::getService('translations/aiDraft')->reconcile();

    $draft = aiDraftGet((string) $source['source_hash']);
    expect($draft['draft_translation'])->toBe('Benvenuto')
        ->and($draft['status'])->toBe(Maho_Translations_Model_Draft::STATUS_PENDING);
});

it('marks an async draft failed when its task fails', function () {
    $source = aiDraftSource('Welcome');
    $resource = Mage::getResourceModel('translations/draft');
    $draftId = $resource->upsertForScope(aiDraftRow($source, null));
    $taskId = aiDraftInsertTask((int) $source['source_id'], $draftId, 'failed', null, 'Rate limited');
    $resource->setTaskId($draftId, $taskId);

    Mage::getService('translations/aiDraft')->reconcile();

    $draft = aiDraftGet((string) $source['source_hash']);
    expect($draft['status'])->toBe(Maho_Translations_Model_Draft::STATUS_FAILED)
        ->and((string) $draft['error_message'])->toContain('Rate limited');
});

/**
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function aiDraftRow(array $source, ?int $taskId): array
{
    $now = Mage::app()->getLocale()->formatDateForDb('now');
    return [
        'task_id' => $taskId,
        'source_hash' => (string) $source['source_hash'],
        'source_text' => (string) $source['source_text'],
        'locale' => 'zz_ZZ',
        'store_id' => 0,
        'base_translation' => null,
        'draft_translation' => null,
        'status' => Maho_Translations_Model_Draft::STATUS_PENDING,
        'platform' => null,
        'model' => null,
        'error_message' => null,
        'requested_by' => 1,
        'reviewed_by' => null,
        'reviewed_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ];
}

function aiDraftInsertTask(int $sourceId, int $draftId, string $status, ?string $response, ?string $error = null): int
{
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $table = Mage::getSingleton('core/resource')->getTableName('ai/task');
    $write->insert($table, [
        'consumer' => Maho_Translations_Service_AiDraft::CONSUMER,
        'action' => Maho_Translations_Service_AiDraft::ACTION,
        'task_type' => Maho_Ai_Model_Task::TYPE_COMPLETION,
        'status' => $status,
        'context' => Mage::helper('core')->jsonEncode(['draft_id' => $draftId, 'source_id' => $sourceId]),
        'response' => $response,
        'error_message' => $error,
        'platform' => 'openai',
        'model' => 'gpt-test',
        'created_at' => Mage::app()->getLocale()->formatDateForDb('now'),
    ]);

    return (int) $write->lastInsertId($table, 'task_id');
}
