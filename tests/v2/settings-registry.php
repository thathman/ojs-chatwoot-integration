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
    // Part 2: registry must agree with ChatwootIntegrationBasePlugin::EXPORT_KEYS
    // (drives import/saveGlobalProfile/applyGlobalProfile) and with the
    // real, executable guessSettingType()/isImportValueSafe() behavior.
    // ================================================================
    $v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $v1ExportKeysStart = strpos($v1Source, 'private const EXPORT_KEYS');
    settingsRegistryCheck($v1ExportKeysStart !== false, 'ChatwootIntegrationBasePlugin must still declare EXPORT_KEYS');
    $v1ExportKeysBlock = substr($v1Source, $v1ExportKeysStart, (int) strpos($v1Source, '];', $v1ExportKeysStart) - $v1ExportKeysStart);
    preg_match_all("/'([a-zA-Z0-9_]+)'/", $v1ExportKeysBlock, $matches);
    $v1ExportKeys = $matches[1];

    settingsRegistryCheck(count($v1ExportKeys) > 0, 'must have parsed at least one key out of EXPORT_KEYS');
    foreach ($v1ExportKeys as $key) {
        settingsRegistryCheck(in_array($key, SettingsRegistry::exportableKeys(), true), "EXPORT_KEYS contains '{$key}' but the registry does not mark it exportable — drift");
    }
    foreach (SettingsRegistry::exportableKeys() as $key) {
        settingsRegistryCheck(in_array($key, $v1ExportKeys, true), "the registry marks '{$key}' exportable but EXPORT_KEYS does not contain it — drift");
    }

    $plugin = new ChatwootIntegrationBasePlugin();
    $guessSettingType = new \ReflectionMethod($plugin, 'guessSettingType');
    foreach ($v1ExportKeys as $key) {
        $registryType = SettingsRegistry::type($key);
        $realType = $guessSettingType->invoke($plugin, $key);
        settingsRegistryCheck($registryType === $realType, "registry says '{$key}' is type '{$registryType}' but the real guessSettingType() says '{$realType}' — drift");
    }

    // ================================================================
    // Part 3: registry must agree with ChatwootIntegrationV2Plugin::LEGACY_EXPORT_KEYS
    // (drives export) — source assertion, matching SETTINGS-SMALL-002's
    // established technique (the two constants live in different files,
    // so this can't be a direct reflection comparison).
    // ================================================================
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    $v2ExportKeysStart = strpos($v2Source, 'LEGACY_EXPORT_KEYS = [');
    settingsRegistryCheck($v2ExportKeysStart !== false, 'ChatwootIntegrationV2Plugin must still declare LEGACY_EXPORT_KEYS');
    $v2ExportKeysBlock = substr($v2Source, $v2ExportKeysStart, (int) strpos($v2Source, '];', $v2ExportKeysStart) - $v2ExportKeysStart);
    preg_match_all("/'([a-zA-Z0-9_]+)'/", $v2ExportKeysBlock, $matches);
    $v2ExportKeys = $matches[1];

    foreach ($v2ExportKeys as $key) {
        settingsRegistryCheck(in_array($key, SettingsRegistry::exportableKeys(), true), "LEGACY_EXPORT_KEYS contains '{$key}' but the registry does not mark it exportable — drift");
    }
    foreach (SettingsRegistry::exportableKeys() as $key) {
        settingsRegistryCheck(in_array($key, $v2ExportKeys, true), "the registry marks '{$key}' exportable but LEGACY_EXPORT_KEYS does not contain it — drift");
    }

    // ================================================================
    // Part 4: registry secret keys must agree with ChatwootSettingsForm::SECRET_KEYS.
    // ================================================================
    $formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
    $secretKeysStart = strpos($formSource, 'SECRET_KEYS = [');
    settingsRegistryCheck($secretKeysStart !== false, 'ChatwootSettingsForm must still declare SECRET_KEYS');
    $secretKeysBlock = substr($formSource, $secretKeysStart, (int) strpos($formSource, '];', $secretKeysStart) - $secretKeysStart);
    preg_match_all("/'([a-zA-Z0-9_]+)'/", $secretKeysBlock, $matches);
    $formSecretKeys = $matches[1];

    sort($formSecretKeys);
    $registrySecretKeys = SettingsRegistry::secretKeys();
    sort($registrySecretKeys);
    settingsRegistryCheck($formSecretKeys === $registrySecretKeys, 'ChatwootSettingsForm::SECRET_KEYS and SettingsRegistry::secretKeys() must be exactly the same set — drift');

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
