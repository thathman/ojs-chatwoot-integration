<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainCustomToolProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainScenarioProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

function har002Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemoryHar002SyncRepository implements SupportKnowledgeSyncRepositoryInterface
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

/**
 * A client whose listCaptainCustomTools()/listCaptainScenarios() throw,
 * matching the real ChatwootApiService's HAR-002 contract (a real
 * request failure must never collapse to []) — proving the
 * provisioners built against this interface actually honor that
 * contract rather than assuming the interface always fails open.
 */
final class ThrowingListCaptainClient implements ChatwootCaptainClientInterface
{
    public array $createdDefinitions = [];

    public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array
    {
        return null;
    }
    public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array
    {
        return null;
    }
    public function syncCaptainDocument(string $documentId): bool
    {
        return true;
    }

    public function listCaptainCustomTools(): array
    {
        throw new \RuntimeException('Chatwoot Captain custom_tools request failed: simulated outage');
    }

    public function createCaptainCustomTool(array $definition): ?array
    {
        $id = 'tool-' . count($this->createdDefinitions);
        $this->createdDefinitions[$id] = $definition;
        return ['id' => $id];
    }

    public function updateCaptainCustomTool(string $toolId, array $definition): bool
    {
        return true;
    }

    public function listCaptainScenarios(int $assistantId): array
    {
        throw new \RuntimeException('Chatwoot Captain scenarios request failed: simulated outage');
    }

    public function createCaptainScenario(int $assistantId, array $definition): ?array
    {
        $id = 'scenario-' . count($this->createdDefinitions);
        $this->createdDefinitions[$id] = $definition;
        return ['id' => $id];
    }

    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool
    {
        return true;
    }

    public function listCaptainAssistantResponses(int $assistantId): array
    {
        return [];
    }
}

function operationUrlsForHar002(): array
{
    $urls = [];
    foreach (CanonicalToolCatalog::all() as $tool) {
        $urls[$tool->operation()] = "https://example.test/journal-a/ojsSupportGateway/{$tool->operation()}";
    }
    return $urls;
}

/**
 * HAR-002: listCaptainCustomTools()/listCaptainScenarios() are used as
 * a dedup-before-create check — before this fix, a failed request
 * silently returned [] and was indistinguishable from "genuinely zero
 * existing resources," so every canonical tool/scenario would be
 * created on retry after a transient outage, potentially duplicating
 * ones that already exist remotely. Proves the real failure path:
 * every tool/scenario resolves to a safe failed() result, and
 * critically, none of them are ever actually created while the
 * listing itself is failing.
 */
$client = new ThrowingListCaptainClient();
$repo = new InMemoryHar002SyncRepository();
$toolProvisioner = new CaptainCustomToolProvisioner($client, $repo);
$toolResults = $toolProvisioner->provisionAll(7, 'en', operationUrlsForHar002(), 'service-token-1', 1_800_000_000);

foreach ($toolResults as $key => $result) {
    har002Check($result->status() === CaptainSyncResult::STATUS_FAILED, "tool {$key} must fail closed when the existing-tools listing itself fails, never proceed as if none exist");
}
har002Check($client->createdDefinitions === [], 'not a single tool may be created while the dedup listing is failing — that would risk a real duplicate');

$scenarioClient = new ThrowingListCaptainClient();
$scenarioRepo = new InMemoryHar002SyncRepository();
$scenarioProvisioner = new CaptainScenarioProvisioner($scenarioClient, $scenarioRepo);
$scenarioResults = $scenarioProvisioner->provisionAll(7, 'en', 42, 1_800_000_000);

foreach ($scenarioResults as $key => $result) {
    har002Check($result->status() === CaptainSyncResult::STATUS_FAILED, "scenario {$key} must fail closed when either the tool-reference listing or the existing-scenarios listing fails");
}
har002Check($scenarioClient->createdDefinitions === [], 'not a single scenario may be created while a listing this provisioner depends on is failing');

// ================================================================
// Real wiring: the interface contract itself must document the
// throw-on-failure requirement, not just individual implementations.
// ================================================================
$interfaceSource = (string) file_get_contents("{$root}/classes/v2/Contracts/ChatwootCaptainClientInterface.php");
har002Check(substr_count($interfaceSource, '@throws \Throwable on any request failure') >= 2, 'both listCaptainCustomTools() and listCaptainScenarios() must document the throw-on-failure contract, matching listCaptainAssistantResponses()\'s existing precedent');

fwrite(STDOUT, "HAR-002 captain-list-failure-fail-closed tests passed\n");
