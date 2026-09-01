<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\SupportGatewayHealthAggregator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\SupportGatewayHealthSummary;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ProviderHealth;

function supportGatewayHealthCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function fixtureKnowledgeHealth(string $state): KnowledgeHealthReport
{
    return new KnowledgeHealthReport(7, 'en', 'fp-1', $state, 3, 2, ['core'], [], 0, 0, 0, [], []);
}

function fixtureCaptainHealth(string $overallState): CaptainProvisioningHealthReport
{
    return new CaptainProvisioningHealthReport(7, 'en', [], $overallState);
}

/**
 * ADM-002 (first slice): the unified Support Gateway health section.
 * SupportGatewayHealthAggregator is a pure function of already-computed
 * inputs (no OJS runtime needed), so its deterministic overall-state
 * rule is fully unit-testable here. The plugin-level gathering method
 * (supportGatewayHealthSummary()) cannot be exercised directly (needs a
 * live OJS request/context), so it is covered by source-level wiring
 * assertions instead, same pattern as every other admin/settings
 * integration point this session.
 */

// ================================================================
// Unconfigured Chatwoot must always report failed, regardless of how
// healthy every other signal looks — nothing else can be trusted
// without it.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(false, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), fixtureCaptainHealth(CaptainProvisioningHealthReport::STATE_HEALTHY), [], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_FAILED, 'an unconfigured Chatwoot connection must always resolve to failed overall');

// ================================================================
// A failed Knowledge health must always resolve to failed overall.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_FAILED), null, [], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_FAILED, 'a failed Knowledge health report must resolve to failed overall');

// ================================================================
// A genuinely failed payment provider (not a legitimately-not-applicable
// state) must resolve to failed overall.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, ['airix.submission_fee' => ProviderHealth::UNAVAILABLE], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_FAILED, 'a genuinely failed payment provider must resolve to failed overall');

// ================================================================
// A provider that is legitimately not applicable (disabled/not
// installed/incompatible) must never be treated as a failure.
// ================================================================
foreach ([ProviderHealth::DISABLED, ProviderHealth::NOT_INSTALLED, ProviderHealth::INCOMPATIBLE_VERSION] as $notApplicableState) {
    $summary = SupportGatewayHealthAggregator::build(true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, ['airix.submission_fee' => $notApplicableState], 0);
    supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_HEALTHY, "a provider reporting the legitimately-not-applicable state \"{$notApplicableState}\" must never drag the overall state down");
}

// ================================================================
// Degraded signals: Knowledge degraded/empty, Captain degraded, a
// missing (but not itself failed) credential, or accumulating dead
// letters must each independently resolve to degraded, never healthy
// and never a false failed.
// ================================================================
$degradedCases = [
    'Knowledge degraded' => [true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_DEGRADED), null, [], 0],
    'Knowledge empty' => [true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_EMPTY), null, [], 0],
    'Captain degraded' => [true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), fixtureCaptainHealth(CaptainProvisioningHealthReport::STATE_DEGRADED), [], 0],
    'Support API not configured' => [true, false, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, [], 0],
    'MCP not configured' => [true, true, false, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, [], 0],
    'verification not configured' => [true, true, true, false, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, [], 0],
    'dead letters accumulating' => [true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), null, [], 3],
];
foreach ($degradedCases as $label => $args) {
    $summary = SupportGatewayHealthAggregator::build(...$args);
    supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_DEGRADED, "case \"{$label}\" must resolve to degraded overall, never healthy and never failed");
}

// ================================================================
// Captain's own "not_provisioned" state must be treated as neutral, not
// degraded — a fresh install genuinely has nothing provisioned yet.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), fixtureCaptainHealth(CaptainProvisioningHealthReport::STATE_NOT_PROVISIONED), [], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_HEALTHY, 'Captain\'s not_provisioned state must never be treated as evidence of degradation — it is neutral, not a problem');

// ================================================================
// Everything present and healthy must resolve to healthy — never a
// synthetic healthy claim, only when every real signal actually says so.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(true, true, true, true, fixtureKnowledgeHealth(KnowledgeHealthReport::STATE_HEALTHY), fixtureCaptainHealth(CaptainProvisioningHealthReport::STATE_HEALTHY), ['airix.submission_fee' => ProviderHealth::AVAILABLE], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_HEALTHY, 'every real signal being healthy must resolve to healthy overall');
supportGatewayHealthCheck($summary->toArray()['deadLetterCount'] === 0, 'toArray() must expose the real dead-letter count');
supportGatewayHealthCheck($summary->toArray()['knowledgeState'] === KnowledgeHealthReport::STATE_HEALTHY, 'toArray() must expose the real underlying knowledge state, never a re-derived guess');

// ================================================================
// A null Knowledge/Captain report (the gathering method could not build
// one) must never crash the aggregator and must never be silently
// treated as evidence of health.
// ================================================================
$summary = SupportGatewayHealthAggregator::build(true, true, true, true, null, null, [], 0);
supportGatewayHealthCheck($summary->overallState() === SupportGatewayHealthSummary::STATE_HEALTHY, 'a genuinely absent Knowledge/Captain report must not itself count as a failure/degradation signal — only a real reported bad state should');
supportGatewayHealthCheck($summary->toArray()['knowledgeState'] === null && $summary->toArray()['captainState'] === null, 'toArray() must honestly report null for a report that was never actually built, never fabricate a state');

// ================================================================
// Wiring: the plugin's real gathering method must exist, must never
// call a live Chatwoot/Captain HTTP client, and the repository must
// expose the real countByStatus() the aggregator's dead-letter signal
// depends on.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
supportGatewayHealthCheck(str_contains($pluginSource, 'function supportGatewayHealthSummary('), 'the plugin must implement a real supportGatewayHealthSummary() gathering method');
$methodStart = strpos($pluginSource, 'function supportGatewayHealthSummary(');
$methodBody = substr($pluginSource, $methodStart, (int) strpos($pluginSource, "\n    }\n", $methodStart) - $methodStart);
supportGatewayHealthCheck(str_contains($methodBody, 'SupportGatewayHealthAggregator::build('), 'the gathering method must delegate its overall-state decision to the real pure aggregator, never re-implement the rule inline');
supportGatewayHealthCheck(!str_contains($methodBody, 'ChatwootApiService') && !str_contains($methodBody, 'new \\GuzzleHttp'), 'the health-gathering method must never construct a live Chatwoot HTTP client — every signal must come from a local/OJS-internal read');
supportGatewayHealthCheck(str_contains($methodBody, '->getAirixSubmissionFeeProvider($context)') && str_contains($methodBody, '->health($context)'), 'payment provider health must come from the real provider\'s own context-level health() check, never a fabricated status');

$repoInterfaceSource = (string) file_get_contents($root . '/classes/v2/Contracts/SupportEventQueueRepositoryInterface.php');
supportGatewayHealthCheck(str_contains($repoInterfaceSource, 'public function countByStatus(string $status): int;'), 'the queue repository interface must declare the real countByStatus() method the dead-letter signal depends on');

$repoImplSource = (string) file_get_contents($root . '/classes/v2/Event/DatabaseSupportEventQueueRepository.php');
supportGatewayHealthCheck(str_contains($repoImplSource, "DB::table(self::table())->where('status', \$status)->count()"), 'the real repository must implement countByStatus() as a real count query, never a fabricated/hardcoded value');

$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
supportGatewayHealthCheck(str_contains($templateSource, '$supportGatewayHealth'), 'the settings template must actually render the real health summary, not a placeholder');

fwrite(STDOUT, "Support Gateway health tests passed\n");
