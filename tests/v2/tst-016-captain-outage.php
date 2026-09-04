<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainDocumentProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainResourceHealth;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;

function tst016Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-016 (first slice): a real HTTP-outage proof of Captain/Chatwoot
 * graceful degradation, using the same honest philosophy as TST-003 — a
 * real unreachable socket, not a hand-rolled fake object that never
 * touches real network code paths.
 *
 * ChatwootApiService itself still cannot be instantiated here (no
 * Composer/Guzzle — see TST-003's scoping note), but
 * ChatwootCaptainClientInterface is a plain interface with no Guzzle
 * dependency, so a minimal real-HTTP implementation of it (built the
 * same way TST-003's test client was: PHP's stream wrapper, no
 * extension/dependency needed) can drive the real
 * CaptainDocumentProvisioner / CaptainProvisioningHealthService business
 * logic end to end against a real connection-refused socket.
 */

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

/**
 * A minimal real-HTTP ChatwootCaptainClientInterface implementation.
 * Every method makes a real request via PHP's stream wrapper — on a real
 * connection failure it returns exactly what the interface's docblock
 * requires ("every method here failing/returning null [or false/[]] is
 * Captain provisioning unavailable, never a fatal"), mirroring
 * ChatwootApiService::requestJson()'s own catch-and-degrade contract.
 */
final class RealHttpCaptainClient implements ChatwootCaptainClientInterface
{
    public function __construct(private string $baseUrl)
    {
    }

    private function get(string $path): ?array
    {
        $result = @file_get_contents($this->baseUrl . $path, false, stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]));
        if ($result === false || !self::responseWasSuccessful($http_response_header ?? [])) {
            return null;
        }
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    /** @param string[] $headers */
    private static function responseWasSuccessful(array $headers): bool
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                return ((int) $matches[1]) >= 200 && ((int) $matches[1]) < 300;
            }
        }
        return false;
    }

    private function post(string $path, array $body): ?array
    {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 1,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($this->baseUrl . $path, false, $context);
        if ($result === false || !self::responseWasSuccessful($http_response_header ?? [])) {
            return null;
        }
        $decoded = json_decode($result, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array
    {
        return $this->get('/documents?external_link=' . urlencode($externalLink));
    }

    public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array
    {
        return $this->post('/documents', ['name' => $name, 'external_link' => $externalLink]);
    }

    public function syncCaptainDocument(string $documentId): bool
    {
        return $this->post("/documents/{$documentId}/sync", []) !== null;
    }

    /** HAR-002: the interface now requires throwing on a real request failure — see ChatwootCaptainClientInterface::listCaptainCustomTools(). */
    public function listCaptainCustomTools(): array
    {
        $result = $this->get('/custom_tools');
        if ($result === null) {
            throw new \RuntimeException('custom_tools request failed');
        }
        return $result;
    }

    public function createCaptainCustomTool(array $definition): ?array
    {
        return $this->post('/custom_tools', $definition);
    }

    public function updateCaptainCustomTool(string $toolId, array $definition): bool
    {
        return $this->post("/custom_tools/{$toolId}", $definition) !== null;
    }

    /** HAR-002: the interface now requires throwing on a real request failure — see ChatwootCaptainClientInterface::listCaptainScenarios(). */
    public function listCaptainScenarios(int $assistantId): array
    {
        $result = $this->get("/assistants/{$assistantId}/scenarios");
        if ($result === null) {
            throw new \RuntimeException('scenarios request failed');
        }
        return $result;
    }

    public function createCaptainScenario(int $assistantId, array $definition): ?array
    {
        return $this->post("/assistants/{$assistantId}/scenarios", $definition);
    }

    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool
    {
        return $this->post("/assistants/{$assistantId}/scenarios/{$scenarioId}", $definition) !== null;
    }

    public function listCaptainAssistantResponses(int $assistantId): array
    {
        return $this->get("/assistant_responses?assistant_id={$assistantId}") ?? [];
    }
}

function tst016Compilation(int $contextId, string $locale, string $fingerprint): KnowledgeCompilation
{
    $facts = [new KnowledgeFact('journal.name', 'A Safe Journal', KnowledgeClassification::PUBLIC, 'ojs.context', $locale, 'test')];
    return new KnowledgeCompilation($contextId, $locale, $facts, $fingerprint, 1_800_000_000);
}

// A real dead port — nothing is listening here, guaranteeing a genuine
// connection-refused, not a slow-timeout or an actually-reachable server.
$deadPort = 18900 + random_int(0, 999);
$outageClient = new RealHttpCaptainClient("http://127.0.0.1:{$deadPort}");

// ================================================================
// 1. A real outage during first-time provisioning must never throw, and
//    must resolve to a safe, generic failure — never a raw exception or
//    a raw connection-refused message reaching the caller.
// ================================================================
$repo = new InMemorySyncRepository();
$provisioner = new CaptainDocumentProvisioner($outageClient, $repo);
$compilation = tst016Compilation(7, 'en', 'fp-1');

try {
    $result = $provisioner->provision($compilation, 42, 'Support Knowledge', 'https://journal-a.example.com/support-knowledge/', 1_800_000_100);
} catch (\Throwable $e) {
    $result = null;
    tst016Check(false, 'a real Chatwoot outage during provisioning must never throw an uncaught exception: ' . $e->getMessage());
}

tst016Check($result instanceof CaptainSyncResult, 'provision() must always return a real CaptainSyncResult, even during a real outage');
tst016Check($result->status() === CaptainSyncResult::STATUS_FAILED, 'a real outage during first-time provisioning must resolve to the generic failed status, never conflict/created/synced');
tst016Check($result->reasonCode() === 'create_failed', 'the reason code must be the real fixed create_failed code, never a raw network/connection-refused message');

// ================================================================
// 2. Local state bookkeeping after the outage must be safe and
//    consistent — an unresolved record, never a corrupted/partial
//    "owned" record that a later real sync could be fooled by.
// ================================================================
$recordedState = $repo->find(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT, '');
tst016Check($recordedState !== null, 'a real outage must still leave a local record behind (unresolved), so the health report below can classify it');
tst016Check($recordedState->isOwned() === false, 'an outage-failed provisioning attempt must never be recorded as owned');
tst016Check($recordedState->lastErrorCode() === 'create_failed', 'the recorded error code must be the same safe fixed code, never raw exception text');

// ================================================================
// 3. The health service (a pure local read — never itself a network
//    call) must correctly classify this as failed, giving an operator a
//    true picture of the outage without the health check itself being
//    vulnerable to the same outage.
// ================================================================
$healthService = new CaptainProvisioningHealthService($repo);
$report = $healthService->buildReport(7, 'en');
$documentHealth = null;
foreach ($report->resources() as $resource) {
    if ($resource->resourceType() === CaptainSyncState::RESOURCE_DOCUMENT) {
        $documentHealth = $resource;
    }
}
tst016Check($documentHealth !== null, 'the health report must include the Document resource');
tst016Check($documentHealth->state() === CaptainResourceHealth::STATE_FAILED, 'the health report must classify a real outage-failed document as failed, giving an honest degraded picture');
tst016Check($documentHealth->lastErrorCode() === 'create_failed', 'the health report must expose the same safe fixed error code, never raw network detail');

// ================================================================
// 4. Unrelated functionality must not be blocked by the outage: building
//    a health report for a completely different journal/context must
//    still work immediately, proving the outage does not leave any
//    global lock/blocking state behind.
// ================================================================
$unrelatedReport = $healthService->buildReport(99, 'en');
tst016Check($unrelatedReport->overallState() !== null, 'an unrelated journal\'s health report must still build normally after another journal hit a real outage');

// ================================================================
// 5. Recovery: once Chatwoot becomes reachable again, a retried
//    provision() must succeed and correctly transition state from
//    failed to owned — proving retry/state bookkeeping is safe across
//    an outage-then-recovery cycle, not just during the outage itself.
// ================================================================
$routerFile = tempnam(sys_get_temp_dir(), 'tst016-captain-router-');
file_put_contents($routerFile, '<?php
header("Content-Type: application/json");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/documents" && $_SERVER["REQUEST_METHOD"] === "POST") {
    echo json_encode(["id" => 909]);
} else {
    http_response_code(404);
    echo json_encode(["error" => "not_found"]);
}
');
$port = 18700 + random_int(0, 199);
$process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerFile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
tst016Check(is_resource($process), 'must be able to start a real local recovery server for this test');

$ready = false;
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(50000);
}
tst016Check($ready, 'the real local recovery server must accept connections before this test proceeds');

try {
    $recoveredClient = new RealHttpCaptainClient("http://127.0.0.1:{$port}");
    $recoveredProvisioner = new CaptainDocumentProvisioner($recoveredClient, $repo);
    $recoveryResult = $recoveredProvisioner->provision($compilation, 42, 'Support Knowledge', 'https://journal-a.example.com/support-knowledge/', 1_800_000_200);

    tst016Check($recoveryResult->status() === CaptainSyncResult::STATUS_CREATED, 'once Chatwoot is reachable again, a retried provision() must succeed and report created, proving recovery works after a real prior outage');

    $recoveredState = $repo->find(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT, '');
    tst016Check($recoveredState !== null && $recoveredState->isOwned() === true, 'the local record must transition from unresolved to owned after a real successful recovery');
    tst016Check($recoveredState->lastErrorCode() === null, 'a successful recovery must clear the prior error code, never leave stale outage state behind');

    $recoveredReport = $healthService->buildReport(7, 'en');
    foreach ($recoveredReport->resources() as $resource) {
        if ($resource->resourceType() === CaptainSyncState::RESOURCE_DOCUMENT) {
            tst016Check($resource->state() === CaptainResourceHealth::STATE_OWNED, 'the health report must reflect the real recovery, no longer reporting failed once the resource is genuinely owned again');
        }
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($routerFile);
}

fwrite(STDOUT, "TST-016 real Captain/Chatwoot outage tests passed\n");
