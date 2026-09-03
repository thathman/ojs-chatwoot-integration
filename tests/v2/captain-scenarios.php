<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalScenarioCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainScenarioProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

function captainScenariosCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemoryScenarioSyncRepository implements SupportKnowledgeSyncRepositoryInterface
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

final class FakeScenarioClient implements ChatwootCaptainClientInterface
{
    public array $customTools = [];
    public array $existingScenarios = [];
    public array $createdDefinitions = [];
    public array $updatedDefinitions = [];
    public bool $updateResult = true;
    public int $nextId = 5000;

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
        return $this->customTools;
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
        return $this->existingScenarios;
    }

    public function createCaptainScenario(int $assistantId, array $definition): ?array
    {
        $id = (string) $this->nextId++;
        $this->createdDefinitions[$id] = $definition;
        return ['id' => $id];
    }

    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool
    {
        $this->updatedDefinitions[$scenarioId] = $definition;
        return $this->updateResult;
    }

    public function listCaptainAssistantResponses(int $assistantId): array
    {
        return [];
    }
}

/** Every canonical tool "already provisioned" with a deterministic fake slug, so scenario resolution can succeed. */
function allToolsProvisioned(): array
{
    $tools = [];
    foreach (CanonicalToolCatalog::all() as $tool) {
        $tools[] = ['id' => 'tool-' . $tool->key(), 'title' => $tool->title(), 'slug' => 'slug_' . $tool->key()];
    }
    return $tools;
}

// ================================================================
// Part 1: CanonicalScenarioCatalog shape.
// ================================================================
$scenarios = CanonicalScenarioCatalog::all();
captainScenariosCheck(count($scenarios) === 5, 'exactly the five documented scenario families must exist');
captainScenariosCheck(count($scenarios) === count(array_unique(array_map(fn ($s) => $s->key(), $scenarios))), 'every scenario key must be unique');
captainScenariosCheck(count($scenarios) === count(array_unique(array_map(fn ($s) => $s->title(), $scenarios))), 'every scenario title must be unique (used for conflict detection)');

$knownToolKeys = array_map(fn ($t) => $t->key(), CanonicalToolCatalog::all());
foreach ($scenarios as $scenario) {
    captainScenariosCheck(strlen($scenario->description()) <= 500, "scenario {$scenario->key()} description must be <=500 chars (Chatwoot's own Captain::Scenario::DESCRIPTION_LENGTH_LIMIT)");
    foreach ($scenario->requiredToolKeys() as $toolKey) {
        captainScenariosCheck(in_array($toolKey, $knownToolKeys, true), "scenario {$scenario->key()} references unknown tool key \"{$toolKey}\"");
    }
    preg_match_all('/\{\{tool:([a-zA-Z0-9_]+)\}\}/', $scenario->instructionTemplate(), $matches);
    foreach ($matches[1] as $referenced) {
        captainScenariosCheck(in_array($referenced, $scenario->requiredToolKeys(), true), "scenario {$scenario->key()} instruction references {$referenced} without declaring it as a required tool");
    }
    foreach ($scenario->requiredToolKeys() as $toolKey) {
        captainScenariosCheck(str_contains($scenario->instructionTemplate(), '{{tool:' . $toolKey . '}}'), "scenario {$scenario->key()} declares required tool {$toolKey} but never references it in the instruction");
    }
}

$journalInfo = array_values(array_filter($scenarios, fn ($s) => $s->key() === 'journal_information'))[0];
captainScenariosCheck($journalInfo->requiredToolKeys() === [], 'Journal Information must use zero tools — knowledge/FAQ lookup only, per the existing spec');

// ================================================================
// Part 2: resolveInstruction() — placeholder substitution and missing-tool safety.
// ================================================================
$accountSupport = array_values(array_filter($scenarios, fn ($s) => $s->key() === 'account_support'))[0];
$resolved = $accountSupport->resolveInstruction(
    ['ojs_get_support_identity' => 'slug_identity', 'ojs_request_verification' => 'slug_req', 'ojs_confirm_verification' => 'slug_conf', 'ojs_diagnose_account' => 'slug_diag'],
    ['ojs_get_support_identity' => 'Get OJS Support Identity', 'ojs_request_verification' => 'Request OJS Verification', 'ojs_confirm_verification' => 'Confirm OJS Verification', 'ojs_diagnose_account' => 'Diagnose OJS Account Issue']
);
captainScenariosCheck($resolved !== null, 'a scenario with every required tool resolvable must resolve successfully');
captainScenariosCheck(str_contains($resolved, '[Get OJS Support Identity](tool://slug_identity)'), 'resolved instruction must contain the real markdown tool:// reference Chatwoot parses');
captainScenariosCheck(!str_contains($resolved, '{{tool:'), 'no unresolved placeholder may remain in a successfully resolved instruction');

$unresolved = $accountSupport->resolveInstruction(['ojs_get_support_identity' => 'slug_identity'], ['ojs_get_support_identity' => 'Get OJS Support Identity']);
captainScenariosCheck($unresolved === null, 'a scenario missing even one required tool slug must fail to resolve, never partially render a broken instruction');

// ================================================================
// Part 3: CaptainScenarioProvisioner — full create pass with every tool available.
// ================================================================
$repo = new InMemoryScenarioSyncRepository();
$client = new FakeScenarioClient();
$client->customTools = allToolsProvisioned();
$provisioner = new CaptainScenarioProvisioner($client, $repo);

$results = $provisioner->provisionAll(7, 'en', 99, 1_800_000_000);
captainScenariosCheck(count($results) === 5, 'provisionAll must return exactly one result per canonical scenario');
foreach ($results as $key => $result) {
    captainScenariosCheck($result->status() === CaptainSyncResult::STATUS_CREATED, "scenario {$key} must be created when every required tool is already provisioned and no conflict exists");
}
captainScenariosCheck(count($client->createdDefinitions) === 5, 'exactly one create call per canonical scenario must be made');
foreach ($client->createdDefinitions as $definition) {
    captainScenariosCheck(!str_contains($definition['instruction'], '{{tool:'), 'every created scenario instruction must have every placeholder resolved');
    captainScenariosCheck(!array_key_exists('tools', $definition), 'the tools array is never sent explicitly — Chatwoot resolves it from the instruction text itself (before_save resolve_tool_references)');
}

// Unchanged -> no-op.
$noopResults = $provisioner->provisionAll(7, 'en', 99, 1_800_000_100);
foreach ($noopResults as $key => $result) {
    captainScenariosCheck($result->status() === CaptainSyncResult::STATUS_NOOP, "scenario {$key} must be a no-op when nothing changed");
}
captainScenariosCheck(count($client->createdDefinitions) === 5, 'a no-op pass must never create again');

// ================================================================
// Part 4: missing tool -> graceful degradation, not a fatal, not a broken scenario.
// ================================================================
$partialRepo = new InMemoryScenarioSyncRepository();
$partialClient = new FakeScenarioClient();
// Only the tools Journal Information needs (none) plus escalate — every other scenario should fail closed.
$partialClient->customTools = [
    ['id' => 'tool-x', 'title' => 'Escalate to Human Support', 'slug' => 'slug_escalate'],
];
$partialResults = (new CaptainScenarioProvisioner($partialClient, $partialRepo))->provisionAll(8, 'en', 99, 1_800_000_000);
captainScenariosCheck($partialResults['journal_information']->status() === CaptainSyncResult::STATUS_CREATED, 'Journal Information must always succeed since it needs no tools at all');
captainScenariosCheck($partialResults['human_escalation']->status() === CaptainSyncResult::STATUS_CREATED, 'Human Escalation must succeed once its one required tool is available');
captainScenariosCheck($partialResults['account_support']->status() === CaptainSyncResult::STATUS_FAILED, 'Account Support must fail closed when its required tools are not yet provisioned');
captainScenariosCheck($partialResults['account_support']->reasonCode() === 'required_tool_unavailable', 'a missing-tool failure must carry the required_tool_unavailable reason code');
captainScenariosCheck(!in_array('Account Support', array_column($partialClient->createdDefinitions, 'title'), true), 'a scenario that failed to resolve must never reach a create call');

// ================================================================
// Part 5: conflict detection — an unmanaged scenario with the same title is never adopted/duplicated.
// ================================================================
$conflictRepo = new InMemoryScenarioSyncRepository();
$conflictClient = new FakeScenarioClient();
$conflictClient->customTools = allToolsProvisioned();
$conflictClient->existingScenarios = [['id' => 'admin-scenario-1', 'title' => 'Journal Information']];
$conflictResults = (new CaptainScenarioProvisioner($conflictClient, $conflictRepo))->provisionAll(9, 'en', 99, 1_800_000_000);
captainScenariosCheck($conflictResults['journal_information']->status() === CaptainSyncResult::STATUS_CONFLICT, 'an existing scenario with the same title must produce a conflict, never a duplicate');
captainScenariosCheck(!array_key_exists('admin-scenario-1', $conflictClient->updatedDefinitions), 'a conflicting unmanaged scenario must never be updated');

// ================================================================
// Part 6: multi-journal isolation.
// ================================================================
$multiRepo = new InMemoryScenarioSyncRepository();
$multiClientA = new FakeScenarioClient();
$multiClientA->customTools = allToolsProvisioned();
(new CaptainScenarioProvisioner($multiClientA, $multiRepo))->provisionAll(201, 'en', 11, 1_800_000_000);
$multiClientB = new FakeScenarioClient();
$multiClientB->customTools = allToolsProvisioned();
(new CaptainScenarioProvisioner($multiClientB, $multiRepo))->provisionAll(202, 'en', 12, 1_800_000_000);
captainScenariosCheck(count($multiClientA->createdDefinitions) === 5, 'context 201 must provision its own full scenario set');
captainScenariosCheck(count($multiClientB->createdDefinitions) === 5, 'context 202 must provision its own full scenario set independently');

// ================================================================
// Part 7: authorization boundary — the provisioner never touches policy/capability code.
// ================================================================
$provisionerSource = '';
foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Captain/CaptainScenarioProvisioner.php')) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $provisionerSource .= is_array($token) ? $token[1] : $token;
}
foreach (['CapabilityPolicyEngine', 'CapabilityDecision', 'SupportSession', 'ServiceTokenAuthenticator'] as $forbidden) {
    captainScenariosCheck(!str_contains($provisionerSource, $forbidden), "CaptainScenarioProvisioner must never reference \"{$forbidden}\" — a scenario is never authorization, it has no way to touch the policy engine");
}

fwrite(STDOUT, "Captain Scenario provisioning tests passed\n");
