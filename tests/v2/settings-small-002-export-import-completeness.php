<?php

declare(strict_types=1);

namespace PKP\plugins {
    /**
     * Same minimal in-memory GenericPlugin double established by
     * tests/v2/fnd-006-v1-retry-queue.php — close enough for the real
     * getSetting()/updateSetting() calls this test's reflected methods
     * (guessSettingType, isImportValueSafe) touch indirectly.
     */
    class GenericPlugin
    {
        /** @var array<int,array<string,mixed>> */
        public array $settings = [];

        public function getSetting($contextId, $key)
        {
            return $this->settings[(int) $contextId][(string) $key] ?? null;
        }

        public function updateSetting($contextId, $key, $value, $type = null)
        {
            $this->settings[(int) $contextId][(string) $key] = $value;
        }

        public function getEnabled($contextId = null)
        {
            return true;
        }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

    function settings002Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // SETTINGS-SMALL-002/UX-024: exportSettings() (v2, legacyExportKeys())
    // and importSettings()/saveGlobalProfile()/applyGlobalProfile() (v1,
    // inherited unchanged by v2, exportKeys()) previously used two
    // independently-maintained key lists that had drifted apart —
    // widgetSettingsJson, eventDeliveryGlobalMode,
    // eventDeliveryCustomerMessageConsent, eventDeliveryPerEventOverridesJson,
    // and chatwootCaptainAssistantId/chatwootSupportApiToken were only
    // in one list or the other. Both now delegate directly to the same
    // canonical `SettingsRegistry::exportableKeys()` (UX-024), so they
    // can never drift apart again — this test proves both real methods
    // still delegate to it (drift is now structurally impossible rather
    // than merely checked), and exercises the real, executable
    // import-safety logic (isImportValueSafe) via reflection against
    // the real class.
    // ================================================================

    $v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

    settings002Check(!str_contains($v1Source, 'private const EXPORT_KEYS'), 'v1 must no longer maintain its own EXPORT_KEYS list');
    $v1ExportKeysStart = strpos($v1Source, 'function exportKeys()');
    settings002Check($v1ExportKeysStart !== false, 'v1 must declare exportKeys()');
    settings002Check(str_contains(substr($v1Source, $v1ExportKeysStart, 150), 'SettingsRegistry::exportableKeys()'), "v1's exportKeys() must delegate to SettingsRegistry::exportableKeys()");

    settings002Check(!str_contains($v2Source, 'LEGACY_EXPORT_KEYS'), 'v2 must no longer maintain its own LEGACY_EXPORT_KEYS list');
    $v2ExportKeysStart = strpos($v2Source, 'function legacyExportKeys()');
    settings002Check($v2ExportKeysStart !== false, 'v2 must declare legacyExportKeys()');
    settings002Check(str_contains(substr($v2Source, $v2ExportKeysStart, 150), 'SettingsRegistry::exportableKeys()'), "v2's legacyExportKeys() must delegate to SettingsRegistry::exportableKeys()");

    $newKeys = [
        'chatwootCaptainAssistantId',
        'chatwootSupportApiToken',
        'widgetSettingsJson',
        'eventDeliveryGlobalMode',
        'eventDeliveryCustomerMessageConsent',
        'eventDeliveryPerEventOverridesJson',
    ];
    foreach ($newKeys as $key) {
        settings002Check(
            in_array($key, SettingsRegistry::exportableKeys(), true),
            "SettingsRegistry must mark '{$key}' exportable — otherwise an export→import round-trip silently drops it"
        );
    }

    // mcpServiceToken must never be exportable (ADR-021, already
    // covered by tests/v2/settings-form-mcp-secret-masking.php —
    // re-asserted here as a same-file regression guard).
    settings002Check(!in_array('mcpServiceToken', SettingsRegistry::exportableKeys(), true), 'mcpServiceToken must never be exportable');

    // --- Real, executable behavior: guessSettingType() ---
    $plugin = new ChatwootIntegrationBasePlugin();
    $guessSettingType = new \ReflectionMethod($plugin, 'guessSettingType');

    settings002Check($guessSettingType->invoke($plugin, 'chatwootCaptainAssistantId') === 'int', 'chatwootCaptainAssistantId must be imported/propagated as int, matching how the settings form saves it');
    settings002Check($guessSettingType->invoke($plugin, 'eventDeliveryCustomerMessageConsent') === 'bool', 'eventDeliveryCustomerMessageConsent must be imported/propagated as bool');
    settings002Check($guessSettingType->invoke($plugin, 'widgetSettingsJson') === 'string', 'widgetSettingsJson must be imported/propagated as string (raw JSON blob)');
    settings002Check($guessSettingType->invoke($plugin, 'eventDeliveryPerEventOverridesJson') === 'string', 'eventDeliveryPerEventOverridesJson must be imported/propagated as string (raw JSON blob)');

    // --- Real, executable behavior: isImportValueSafe() must fail closed on malformed import data ---
    $isImportValueSafe = new \ReflectionMethod($plugin, 'isImportValueSafe');

    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryCustomerMessageConsent', true) === true, 'a real JSON boolean true must be accepted for eventDeliveryCustomerMessageConsent');
    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryCustomerMessageConsent', false) === true, 'a real JSON boolean false must be accepted for eventDeliveryCustomerMessageConsent');
    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryCustomerMessageConsent', 'true') === false, 'a truthy STRING must be rejected for eventDeliveryCustomerMessageConsent — a naive (bool) cast would otherwise let a malformed import silently enable customer-visible message delivery');
    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryCustomerMessageConsent', 1) === false, 'a truthy INT must be rejected for eventDeliveryCustomerMessageConsent — only a real JSON boolean is safe');

    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryPerEventOverridesJson', '') === true, 'an empty string must be accepted for eventDeliveryPerEventOverridesJson (a safe "no overrides" value)');
    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryPerEventOverridesJson', '{"1":"privateNote"}') === true, 'valid JSON must be accepted for eventDeliveryPerEventOverridesJson');
    settings002Check($isImportValueSafe->invoke($plugin, 'eventDeliveryPerEventOverridesJson', '{not valid json') === false, 'malformed JSON must be rejected for eventDeliveryPerEventOverridesJson rather than being stored broken and failing later at read time');

    settings002Check($isImportValueSafe->invoke($plugin, 'chatwootBaseUrl', 'https://example.com') === true, 'ordinary string settings must remain unaffected by the new validation');

    fwrite(STDOUT, "PASS: settings-small-002-export-import-completeness\n");
}
