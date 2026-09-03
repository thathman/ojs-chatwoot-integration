<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Settings;

/**
 * UX-024 first slice: the canonical settings definition `SETTINGS_UI_REDESIGN.md`
 * asks for, describing every plugin setting key once (type, secret,
 * export/import eligibility, global-profile eligibility, console tab).
 *
 * Deliberately NOT yet a consumer replacement: `ChatwootIntegrationBasePlugin::EXPORT_KEYS`,
 * `ChatwootIntegrationV2Plugin::LEGACY_EXPORT_KEYS`, and `ChatwootSettingsForm`'s
 * three own key lists are untouched by this slice — migrating each one
 * is separate, reviewable follow-up work. This registry's first real
 * job is `tests/v2/settings-registry.php`: an automated drift guard
 * that fails the moment any of those lists disagrees with this one,
 * replacing the "must stay in sync" comment discipline with something
 * that actually breaks CI.
 *
 * `globalEligible: false` on the three trust-plane credentials records
 * the real HAR-008 finding (global-profile fallback must not silently
 * share Chatwoot API/Identity/Support-API secrets across journals) as
 * a declared fact — it does not yet change `saveGlobalProfile()`/
 * `applyGlobalProfile()` runtime behavior, which remains a separate,
 * not-yet-closed HAR-008 fix.
 */
final class SettingsRegistry
{
    /** @return array<string,SettingDefinition> Keyed by setting key. */
    public static function all(): array
    {
        $definitions = [
            new SettingDefinition('chatwootBaseUrl', 'string', tab: 'chatwoot'),
            new SettingDefinition('chatwootWebsiteToken', 'string', tab: 'chatwoot'),
            new SettingDefinition('chatwootIdentityValidationSecret', 'string', secret: true, globalEligible: false, tab: 'chatwoot'),
            new SettingDefinition('chatwootApiAccessToken', 'string', secret: true, globalEligible: false, tab: 'chatwoot'),
            new SettingDefinition('chatwootInboxId', 'int', tab: 'chatwoot'),
            new SettingDefinition('chatwootCaptainAssistantId', 'int', tab: 'ai_knowledge'),
            new SettingDefinition('chatwootSupportApiToken', 'string', secret: true, globalEligible: false, tab: 'api_mcp'),
            new SettingDefinition('mcpServiceToken', 'string', secret: true, exportable: false, globalEligible: false, tab: 'api_mcp'),
            new SettingDefinition('enableWidget', 'bool', tab: 'widget'),
            new SettingDefinition('enableDebugMode', 'bool', tab: 'advanced'),
            new SettingDefinition('enablePrivacyMode', 'bool', tab: 'widget'),
            new SettingDefinition('hideForGuests', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_1', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_16', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_17', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_4097', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_65536', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_4096', 'bool', tab: 'widget'),
            new SettingDefinition('hideForRole_1048576', 'bool', tab: 'widget'),
            new SettingDefinition('enableGlobalDefaults', 'bool', tab: 'advanced'),
            new SettingDefinition('retryQueueEnabled', 'bool', tab: 'automation'),
            new SettingDefinition('maxRetryAttempts', 'int', tab: 'automation'),
            new SettingDefinition('eventSyncMode', 'string', tab: 'automation'),
            new SettingDefinition('eventSubmissionCreated', 'bool', tab: 'automation'),
            new SettingDefinition('eventRevisionRequested', 'bool', tab: 'automation'),
            new SettingDefinition('eventAccepted', 'bool', tab: 'automation'),
            new SettingDefinition('eventRejected', 'bool', tab: 'automation'),
            new SettingDefinition('eventPublicationScheduled', 'bool', tab: 'automation'),
            new SettingDefinition('eventPublicationPublished', 'bool', tab: 'automation'),
            new SettingDefinition('eventDecisionRecorded', 'bool', tab: 'automation'),
            new SettingDefinition('lazyLoadWidget', 'bool', tab: 'advanced'),
            new SettingDefinition('lazyLoadTrigger', 'string', tab: 'advanced'),
            new SettingDefinition('excludedPages', 'string', tab: 'advanced'),
            new SettingDefinition('cspSafeMode', 'bool', tab: 'advanced'),
            new SettingDefinition('skipBackendPages', 'bool', tab: 'advanced'),
            new SettingDefinition('widgetSettingsJson', 'string', tab: 'widget'),
            new SettingDefinition('eventDeliveryGlobalMode', 'string', tab: 'automation'),
            new SettingDefinition('eventDeliveryCustomerMessageConsent', 'bool', tab: 'automation'),
            new SettingDefinition('eventDeliveryPerEventOverridesJson', 'string', tab: 'automation'),
        ];

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }
        return $byKey;
    }

    public static function get(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return string[] Keys eligible for export/import/global-profile round-tripping. */
    public static function exportableKeys(): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d) => $d->key,
            array_filter(self::all(), static fn (SettingDefinition $d) => $d->exportable)
        ));
    }

    /** @return string[] */
    public static function secretKeys(): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d) => $d->key,
            array_filter(self::all(), static fn (SettingDefinition $d) => $d->secret)
        ));
    }

    /** @return string[] Exportable keys NOT eligible to inherit via "Use Global Defaults" (HAR-008). */
    public static function nonGlobalEligibleKeys(): array
    {
        return array_values(array_map(
            static fn (SettingDefinition $d) => $d->key,
            array_filter(self::all(), static fn (SettingDefinition $d) => $d->exportable && !$d->globalEligible)
        ));
    }

    public static function type(string $key): string
    {
        return self::get($key)?->type ?? 'string';
    }
}
