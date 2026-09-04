<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\McpToolCatalog;

function mcpToolCatalogCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Settings Console item H (API & MCP tab, owner directive 2026-09-04):
 * "capabilities/tools/resources summary" — McpToolCatalog is the one
 * real source of truth the admin UI reads. This is its drift guard:
 * fails the moment its list disagrees with the real number of
 * `$registry->register(` calls in ChatwootIntegrationV2Plugin::mcpRequest(),
 * exactly the discipline SettingsRegistry's own drift-guard test
 * already established for setting keys.
 */
$pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
$mcpRequestStart = strpos($pluginSource, 'function mcpRequest(');
mcpToolCatalogCheck($mcpRequestStart !== false, 'mcpRequest() must exist');
$mcpRequestEnd = strpos($pluginSource, "\n    public function ", $mcpRequestStart + 1);
$mcpRequestBody = $mcpRequestEnd !== false ? substr($pluginSource, $mcpRequestStart, $mcpRequestEnd - $mcpRequestStart) : substr($pluginSource, $mcpRequestStart);

$realRegisterCount = substr_count($mcpRequestBody, '$registry->register(');
mcpToolCatalogCheck($realRegisterCount > 0, 'sanity check: mcpRequest() must register at least one real tool');
$catalogCount = McpToolCatalog::count();
mcpToolCatalogCheck($catalogCount === $realRegisterCount, "McpToolCatalog declares {$catalogCount} tools but mcpRequest() actually registers {$realRegisterCount} — the catalog must list every real registered tool, no more, no fewer");

// Every summary must be sourced from the tool class's own real
// NAME/DESCRIPTION constants (proven by non-empty, distinct values —
// a hand-copied duplicate/stale description would either collide or
// go stale silently otherwise).
$summaries = McpToolCatalog::summaries();
mcpToolCatalogCheck(count($summaries) === McpToolCatalog::count(), 'summaries() must return exactly one entry per cataloged tool');
$names = array_column($summaries, 'name');
mcpToolCatalogCheck(count($names) === count(array_unique($names)), 'every real tool name must be unique — a duplicate would mean two catalog entries point at the same tool');
foreach ($summaries as $summary) {
    mcpToolCatalogCheck(trim($summary['name']) !== '', 'every summary must have a real, non-empty tool name');
    mcpToolCatalogCheck(trim($summary['description']) !== '', "tool '{$summary['name']}' must have a real, non-empty description");
}

// The real 15 tools known at the time this catalog was written must
// all still be present by name — catches a tool being silently
// dropped from the catalog even if the count happens to still match
// (e.g. one tool added and a different one removed in the same change).
$expectedNames = [
    'journal.get_profile',
    'journal.get_submission_policy',
    'journal.get_fee_policy',
    'identity.get_support_identity',
    'identity.request_verification',
    'identity.confirm_verification',
    'submission.get_required_actions',
    'submission.get_support_status',
    'publication.get_status',
    'payment.get_submission_status',
    'diagnostics.account',
    'diagnostics.submission',
    'support.escalate',
    'submission.list_mine',
    'capabilities.list_available',
];
sort($expectedNames);
$actualNames = $names;
sort($actualNames);
mcpToolCatalogCheck($expectedNames === $actualNames, 'McpToolCatalog must list exactly the real 15 known tool names — got: ' . implode(', ', $actualNames));

// ================================================================
// Template/form wiring: the API & MCP tab must render the real
// catalog, never a hand-typed duplicate list.
// ================================================================
$tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
$formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
mcpToolCatalogCheck(str_contains($formSource, 'McpToolCatalog::summaries()'), 'ChatwootSettingsForm must assign the real catalog summaries, never a hand-typed list');
mcpToolCatalogCheck(str_contains($tpl, '{foreach from=$mcpToolSummaries'), 'the API & MCP tab must render tools from a real $mcpToolSummaries loop');

fwrite(STDOUT, "MCP tool catalog tests passed\n");
