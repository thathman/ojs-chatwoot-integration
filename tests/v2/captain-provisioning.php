<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainDocumentProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;

function captainProvisioningCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemorySyncRepository implements SupportKnowledgeSyncRepositoryInterface
{
    /** @var array<string,CaptainSyncState> */
    public array $states = [];

    public function find(int $contextId, string $locale, string $resourceType, string $resourceKey = ''): ?CaptainSyncState
    {
        return $this->states["{$contextId}:{$locale}:{$resourceType}:{$resourceKey}"] ?? null;
    }

    public function save(CaptainSyncState $state): void
    {
        $this->states["{$state->contextId()}:{$state->locale()}:{$state->resourceType()}:{$state->resourceKey()}"] = $state;
    }
}

final class FakeCaptainClient implements ChatwootCaptainClientInterface
{
    public ?array $existingDocument = null;
    public ?array $createResult = null;
    public bool $syncResult = true;
    public int $createCalls = 0;
    public int $syncCalls = 0;
    public ?string $lastSyncedDocumentId = null;

    public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array
    {
        return $this->existingDocument;
    }

    public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array
    {
        $this->createCalls++;
        return $this->createResult;
    }

    public function syncCaptainDocument(string $documentId): bool
    {
        $this->syncCalls++;
        $this->lastSyncedDocumentId = $documentId;
        return $this->syncResult;
    }

    public function listCaptainCustomTools(): array
    {
        return [];
    }

    public function createCaptainCustomTool(array $definition): ?array
    {
        return null;
    }

    public function updateCaptainCustomTool(string $toolId, array $definition): bool
    {
        return false;
    }

    public function listCaptainScenarios(int $assistantId): array
    {
        return [];
    }

    public function createCaptainScenario(int $assistantId, array $definition): ?array
    {
        return null;
    }

    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool
    {
        return false;
    }

    public function listCaptainAssistantResponses(int $assistantId): array
    {
        return [];
    }
}

function makeCompilation(int $contextId, string $locale, string $fingerprint): KnowledgeCompilation
{
    $facts = [new KnowledgeFact('journal.name', 'Journal', KnowledgeClassification::PUBLIC, 'ojs.context', $locale, 'test')];
    return new KnowledgeCompilation($contextId, $locale, $facts, $fingerprint, 1_800_000_000);
}

// ================================================================
// Part 1: fresh provisioning — no local record, no remote conflict.
// ================================================================
$repo = new InMemorySyncRepository();
$client = new FakeCaptainClient();
$client->createResult = ['id' => 501];
$provisioner = new CaptainDocumentProvisioner($client, $repo);

$compilationA = makeCompilation(7, 'en', 'fp-1');
$result = $provisioner->provision($compilationA, 42, 'Journal A Support Knowledge', 'https://example.test/journal-a/support-knowledge', 1_800_000_000);
captainProvisioningCheck($result->status() === CaptainSyncResult::STATUS_CREATED, 'first provisioning with no conflict must create a new document');
captainProvisioningCheck($client->createCalls === 1, 'exactly one create call must be made');
captainProvisioningCheck($client->syncCalls === 0, 'a fresh create must never also call sync');

$state = $repo->find(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT);
captainProvisioningCheck($state !== null && $state->remoteResourceId() === '501', 'ownership record must store the real remote document id');
captainProvisioningCheck($state->lastSuccessfulFingerprint() === 'fp-1', 'ownership record must store the fingerprint at creation time');

// ================================================================
// Part 2: unchanged fingerprint -> no-op, never calls the API again.
// ================================================================
$noopResult = $provisioner->provision(makeCompilation(7, 'en', 'fp-1'), 42, 'Journal A Support Knowledge', 'https://example.test/journal-a/support-knowledge', 1_800_000_100);
captainProvisioningCheck($noopResult->status() === CaptainSyncResult::STATUS_NOOP, 'an unchanged fingerprint must be a no-op');
captainProvisioningCheck($client->createCalls === 1 && $client->syncCalls === 0, 'a no-op must never call create or sync');

// ================================================================
// Part 3: changed fingerprint -> sync (re-crawl), never create again.
// ================================================================
$syncResult = $provisioner->provision(makeCompilation(7, 'en', 'fp-2'), 42, 'Journal A Support Knowledge', 'https://example.test/journal-a/support-knowledge', 1_800_000_200);
captainProvisioningCheck($syncResult->status() === CaptainSyncResult::STATUS_SYNCED, 'a changed fingerprint on an owned document must sync, not create a duplicate');
captainProvisioningCheck($client->createCalls === 1, 'sync path must never call create again');
captainProvisioningCheck($client->syncCalls === 1 && $client->lastSyncedDocumentId === '501', 'sync must target the exact owned remote document id');
captainProvisioningCheck($repo->find(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT)?->lastSuccessfulFingerprint() === 'fp-2', 'ownership record must advance to the new fingerprint after a successful sync');

// ================================================================
// Part 4: sync failure -> failed status, ownership preserved, error recorded.
// ================================================================
$client->syncResult = false;
$failedSyncResult = $provisioner->provision(makeCompilation(7, 'en', 'fp-3'), 42, 'Journal A Support Knowledge', 'https://example.test/journal-a/support-knowledge', 1_800_000_300);
captainProvisioningCheck($failedSyncResult->status() === CaptainSyncResult::STATUS_FAILED, 'a failed sync call must report failed');
captainProvisioningCheck($failedSyncResult->reasonCode() === 'sync_failed', 'a failed sync must carry the sync_failed reason code');
$stateAfterFailure = $repo->find(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT);
captainProvisioningCheck($stateAfterFailure?->remoteResourceId() === '501', 'a failed sync must never discard the existing ownership record');
captainProvisioningCheck($stateAfterFailure?->lastSuccessfulFingerprint() === 'fp-2', 'a failed sync must never advance the recorded fingerprint');
captainProvisioningCheck($stateAfterFailure?->lastErrorCode() === 'sync_failed', 'the failure reason must be recorded for health reporting');

// ================================================================
// Part 5: unmanaged remote document already exists -> conflict, never adopted/duplicated.
// ================================================================
$conflictRepo = new InMemorySyncRepository();
$conflictClient = new FakeCaptainClient();
$conflictClient->existingDocument = ['id' => 999, 'external_link' => 'https://example.test/journal-b/support-knowledge'];
$conflictProvisioner = new CaptainDocumentProvisioner($conflictClient, $conflictRepo);

$conflictResult = $conflictProvisioner->provision(makeCompilation(8, 'en', 'fp-1'), 43, 'Journal B Support Knowledge', 'https://example.test/journal-b/support-knowledge', 1_800_000_000);
captainProvisioningCheck($conflictResult->status() === CaptainSyncResult::STATUS_CONFLICT, 'an existing unmanaged document must produce a conflict result');
captainProvisioningCheck($conflictResult->reasonCode() === 'unmanaged_document_exists', 'conflict must carry the unmanaged_document_exists reason code');
captainProvisioningCheck($conflictClient->createCalls === 0, 'a detected conflict must never trigger a create call');
captainProvisioningCheck($conflictClient->syncCalls === 0, 'a detected conflict must never sync a document this codebase does not own');
$conflictState = $conflictRepo->find(8, 'en', CaptainSyncState::RESOURCE_DOCUMENT);
captainProvisioningCheck($conflictState !== null && !$conflictState->isOwned(), 'a conflict must never record ownership of a document the plugin did not create');

// ================================================================
// Part 6: create failure -> failed, no false ownership recorded.
// ================================================================
$failRepo = new InMemorySyncRepository();
$failClient = new FakeCaptainClient();
$failClient->createResult = null;
$failProvisioner = new CaptainDocumentProvisioner($failClient, $failRepo);
$createFailResult = $failProvisioner->provision(makeCompilation(9, 'en', 'fp-1'), 44, 'Journal C Support Knowledge', 'https://example.test/journal-c/support-knowledge', 1_800_000_000);
captainProvisioningCheck($createFailResult->status() === CaptainSyncResult::STATUS_FAILED, 'a failed create call must report failed');
captainProvisioningCheck($createFailResult->reasonCode() === 'create_failed', 'a failed create must carry the create_failed reason code');
captainProvisioningCheck(!($failRepo->find(9, 'en', CaptainSyncState::RESOURCE_DOCUMENT)?->isOwned() ?? false), 'a failed create must never record a fabricated ownership record');

// ================================================================
// Part 7: multi-journal isolation — two contexts never share sync state.
// ================================================================
$multiRepo = new InMemorySyncRepository();
$multiClientA = new FakeCaptainClient();
$multiClientA->createResult = ['id' => 'doc-a'];
(new CaptainDocumentProvisioner($multiClientA, $multiRepo))->provision(makeCompilation(101, 'en', 'fp-a'), 1, 'A', 'https://example.test/a', 1_800_000_000);
$multiClientB = new FakeCaptainClient();
$multiClientB->createResult = ['id' => 'doc-b'];
(new CaptainDocumentProvisioner($multiClientB, $multiRepo))->provision(makeCompilation(102, 'en', 'fp-b'), 2, 'B', 'https://example.test/b', 1_800_000_000);
captainProvisioningCheck($multiRepo->find(101, 'en', CaptainSyncState::RESOURCE_DOCUMENT)?->remoteResourceId() === 'doc-a', 'context 101 must keep its own remote document id');
captainProvisioningCheck($multiRepo->find(102, 'en', CaptainSyncState::RESOURCE_DOCUMENT)?->remoteResourceId() === 'doc-b', 'context 102 must keep its own remote document id');

// ================================================================
// Part 8: migration/plugin wiring source-level checks.
// ================================================================
$migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
captainProvisioningCheck(str_contains($migrationSource, "KNOWLEDGE_SYNC_TABLE = 'chatwoot_support_knowledge_sync'"), 'migration must define the knowledge sync table constant');
captainProvisioningCheck(str_contains($migrationSource, "'cw_knowledge_sync_identity'"), 'migration must uniquely key sync state by (context, locale, resource type)');

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
captainProvisioningCheck(str_contains($pluginSource, 'function provisionCaptainKnowledgeDocument'), 'plugin must implement the Captain document provisioning entry point');
captainProvisioningCheck(str_contains($pluginSource, 'chatwootCaptainAssistantId'), 'plugin must read a configurable Captain assistant id, never a hard-coded one');

$repositorySource = (string) file_get_contents($root . '/classes/v2/Captain/DatabaseSupportKnowledgeSyncRepository.php');
captainProvisioningCheck(str_contains($repositorySource, 'DB::table(self::table())'), 'the database repository must go through the shared table() accessor, matching the rest of this codebase\'s repositories');
captainProvisioningCheck(str_contains($repositorySource, "where('context_id'"), 'lookups must scope by context_id — never a global key');

fwrite(STDOUT, "Captain provisioning tests passed\n");
