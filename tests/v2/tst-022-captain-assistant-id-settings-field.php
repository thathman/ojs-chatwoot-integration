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
require_once "{$root}/classes/v2/Settings/SettingDefinition.php";
require_once "{$root}/classes/v2/Settings/SettingsRegistry.php";

// UX-024: ChatwootSettingsForm's initData()/readInputData()/execute()
// no longer hardcode any key list — they all iterate
// SettingsRegistry::keys()/::type() directly, so chatwootCaptainAssistantId
// being wired through the form lifecycle is now proven by it being a
// real registry entry with the right type/secret classification.
$formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
tst022Check(!str_contains($formSource, 'private const SECRET_KEYS'), 'ChatwootSettingsForm must no longer maintain its own SECRET_KEYS list');
tst022Check(substr_count($formSource, 'SettingsRegistry::keys()') >= 3, 'initData()/readInputData()/execute() must all iterate SettingsRegistry::keys() directly');

tst022Check(
    in_array('chatwootCaptainAssistantId', \APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::keys(), true),
    'chatwootCaptainAssistantId must be a real registry key, wired through the form lifecycle'
);
tst022Check(
    \APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::type('chatwootCaptainAssistantId') === 'int',
    'chatwootCaptainAssistantId must be saved as an int, matching how the plugin reads it via (int) v2EffectiveSetting(...)'
);
tst022Check(
    !in_array('chatwootCaptainAssistantId', \APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::secretKeys(), true),
    'chatwootCaptainAssistantId is a public numeric ID, not a secret — must never be marked secret (which would needlessly force re-entry on every save)'
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

// UX-024: LEGACY_EXPORT_KEYS is gone — legacyExportKeys() now
// delegates directly to SettingsRegistry::exportableKeys(), so
// checking the single shared source is the correct-tier assertion.
// chatwootCaptainAssistantId (a public numeric ID, safe to
// export/import like chatwootInboxId) must be exportable; mcpServiceToken
// must still never be (an already-tested design decision,
// tests/v2/settings-form-mcp-secret-masking.php) — this fix must not
// regress that already-tested guarantee.
tst022Check(!str_contains($pluginSource, 'LEGACY_EXPORT_KEYS'), 'ChatwootIntegrationV2Plugin must no longer maintain its own LEGACY_EXPORT_KEYS list');
tst022Check(
    in_array('chatwootCaptainAssistantId', \APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::exportableKeys(), true),
    'chatwootCaptainAssistantId must be exportable — it is a public numeric ID, safe to export/import like chatwootInboxId'
);
tst022Check(
    !in_array('mcpServiceToken', \APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::exportableKeys(), true),
    'mcpServiceToken must still never be exportable — this fix must not regress that already-tested guarantee'
);

fwrite(STDOUT, "PASS: tst-022-captain-assistant-id-settings-field\n");
