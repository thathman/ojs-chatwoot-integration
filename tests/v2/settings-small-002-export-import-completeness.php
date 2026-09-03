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

    function settings002Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // SETTINGS-SMALL-002: exportSettings() (v2, LEGACY_EXPORT_KEYS) and
    // importSettings()/saveGlobalProfile()/applyGlobalProfile() (v1,
    // inherited unchanged by v2, EXPORT_KEYS) previously used two
    // independently-maintained key lists that had drifted apart —
    // widgetSettingsJson, eventDeliveryGlobalMode,
    // eventDeliveryCustomerMessageConsent, eventDeliveryPerEventOverridesJson,
    // and chatwootCaptainAssistantId/chatwootSupportApiToken were only
    // in one list or the other. A real export → import round-trip
    // would silently drop whichever of these keys weren't in the
    // *import* list, even though they were genuinely exported.
    //
    // This test proves, against the real source tree, that both lists
    // now agree on every one of these keys (source-level: the two
    // constants live in different classes/files, so a single
    // reflection call can't compare them directly — string assertion
    // against both real files is the correct-tier check here), and
    // exercises the real, executable import-safety logic
    // (isImportValueSafe) via reflection against the real class.
    // ================================================================

    $v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

    $newKeys = [
        'chatwootCaptainAssistantId',
        'chatwootSupportApiToken',
        'widgetSettingsJson',
        'eventDeliveryGlobalMode',
        'eventDeliveryCustomerMessageConsent',
        'eventDeliveryPerEventOverridesJson',
    ];

    $v1ExportKeysStart = strpos($v1Source, 'private const EXPORT_KEYS');
    settings002Check($v1ExportKeysStart !== false, 'v1 must declare EXPORT_KEYS');
    $v1ExportKeysBlock = substr($v1Source, $v1ExportKeysStart, (int) strpos($v1Source, '];', $v1ExportKeysStart) - $v1ExportKeysStart);

    $v2ExportKeysStart = strpos($v2Source, 'LEGACY_EXPORT_KEYS = [');
    settings002Check($v2ExportKeysStart !== false, 'v2 must declare LEGACY_EXPORT_KEYS');
    $v2ExportKeysBlock = substr($v2Source, $v2ExportKeysStart, (int) strpos($v2Source, '];', $v2ExportKeysStart) - $v2ExportKeysStart);

    foreach ($newKeys as $key) {
        settings002Check(
            str_contains($v1ExportKeysBlock, "'{$key}'"),
            "v1's EXPORT_KEYS (import/saveGlobalProfile/applyGlobalProfile) must include '{$key}' — otherwise an export→import round-trip silently drops it"
        );
        settings002Check(
            str_contains($v2ExportKeysBlock, "'{$key}'"),
            "v2's LEGACY_EXPORT_KEYS (export) must include '{$key}' — it is a real, non-secret, UI-configurable setting"
        );
    }

    // mcpServiceToken must still never appear in either list (ADR-021,
    // already covered by tests/v2/settings-form-mcp-secret-masking.php
    // — re-asserted here as a same-file regression guard for this PR).
    settings002Check(!str_contains($v1ExportKeysBlock, "'mcpServiceToken'"), 'mcpServiceToken must never appear in v1 EXPORT_KEYS');
    settings002Check(!str_contains($v2ExportKeysBlock, "'mcpServiceToken'"), 'mcpServiceToken must never appear in v2 LEGACY_EXPORT_KEYS');

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
