<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Settings;

/**
 * UX-024/HAR-025: one immutable fact about one plugin setting key. The
 * canonical settings registry this participates in exists to stop the
 * key-list drift `SETTINGS-SMALL-002` already found once (`EXPORT_KEYS`
 * in `ChatwootIntegrationBasePlugin` vs `LEGACY_EXPORT_KEYS` in
 * `ChatwootIntegrationV2Plugin` vs `ChatwootSettingsForm`'s three own
 * lists) — a fifth key list is not the fix; a single declared fact per
 * key, checked against every existing list, is.
 */
final class SettingDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly bool $secret = false,
        public readonly bool $exportable = true,
        public readonly bool $globalEligible = true,
        public readonly string $tab = 'advanced'
    ) {
    }
}
