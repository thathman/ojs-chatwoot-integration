<?php

declare(strict_types=1);

// ================================================================
// TST-022: real acceptance testing (SETTINGS-001, the owner's settings-
// reconciliation mandate) found that `chatwootCaptainAssistantId` — a
// setting `provisionCaptainKnowledgeDocument()`/`provisionCaptainCustomTools()`
// both read as a hard requirement (Captain provisioning silently returns
// null whenever it's <= 0, i.e. its unset default) — had no field
// anywhere: not in ChatwootSettingsForm's initData()/readInputData()/
// execute() key lists, not in settingsForm.tpl, not in LEGACY_EXPORT_KEYS.
// There was no way for a real Journal Manager to ever configure it
// through the admin UI, direct DB access aside — meaning Sync/Repair
// Captain has always silently no-op'd in every real deployment. Confirmed
// live: the real "OJS Demo (AJDSI)" Chatwoot inbox has no Captain
// assistant bound to it, and this is exactly why.
//
// This test asserts, against the real source tree, that the setting is
// now wired through every real layer: form data lifecycle, template
// field, and settings-type map.
// ================================================================

function tst022Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
tst022Check(
    substr_count($formSource, "'chatwootCaptainAssistantId'") >= 3,
    'chatwootCaptainAssistantId must appear in initData(), readInputData(), and the execute() settings-type map (at least 3 real occurrences)'
);
tst022Check(
    str_contains($formSource, "'chatwootCaptainAssistantId' => 'int'"),
    'chatwootCaptainAssistantId must be saved as an int, matching how the plugin reads it via (int) v2EffectiveSetting(...)'
);
$secretKeysStart = strpos($formSource, 'private const SECRET_KEYS');
tst022Check($secretKeysStart !== false, 'ChatwootSettingsForm must declare SECRET_KEYS');
$secretKeysEnd = strpos($formSource, '];', $secretKeysStart);
$secretKeysBlock = substr($formSource, $secretKeysStart, $secretKeysEnd - $secretKeysStart);
tst022Check(
    !str_contains($secretKeysBlock, "'chatwootCaptainAssistantId'"),
    'chatwootCaptainAssistantId is a public numeric ID, not a secret — must never be added to SECRET_KEYS (which would needlessly force re-entry on every save)'
);

$templateSource = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
tst022Check(
    str_contains($templateSource, 'id="chatwootCaptainAssistantId"'),
    'settingsForm.tpl must render a real chatwootCaptainAssistantId field — otherwise the settings-lifecycle wiring above has nothing for a real admin to actually fill in'
);

$pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
tst022Check(
    substr_count($pluginSource, "v2EffectiveSetting(\$contextId, 'chatwootCaptainAssistantId', 0)") >= 2,
    'Captain Document and Custom Tool provisioning must both still require chatwootCaptainAssistantId (proves this test checks the same key provisioning actually needs, not a renamed/orphaned one)'
);

// mcpServiceToken must remain excluded from LEGACY_EXPORT_KEYS (an
// already-tested design decision, tests/v2/settings-form-mcp-secret-
// masking.php) — this test does not weaken that guarantee while adding
// chatwootCaptainAssistantId to the same list.
$legacyExportKeysStart = strpos($pluginSource, 'LEGACY_EXPORT_KEYS = [');
tst022Check($legacyExportKeysStart !== false, 'the plugin must declare LEGACY_EXPORT_KEYS');
$legacyExportKeysEnd = strpos($pluginSource, '];', $legacyExportKeysStart);
$legacyExportKeysBlock = substr($pluginSource, $legacyExportKeysStart, $legacyExportKeysEnd - $legacyExportKeysStart);
tst022Check(
    str_contains($legacyExportKeysBlock, "'chatwootCaptainAssistantId'"),
    'chatwootCaptainAssistantId must be included in LEGACY_EXPORT_KEYS — it is a public numeric ID, safe to export/import like chatwootInboxId'
);
tst022Check(
    !str_contains($legacyExportKeysBlock, "'mcpServiceToken'"),
    'mcpServiceToken must still never appear in LEGACY_EXPORT_KEYS — this fix must not regress that already-tested guarantee'
);

fwrite(STDOUT, "PASS: tst-022-captain-assistant-id-settings-field\n");
