<?php

declare(strict_types=1);

namespace PKP\plugins {
    /** Same minimal in-memory GenericPlugin double established by tests/v2/settings-small-002-export-import-completeness.php. */
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
    use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

    function settingsRegistryCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * UX-024: this is the automated drift guard `SettingsRegistry`'s own
     * docblock promises — it fails the moment the registry disagrees with
     * any of the pre-existing, independently-maintained key/type lists
     * (SETTINGS-SMALL-002 already found one such drift once). This does
     * NOT migrate any consumer to read from the registry yet; it only
     * proves the registry accurately describes what those consumers
     * already do, so a future migration PR has a safety net.
     */

    // ================================================================
    // Part 1: registry internal consistency.
    // ================================================================
    $all = SettingsRegistry::all();
    settingsRegistryCheck(count($all) > 0, 'the registry must declare at least one setting');
    settingsRegistryCheck(count(array_unique(array_keys($all))) === count($all), 'the registry must never declare the same key twice');

    foreach ($all as $key => $definition) {
        settingsRegistryCheck($definition->key === $key, "definition for '{$key}' must have a matching ->key property");
        settingsRegistryCheck(in_array($definition->type, ['string', 'int', 'bool'], true), "'{$key}' must declare a real type (string/int/bool)");
    }

    settingsRegistryCheck(!in_array('mcpServiceToken', SettingsRegistry::exportableKeys(), true), 'mcpServiceToken must never be exportable (ADR-021)');
    settingsRegistryCheck(in_array('mcpServiceToken', array_keys($all), true), 'mcpServiceToken must still be a known, secret-typed setting even though it is not exportable');
    settingsRegistryCheck(SettingsRegistry::get('mcpServiceToken')?->secret === true, 'mcpServiceToken must be marked secret');

    // ================================================================
    // Part 2: ChatwootIntegrationBasePlugin::exportKeys() (drives
    // import/saveGlobalProfile/applyGlobalProfile) must delegate
    // directly to the registry, and the real, executable
    // guessSettingType() behavior must agree with it.
    // ================================================================
    $v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    settingsRegistryCheck(!str_contains($v1Source, 'private const EXPORT_KEYS'), 'ChatwootIntegrationBasePlugin must no longer maintain its own EXPORT_KEYS list');
    $v1ExportKeysStart = strpos($v1Source, 'function exportKeys()');
    settingsRegistryCheck($v1ExportKeysStart !== false, 'ChatwootIntegrationBasePlugin must declare exportKeys()');
    settingsRegistryCheck(str_contains(substr($v1Source, $v1ExportKeysStart, 150), 'SettingsRegistry::exportableKeys()'), 'exportKeys() must delegate to SettingsRegistry::exportableKeys() — drift');

    $v1ExportKeys = SettingsRegistry::exportableKeys();

    $plugin = new ChatwootIntegrationBasePlugin();
    $guessSettingType = new \ReflectionMethod($plugin, 'guessSettingType');
    foreach ($v1ExportKeys as $key) {
        $registryType = SettingsRegistry::type($key);
        $realType = $guessSettingType->invoke($plugin, $key);
        settingsRegistryCheck($registryType === $realType, "registry says '{$key}' is type '{$registryType}' but the real guessSettingType() says '{$realType}' — drift");
    }

    // ================================================================
    // Part 3: ChatwootIntegrationV2Plugin::legacyExportKeys() (drives
    // export) must delegate directly to the registry — UX-024 migrated
    // this off its own independently-maintained key list.
    // ================================================================
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    settingsRegistryCheck(!str_contains($v2Source, 'LEGACY_EXPORT_KEYS'), 'ChatwootIntegrationV2Plugin must no longer maintain its own LEGACY_EXPORT_KEYS list');
    $legacyExportKeysStart = strpos($v2Source, 'function legacyExportKeys()');
    settingsRegistryCheck($legacyExportKeysStart !== false, 'ChatwootIntegrationV2Plugin must declare legacyExportKeys()');
    $legacyExportKeysBody = substr($v2Source, $legacyExportKeysStart, 200);
    settingsRegistryCheck(str_contains($legacyExportKeysBody, 'SettingsRegistry::exportableKeys()'), 'legacyExportKeys() must delegate to SettingsRegistry::exportableKeys() — drift');

    // ================================================================
    // Part 4: ChatwootSettingsForm::secretKeys() must delegate directly
    // to the registry, and initData()/readInputData()/execute() must
    // all be driven by SettingsRegistry::keys() (no separate hand-
    // maintained key lists or role loops left).
    // ================================================================
    $formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
    settingsRegistryCheck(!str_contains($formSource, 'private const SECRET_KEYS'), 'ChatwootSettingsForm must no longer maintain its own SECRET_KEYS list');
    $formSecretKeysStart = strpos($formSource, 'function secretKeys()');
    settingsRegistryCheck($formSecretKeysStart !== false, 'ChatwootSettingsForm must declare secretKeys()');
    settingsRegistryCheck(str_contains(substr($formSource, $formSecretKeysStart, 150), 'SettingsRegistry::secretKeys()'), 'ChatwootSettingsForm::secretKeys() must delegate to SettingsRegistry::secretKeys() — drift');

    settingsRegistryCheck(substr_count($formSource, 'SettingsRegistry::keys()') >= 3, 'initData()/readInputData()/execute() must all iterate SettingsRegistry::keys() directly, not a separately-maintained list');
    settingsRegistryCheck(!str_contains($formSource, 'ROLE_ID_MANAGER'), 'ChatwootSettingsForm must no longer loop over Role::ROLE_ID_* separately — those keys are already in SettingsRegistry::keys()');

    // ================================================================
    // Part 4b: ExportPolicy::sensitiveKeys() now IS SettingsRegistry::secretKeys()
    // — a real consumer migration, not just a drift guard.
    // ================================================================
    $exportPolicySensitive = ExportPolicy::sensitiveKeys();
    sort($exportPolicySensitive);
    $registrySecretKeysAgain = SettingsRegistry::secretKeys();
    sort($registrySecretKeysAgain);
    settingsRegistryCheck($exportPolicySensitive === $registrySecretKeysAgain, 'ExportPolicy::sensitiveKeys() must be exactly SettingsRegistry::secretKeys()');

    // ================================================================
    // Part 5: HAR-008 fact recorded, not yet enforced — every
    // non-global-eligible key must still be a real, known, exportable
    // (per-journal) setting; this registry does not yet change
    // saveGlobalProfile()/applyGlobalProfile() runtime behavior.
    // ================================================================
    foreach (SettingsRegistry::nonGlobalEligibleKeys() as $key) {
        settingsRegistryCheck(in_array($key, SettingsRegistry::exportableKeys(), true), "'{$key}' is marked non-global-eligible but must still be exportable/per-journal");
        settingsRegistryCheck(SettingsRegistry::get($key)?->secret === true, "'{$key}' is marked non-global-eligible for HAR-008 — expected only trust-plane secrets here");
    }

    fwrite(STDOUT, "Settings registry drift-guard tests passed\n");
}
