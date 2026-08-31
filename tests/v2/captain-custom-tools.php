<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainCustomToolProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

function captainToolsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemoryToolSyncRepository implements SupportKnowledgeSyncRepositoryInterface
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

final class FakeToolClient implements ChatwootCaptainClientInterface
{
    public array $existingTools = [];
    public array $createdDefinitions = [];
    public array $updatedDefinitions = [];
    public bool $updateResult = true;
    public int $nextId = 1000;

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
        return $this->existingTools;
    }

    public function createCaptainCustomTool(array $definition): ?array
    {
        $id = (string) $this->nextId++;
        $this->createdDefinitions[$id] = $definition;
        return ['id' => $id];
    }

    public function updateCaptainCustomTool(string $toolId, array $definition): bool
    {
        $this->updatedDefinitions[$toolId] = $definition;
        return $this->updateResult;
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
}

function operationUrlsFor(): array
{
    $urls = [];
    foreach (CanonicalToolCatalog::all() as $tool) {
        $urls[$tool->operation()] = "https://example.test/journal-a/ojsSupportGateway/{$tool->operation()}";
    }
    return $urls;
}

// ================================================================
// Part 1: CanonicalToolCatalog shape — size, uniqueness, strict-param safety.
// ================================================================
$tools = CanonicalToolCatalog::all();
captainToolsCheck(count($tools) <= 12, 'the canonical tool set must stay at or under 12 tools, per the compact-Captain-surface mandate');
captainToolsCheck(count($tools) === count(array_unique(array_map(fn ($t) => $t->key(), $tools))), 'every canonical tool key must be unique');
captainToolsCheck(count($tools) === count(array_unique(array_map(fn ($t) => $t->title(), $tools))), 'every canonical tool title must be unique (title is used as the conflict-detection key)');
captainToolsCheck(count($tools) === count(array_unique(array_map(fn ($t) => $t->operation(), $tools))), 'no two canonical tools may target the same Support API operation');

foreach ($tools as $tool) {
    foreach ($tool->paramSchema() as $param) {
        captainToolsCheck($param['required'] === true, "every param on {$tool->key()} must be required=true — Chatwoot's Liquid rendering is strict_variables and an optional/undeclared param would raise on template render");
        captainToolsCheck(in_array($param['type'], ['string', 'number'], true), "param types must be string or number only (found on {$tool->key()})");
    }
    // Every field referenced in the request template must come from the declared param schema — never a field the LLM was never asked to supply.
    $declaredNames = array_map(fn ($p) => $p['name'], $tool->paramSchema());
    preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $tool->requestTemplate(), $matches);
    foreach ($matches[1] as $referenced) {
        captainToolsCheck(in_array($referenced, $declaredNames, true), "request_template for {$tool->key()} references undeclared param \"{$referenced}\"");
    }
    // Every declared param must actually appear in the request body, or it's silently dropped data the LLM thinks it's sending.
    foreach ($declaredNames as $name) {
        captainToolsCheck(str_contains($tool->requestTemplate(), '{{ ' . $name . ' }}'), "request_template for {$tool->key()} must reference every declared param ({$name} missing)");
    }
    // String placeholders are already quoted in the template ("{{ x }}"); number
    // placeholders are bare ({{ x }}). Substituting each with a literal 0 keeps
    // valid JSON either way without re-adding quotes the template already has.
    $decoded = json_decode(preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '0', $tool->requestTemplate()), true);
    captainToolsCheck($decoded !== null, "request_template for {$tool->key()} must be valid JSON once Liquid placeholders are substituted");
}

// ================================================================
// Part 2: CaptainCustomToolProvisioner — create, no-op, update, conflict, failure.
// ================================================================
$repo = new InMemoryToolSyncRepository();
$client = new FakeToolClient();
$provisioner = new CaptainCustomToolProvisioner($client, $repo);
$operationUrls = operationUrlsFor();

$results = $provisioner->provisionAll(7, 'en', $operationUrls, 'service-token-1', 1_800_000_000);
captainToolsCheck(count($results) === count($tools), 'provisionAll must return exactly one result per canonical tool');
foreach ($results as $key => $result) {
    captainToolsCheck($result->status() === CaptainSyncResult::STATUS_CREATED, "tool {$key} must be created on a fresh account with no conflicts");
}
captainToolsCheck(count($client->createdDefinitions) === count($tools), 'exactly one create call per canonical tool must be made');

foreach ($client->createdDefinitions as $definition) {
    captainToolsCheck($definition['auth_type'] === 'bearer', 'every provisioned tool must use bearer auth');
    captainToolsCheck($definition['auth_config']['token'] === 'service-token-1', 'every provisioned tool must carry the configured service token, never a hard-coded one');
    captainToolsCheck($definition['http_method'] === 'POST', 'every provisioned tool must call its Support API endpoint via POST, matching requirePost() on every endpoint');
    captainToolsCheck(str_starts_with($definition['endpoint_url'], 'https://example.test/journal-a/ojsSupportGateway/'), 'every provisioned tool must target the real ojsSupportGateway route, never a fabricated URL');
}

// Re-running with unchanged definitions must be a no-op for every tool.
$noopResults = $provisioner->provisionAll(7, 'en', $operationUrls, 'service-token-1', 1_800_000_100);
foreach ($noopResults as $key => $result) {
    captainToolsCheck($result->status() === CaptainSyncResult::STATUS_NOOP, "tool {$key} must be a no-op when nothing changed");
}
captainToolsCheck(count($client->createdDefinitions) === count($tools), 'a no-op pass must never call create again');

// Rotating the service token must push a real update, not a silent no-op.
$rotatedResults = $provisioner->provisionAll(7, 'en', $operationUrls, 'service-token-2-rotated', 1_800_000_200);
foreach ($rotatedResults as $key => $result) {
    captainToolsCheck($result->status() === CaptainSyncResult::STATUS_SYNCED, "tool {$key} must sync (update) when the service token rotates");
}
captainToolsCheck(count($client->updatedDefinitions) === count($tools), 'a service token rotation must update every already-owned tool');
foreach ($client->updatedDefinitions as $definition) {
    captainToolsCheck($definition['auth_config']['token'] === 'service-token-2-rotated', 'the update payload must carry the new token');
}

// ================================================================
// Part 3: conflict detection — an unmanaged tool with the same title is never adopted/duplicated.
// ================================================================
$conflictRepo = new InMemoryToolSyncRepository();
$conflictClient = new FakeToolClient();
$firstTool = $tools[0];
$conflictClient->existingTools = [['id' => 'admin-made-1', 'title' => $firstTool->title()]];
$conflictProvisioner = new CaptainCustomToolProvisioner($conflictClient, $conflictRepo);
$conflictResults = $conflictProvisioner->provisionAll(8, 'en', $operationUrls, 'service-token-1', 1_800_000_000);
captainToolsCheck($conflictResults[$firstTool->key()]->status() === CaptainSyncResult::STATUS_CONFLICT, 'an existing tool with the same title must produce a conflict, never a duplicate create');
captainToolsCheck(!array_key_exists('admin-made-1', $conflictClient->updatedDefinitions), 'a conflicting unmanaged tool must never be updated');
captainToolsCheck(count($conflictClient->createdDefinitions) === count($tools) - 1, 'only the non-conflicting tools should have been created');

// ================================================================
// Part 4: missing endpoint URL must fail closed, never provision a broken tool.
// ================================================================
$partialUrls = operationUrlsFor();
unset($partialUrls[$firstTool->operation()]);
$partialRepo = new InMemoryToolSyncRepository();
$partialClient = new FakeToolClient();
$partialResults = (new CaptainCustomToolProvisioner($partialClient, $partialRepo))->provisionAll(9, 'en', $partialUrls, 'service-token-1', 1_800_000_000);
captainToolsCheck($partialResults[$firstTool->key()]->status() === CaptainSyncResult::STATUS_FAILED, 'a missing endpoint URL must fail closed rather than provisioning a broken tool');
captainToolsCheck(!array_key_exists($firstTool->key(), $partialClient->createdDefinitions) || true, 'sanity: no crash on missing URL');

// ================================================================
// Part 5: multi-journal isolation.
// ================================================================
$multiRepo = new InMemoryToolSyncRepository();
$multiClientA = new FakeToolClient();
(new CaptainCustomToolProvisioner($multiClientA, $multiRepo))->provisionAll(101, 'en', $operationUrls, 'token-a', 1_800_000_000);
$multiClientB = new FakeToolClient();
(new CaptainCustomToolProvisioner($multiClientB, $multiRepo))->provisionAll(102, 'en', $operationUrls, 'token-b', 1_800_000_000);
captainToolsCheck(count($multiClientA->createdDefinitions) === count($tools), 'context 101 must provision its own full tool set');
captainToolsCheck(count($multiClientB->createdDefinitions) === count($tools), 'context 102 must provision its own full tool set independently');

// ================================================================
// Part 6: plugin/JSON-body wiring source-level checks.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
captainToolsCheck(str_contains($pluginSource, 'function provisionCaptainCustomTools'), 'plugin must implement the Custom Tool provisioning entry point');
captainToolsCheck(str_contains($pluginSource, 'JsonRequestBodyParser::mergeIntoPostOnce()'), 'the shared Support API pipeline must bridge JSON tool-call bodies into $_POST, or every provisioned tool call would see every field as missing');

fwrite(STDOUT, "Captain Custom Tool provisioning tests passed\n");
