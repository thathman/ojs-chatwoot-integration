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
            /**
             * Chatwoot tab console (owner directive 2026-09-04): a token
             * can belong to more than one Chatwoot account — HAR-001
             * requires never silently guessing which one. Persisted only
             * once explicitly resolved (auto-selected when exactly one
             * account exists, or chosen by the admin when more than one
             * does) via discoverChatwootResources(); every later resource
             * (Inbox, Captain Assistant) is validated against this
             * explicit account, never re-guessed per call. Global-eligible
             * (unlike the trust-plane secrets above): the account a
             * shared/global chatwootApiAccessToken resolves to is a fact
             * about that token, not journal-specific, so copying it
             * alongside a shared token is consistent, never a cross-
             * journal leak — see tests/v2/har-008-global-profile-credential-isolation.php's
             * "nonGlobalEligible === secret" invariant this must not violate.
             */
            new SettingDefinition('chatwootAccountId', 'int', tab: 'chatwoot'),
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
            /**
             * Widget tab console (owner directive 2026-09-04): structured
             * Appearance controls replacing raw widgetSettingsJson editing
             * for the common case. Every value here is a real, verified
             * `window.chatwootSettings` key/value the deployed Chatwoot
             * SDK (support.airixmedia.com/packs/js/sdk.js) actually reads
             * — confirmed by inspecting that real bundle, never invented:
             * position ("left"/"right"), launcher type ("standard"/
             * "expanded_bubble"), theme/darkMode ("light"/"dark"/"auto"),
             * useBrowserLanguage, showPopoutButton, showUnreadMessagesDialog,
             * hideMessageBubble.
             */
            new SettingDefinition('widgetPosition', 'string', tab: 'widget'),
            new SettingDefinition('widgetLauncherStyle', 'string', tab: 'widget'),
            new SettingDefinition('widgetLauncherTitle', 'string', tab: 'widget'),
            new SettingDefinition('widgetLanguageMode', 'string', tab: 'widget'),
            new SettingDefinition('widgetFixedLocale', 'string', tab: 'widget'),
            new SettingDefinition('widgetTheme', 'string', tab: 'widget'),
            new SettingDefinition('widgetShowPopoutButton', 'bool', tab: 'widget'),
            new SettingDefinition('widgetShowUnreadDialog', 'bool', tab: 'widget'),
            new SettingDefinition('widgetHideMessageBubble', 'bool', tab: 'widget'),
            /**
             * Owner directive item 13: raw JSON editing is no longer part
             * of ordinary setup — moved to Advanced as a documented,
             * validated override layer for genuine compatibility edge
             * cases only. The structured controls above remain
             * authoritative; this merges on top of them (see
             * addChatwootWidget()'s own layering comment) so an override
             * here can only add/replace a key the structured controls
             * do not already cover, never silently contradict a decision
             * an admin made through them without visible effect.
             */
            new SettingDefinition('widgetSettingsJson', 'string', tab: 'advanced'),
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
