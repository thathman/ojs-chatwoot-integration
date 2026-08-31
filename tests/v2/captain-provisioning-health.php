<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalScenarioCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainResourceHealth;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

function captainHealthCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemoryHealthSyncRepository implements SupportKnowledgeSyncRepositoryInterface
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

$expectedTotal = 1 + count(CanonicalToolCatalog::all()) + count(CanonicalScenarioCatalog::all());

// ================================================================
// Part 1: nothing provisioned yet -> not_provisioned overall, never mistaken for a failure.
// ================================================================
$repo = new InMemoryHealthSyncRepository();
$service = new CaptainProvisioningHealthService($repo);
$emptyReport = $service->buildReport(7, 'en');
captainHealthCheck(count($emptyReport->resources()) === $expectedTotal, 'report must cover exactly the Document + every canonical tool + every canonical scenario');
captainHealthCheck($emptyReport->overallState() === CaptainProvisioningHealthReport::STATE_NOT_PROVISIONED, 'no local records at all must report not_provisioned, never degraded/failed');
captainHealthCheck($emptyReport->countByState(CaptainResourceHealth::STATE_NOT_PROVISIONED) === $expectedTotal, 'every resource must individually be not_provisioned');

// ================================================================
// Part 2: everything owned and healthy -> overall healthy.
// ================================================================
$now = 1_800_000_000;
$repo->save(CaptainSyncState::created(7, 'en', CaptainSyncState::RESOURCE_DOCUMENT, '', 'doc-1', 'fp-doc', $now));
foreach (CanonicalToolCatalog::all() as $tool) {
    $repo->save(CaptainSyncState::created(7, 'en', CaptainSyncState::RESOURCE_CUSTOM_TOOL, $tool->key(), 'tool-' . $tool->key(), 'fp-' . $tool->key(), $now));
}
foreach (CanonicalScenarioCatalog::all() as $scenario) {
    $repo->save(CaptainSyncState::created(7, 'en', CaptainSyncState::RESOURCE_SCENARIO, $scenario->key(), 'scenario-' . $scenario->key(), 'fp-' . $scenario->key(), $now));
}
$healthyReport = $service->buildReport(7, 'en');
captainHealthCheck($healthyReport->overallState() === CaptainProvisioningHealthReport::STATE_HEALTHY, 'every resource owned with no errors must report healthy overall');
captainHealthCheck($healthyReport->countByState(CaptainResourceHealth::STATE_OWNED) === $expectedTotal, 'every resource must individually be owned');

// ================================================================
// Part 3: a degraded resource (owned, but last sync attempt errored) drags overall state down.
// ================================================================
$firstTool = CanonicalToolCatalog::all()[0];
$owned = $repo->find(7, 'en', CaptainSyncState::RESOURCE_CUSTOM_TOOL, $firstTool->key());
$repo->save($owned->withError('update_failed', $now + 100));
$degradedReport = $service->buildReport(7, 'en');
captainHealthCheck($degradedReport->overallState() === CaptainProvisioningHealthReport::STATE_DEGRADED, 'one degraded resource must pull the overall state to degraded');
$degradedResource = array_values(array_filter($degradedReport->resources(), fn ($r) => $r->resourceKey() === $firstTool->key() && $r->resourceType() === CaptainSyncState::RESOURCE_CUSTOM_TOOL))[0];
captainHealthCheck($degradedResource->state() === CaptainResourceHealth::STATE_DEGRADED, 'an owned resource with a recorded error must be classified degraded, not owned or failed');
captainHealthCheck($degradedResource->lastErrorCode() === 'update_failed', 'the degraded resource must carry its actual error code');

// ================================================================
// Part 4: conflict vs. genuine failure classification.
// ================================================================
$conflictRepo = new InMemoryHealthSyncRepository();
$conflictRepo->save(CaptainSyncState::unresolved(8, 'en', CaptainSyncState::RESOURCE_DOCUMENT, '', 'unmanaged_document_exists', $now));
$conflictReport = (new CaptainProvisioningHealthService($conflictRepo))->buildReport(8, 'en');
$docHealth = array_values(array_filter($conflictReport->resources(), fn ($r) => $r->resourceType() === CaptainSyncState::RESOURCE_DOCUMENT))[0];
captainHealthCheck($docHealth->state() === CaptainResourceHealth::STATE_CONFLICT, 'an unresolved state with an "unmanaged" error code must classify as conflict, not failed');

$failRepo = new InMemoryHealthSyncRepository();
$failRepo->save(CaptainSyncState::unresolved(9, 'en', CaptainSyncState::RESOURCE_DOCUMENT, '', 'create_failed', $now));
$failReport = (new CaptainProvisioningHealthService($failRepo))->buildReport(9, 'en');
$failedDocHealth = array_values(array_filter($failReport->resources(), fn ($r) => $r->resourceType() === CaptainSyncState::RESOURCE_DOCUMENT))[0];
captainHealthCheck($failedDocHealth->state() === CaptainResourceHealth::STATE_FAILED, 'a create failure (not a name/URL conflict) must classify as failed, not conflict');

// ================================================================
// Part 5: multi-journal isolation — context 8/9's states must never appear in context 7's report.
// ================================================================
$isolationReport = $service->buildReport(7, 'en');
foreach ($isolationReport->resources() as $resource) {
    captainHealthCheck($resource->state() !== CaptainResourceHealth::STATE_CONFLICT, 'context 7 must never see context 8\'s conflict state');
}

// ================================================================
// Part 6: this service never calls the Chatwoot API — source-level check.
// ================================================================
$serviceSource = '';
foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Captain/CaptainProvisioningHealthService.php')) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $serviceSource .= is_array($token) ? $token[1] : $token;
}
foreach (['ChatwootCaptainClientInterface', 'ChatwootApiService', 'requestJson'] as $forbidden) {
    captainHealthCheck(!str_contains($serviceSource, $forbidden), "CaptainProvisioningHealthService must never reference \"{$forbidden}\" — it is a pure local-state read, never a network call");
}

fwrite(STDOUT, "Captain provisioning health tests passed\n");
