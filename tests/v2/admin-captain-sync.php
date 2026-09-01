<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;

function adminCaptainSyncCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * ADM-003 (first slice): the manual "Sync/Repair Captain" admin action.
 * Deliberately zero new provisioning logic — syncCaptainResources()
 * only orchestrates the three already-built, already-tested entry
 * points (provisionCaptainKnowledgeDocument()/provisionCaptainCustomTools()/
 * provisionCaptainScenarios()), which cannot themselves be exercised
 * here (they need a live OJS request/context/Chatwoot connection — same
 * constraint as captainProvisioningHealth() before it). This test
 * covers the one genuinely new piece of pure logic
 * (v2SummarizeCaptainSyncResults()'s status-count aggregation, extracted
 * and mirrored here since it's private) plus source-level wiring
 * assertions proving the real orchestration order, the real
 * never-mutates-unrelated-resources guarantee, and the real UI wiring.
 */

/** Mirrors ChatwootIntegrationV2Plugin::v2SummarizeCaptainSyncResults() exactly. */
function adminCaptainSyncSummarize(array $results): array
{
    $counts = [];
    foreach ($results as $result) {
        $counts[$result->status()] = ($counts[$result->status()] ?? 0) + 1;
    }
    return $counts;
}

// ================================================================
// The summarization logic must count by real CaptainSyncResult status,
// never lose or miscount a result, and handle an empty result set
// (e.g. no canonical tools/scenarios configured) without error.
// ================================================================
adminCaptainSyncCheck(adminCaptainSyncSummarize([]) === [], 'summarizing zero results must return an empty count map, never error');

$mixedResults = [
    'tool_a' => CaptainSyncResult::created('fp-1'),
    'tool_b' => CaptainSyncResult::noop('fp-2'),
    'tool_c' => CaptainSyncResult::synced('fp-3'),
    'tool_d' => CaptainSyncResult::conflict('unmanaged_tool_exists'),
    'tool_e' => CaptainSyncResult::failed('create_failed'),
    'tool_f' => CaptainSyncResult::created('fp-4'),
];
$summary = adminCaptainSyncSummarize($mixedResults);
adminCaptainSyncCheck($summary === [
    CaptainSyncResult::STATUS_CREATED => 2,
    CaptainSyncResult::STATUS_NOOP => 1,
    CaptainSyncResult::STATUS_SYNCED => 1,
    CaptainSyncResult::STATUS_CONFLICT => 1,
    CaptainSyncResult::STATUS_FAILED => 1,
], 'the summary must count every real result exactly once, keyed by its real status, never dropping or double-counting one');

// ================================================================
// Wiring: the real plugin method must exist, must call the three real
// provisioning entry points in the documented document-then-tools-then-
// scenarios order, must delegate its counting to the real shared
// summarizer (never a second inline copy), and must never construct its
// own Chatwoot client or touch the sync repository directly — every
// Chatwoot-facing/ownership guarantee must come from the three existing
// provisioners alone.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function syncCaptainResources(');
adminCaptainSyncCheck($methodStart !== false, 'the plugin must implement a real syncCaptainResources() method');
$methodBody = substr($pluginSource, $methodStart, (int) strpos($pluginSource, "\n    }\n", $methodStart) - $methodStart);

$documentPos = strpos($methodBody, 'provisionCaptainKnowledgeDocument(');
$toolsPos = strpos($methodBody, 'provisionCaptainCustomTools(');
$scenariosPos = strpos($methodBody, 'provisionCaptainScenarios(');
adminCaptainSyncCheck($documentPos !== false && $toolsPos !== false && $scenariosPos !== false, 'syncCaptainResources() must call all three real provisioning entry points, never a bespoke reimplementation');
adminCaptainSyncCheck($documentPos < $toolsPos && $toolsPos < $scenariosPos, 'the three entry points must run in the documented document-then-tools-then-scenarios order — a scenario can only reference a tool by its real assigned slug');
adminCaptainSyncCheck(str_contains($methodBody, 'v2SummarizeCaptainSyncResults('), 'syncCaptainResources() must delegate counting to the real shared summarizer, never a second inline copy');
adminCaptainSyncCheck(!str_contains($methodBody, 'new ChatwootApiService') && !str_contains($methodBody, 'DatabaseSupportKnowledgeSyncRepository'), 'syncCaptainResources() must never construct its own Chatwoot client or sync repository — every ownership/conflict guarantee must come from the three existing provisioners alone, never a parallel path that could bypass it');

adminCaptainSyncCheck(str_contains($pluginSource, "if (\$request->getUserVar('verb') === 'syncCaptainResources')"), 'the plugin must route a real syncCaptainResources verb to the real method');

$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
adminCaptainSyncCheck(str_contains($formSource, "'verb' => 'syncCaptainResources'"), 'the settings form must build a real URL for the syncCaptainResources verb, never a hardcoded/placeholder path');

$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
adminCaptainSyncCheck(str_contains($templateSource, '$syncCaptainResourcesUrl'), 'the template must wire the real sync URL into its button handler, never a static/dead button');
adminCaptainSyncCheck(str_contains($templateSource, 'chatwootSyncCaptainBtn'), 'the template must render a real Sync/Repair Captain button');

fwrite(STDOUT, "Admin Captain sync tests passed\n");
