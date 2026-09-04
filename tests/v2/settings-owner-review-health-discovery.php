<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function ownerReviewCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Owner browser review 2026-09-04 (docs/v2/SETTINGS_CONSOLE_OWNER_REVIEW_HEALTH_DISCOVERY.md):
 * three real defects found in the shipped Settings Console — raw JSON
 * in Health Check/Captain Sync results, and discovered resource names
 * not surviving a settings-modal reload. This is the drift guard for
 * all three fixes.
 */
$tpl = (string) file_get_contents($root . '/templates/settingsForm.tpl');
$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');

// ================================================================
// Finding #1/#4: no raw JSON.stringify() for Health Check/Captain
// Sync/Retry Dead Letters results — those three response bodies are
// the only ones this finding covers (Export/Import genuinely are JSON
// by design and must keep using JSON.stringify()).
// ================================================================
ownerReviewCheck(str_contains($tpl, 'cwFormatHealthCheck'), 'Health Check must format its result through a real human-text function, not JSON.stringify()');
ownerReviewCheck(str_contains($tpl, 'cwFormatCaptainSync'), 'Captain Sync must format its result through a real human-text function, not JSON.stringify()');
$healthCheckWireStart = strpos($tpl, "cwWireAction('chatwootHealthCheckBtn'");
$healthCheckWireEnd = strpos($tpl, '});', $healthCheckWireStart);
ownerReviewCheck(!str_contains(substr($tpl, $healthCheckWireStart, $healthCheckWireEnd - $healthCheckWireStart), 'JSON.stringify'), 'chatwootHealthCheckBtn must never call JSON.stringify() on its own result');
$captainSyncWireStart = strpos($tpl, "cwWireAction('chatwootSyncCaptainBtn'");
$captainSyncWireEnd = strpos($tpl, '});', $captainSyncWireStart);
ownerReviewCheck(!str_contains(substr($tpl, $captainSyncWireStart, $captainSyncWireEnd - $captainSyncWireStart), 'JSON.stringify'), 'chatwootSyncCaptainBtn must never call JSON.stringify() on its own result');
$retryWireStart = strpos($tpl, "cwWireAction('chatwootRetryDeadLettersBtn'");
$retryWireEnd = strpos($tpl, '});', $retryWireStart);
ownerReviewCheck(!str_contains(substr($tpl, $retryWireStart, $retryWireEnd - $retryWireStart), 'JSON.stringify'), 'chatwootRetryDeadLettersBtn must never call JSON.stringify() on its own result');

// ================================================================
// Finding #3: discovered resource names must be real, persisted
// SettingsRegistry keys — not just transient DOM state populated by
// JS and lost on reload.
// ================================================================
foreach (['chatwootAccountName', 'chatwootInboxName', 'chatwootCaptainAssistantName', 'chatwootDiscoveryVerifiedAt'] as $key) {
    $definition = SettingsRegistry::get($key);
    ownerReviewCheck($definition !== null, "'{$key}' must be a real SettingsRegistry key so a discovered name survives save/reload");
    ownerReviewCheck($definition->exportable === false, "'{$key}' must be exportable:false — safe cached display metadata, meaningless without the live account it was verified against");
    ownerReviewCheck($definition->secret === false, "'{$key}' must never be marked secret — it is a human-readable display name/timestamp, not a credential");
    ownerReviewCheck(str_contains($tpl, "id=\"{$key}\""), "settingsForm.tpl must render a real form field with id=\"{$key}\" so it round-trips through the normal save cycle");
}

// The JS that captures a select's chosen name into its hidden field on
// every real change — not only right after a fresh discovery run —
// so a manual selection (not just a freshly discovered one) is also
// captured before save.
ownerReviewCheck(str_contains($tpl, 'cwSyncSelectName'), 'a shared name-capture helper must exist so both selects stay in sync with their hidden name fields');
ownerReviewCheck(
    (bool) preg_match('/\$\(\'#chatwootInboxId\'\)\.on\(\'change\'/', $tpl) && (bool) preg_match('/\$\(\'#chatwootCaptainAssistantId\'\)\.on\(\'change\'/', $tpl),
    'both the Website Inbox and Captain Assistant selects must capture their chosen name on every real change event, not only immediately after discovery'
);

// A pre-existing install with only a numeric ID cached (no name yet)
// must show a transitional label, never claim "Not tested yet" for a
// resource that already has a real saved selection.
ownerReviewCheck(str_contains($tpl, 'discover.savedNeedsVerify'), 'a saved-ID-but-no-cached-name resource must show a transitional "saved, needs verify" label, not "Not tested yet"');
ownerReviewCheck(str_contains($tpl, 'discover.lastVerifiedPrefix'), 'a resource with a cached name must show when it was last verified');

// ================================================================
// The overview banner: one human sentence, never using the reserved
// {translate} "count" param (see locale-placeholder-substitution-syntax.php
// — this codebase already found that exact bug once this session).
// ================================================================
ownerReviewCheck(str_contains($formSource, 'overviewNeedsAttentionLabels'), 'ChatwootSettingsForm must compute a real needs-attention list for the overview banner');
ownerReviewCheck(str_contains($tpl, 'cwOverviewBanner'), 'the Overview tab must render one human banner sentence, not just the card grid alone');
ownerReviewCheck(!preg_match('/\{translate[^}]*\bcount=/', $tpl), 'no {translate} call anywhere in this template may pass the reserved "count" param as an ordinary value');

fwrite(STDOUT, "Owner review (health/discovery) tests passed\n");
