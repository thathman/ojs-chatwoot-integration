<?php

declare(strict_types=1);

// ================================================================
// ADM-012: `launcherBottomOffset` was a real, confirmed-dead setting
// (docs/v2/V1_INVENTORY.md FND-004) — saved by the settings form,
// loaded by the settings form, rendered as a real admin input field,
// but never read anywhere in the widget-injection code that would
// need to apply it. It had zero runtime effect: a placebo setting.
//
// No Product Bible requirement names a launcher position/offset
// control (verified: PRODUCT_BIBLE.md's only "launcher" reference is
// "contextual launcher intent", an unrelated behavioral concept), so
// this closes ADM-012/SETTINGS-SMALL-001 by REMOVAL rather than
// wiring it up. Existing stored values for old installs become a
// harmless orphaned `plugin_settings` row — never read, never
// migrated, no data-loss risk. It was never present in
// EXPORT_KEYS/LEGACY_EXPORT_KEYS, so this removal cannot break
// existing settings-backup import/export compatibility.
//
// This test asserts, against the real source tree, that the setting
// key is now gone from every real runtime location: the settings
// form's data lifecycle, the settings template, and the locale file.
// ================================================================

function adm012Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
adm012Check(
    !str_contains($formSource, 'launcherBottomOffset'),
    'launcherBottomOffset must be fully removed from ChatwootSettingsForm.php (initData/readInputData/execute) — a dead setting must not remain reachable through the form data lifecycle'
);

$templateSource = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
adm012Check(
    !str_contains($templateSource, 'launcherBottomOffset'),
    'launcherBottomOffset must be fully removed from settingsForm.tpl — no dead field may remain in the admin UI'
);

$localeSource = (string) file_get_contents("{$root}/locale/en/locale.po");
adm012Check(
    !str_contains($localeSource, 'launcherBottomOffset'),
    'launcherBottomOffset locale strings must be removed — no orphaned translation for a removed setting'
);

$v2PluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
adm012Check(
    !str_contains($v2PluginSource, 'launcherBottomOffset'),
    'launcherBottomOffset must never be reintroduced into the v2 plugin (e.g. LEGACY_EXPORT_KEYS) now that it is removed'
);

$v1PluginSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
adm012Check(
    !str_contains($v1PluginSource, 'launcherBottomOffset'),
    'launcherBottomOffset must never be reintroduced into the v1 plugin (e.g. EXPORT_KEYS) now that it is removed'
);

fwrite(STDOUT, "PASS: adm-012-launcher-offset-removed\n");
