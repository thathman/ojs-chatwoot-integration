# v1 Implementation Inventory for v2 Migration

Status: Phase 0 baseline  
Baseline: v1 `1.0.0.2` / `main` commit `2a0459971cb83950d3561580033684282bef56ec`

This inventory records the v1 surfaces that v2 must preserve, migrate, harden or intentionally retire. It is an engineering baseline, not a compatibility claim for OJS versions that have not passed tests.

## Runtime files

| File | Current responsibility | v2 disposition |
|---|---|---|
| `ChatwootIntegrationPlugin.php` | plugin registration, hooks, widget, context, event sync, queue, settings operations, health checks | decompose behind v2 services; keep plugin class as OJS adapter/composition entry point |
| `ChatwootApiService.php` | Chatwoot HTTP API client | preserve behavior behind connector interface; add typed/error-safe v2 client later |
| `ChatwootSettingsForm.php` | journal settings UI and persistence | preserve settings through migration; split secrets/capabilities as v2 settings grow |
| `templates/settingsForm.tpl` | settings UI | preserve existing controls; progressively add v2 health/provider/security sections |
| `locale/en/locale.po` | user-facing strings | preserve; all v2 user-visible strings must remain localized |
| `version.xml` | OJS plugin identity/version | keep product/application identity `chatwootIntegration`; bump only at an intentional release milestone |

## Registered hooks in v1

Current registration in `ChatwootIntegrationPlugin::register()`:

- `TemplateManager::display` → widget injection
- `TemplateManager::fetch` → widget injection
- `Templates::Common::Footer::PageFooter` → widget fallback injection
- `Decision::add` → editor decision event
- `Submission::add` → submission-created event
- `Submission::updateStatus` → submission status event
- `Publication::publish` → publication event

Phase 0 rule: preserve these while they are tested against the selected OJS 3.5 patch target. A later compatibility adapter/event layer may replace a hook only after an ADR and regression test show equivalent behavior.

## v1 settings classification

### Connection / identity

| Setting | Classification | Notes |
|---|---|---|
| `chatwootBaseUrl` | KEEP | connector setting |
| `chatwootWebsiteToken` | KEEP | browser widget token; not treated as a server secret |
| `chatwootIdentityValidationSecret` | KEEP + HARDEN | secret; must never appear in exports/logs/browser except derived HMAC |
| `chatwootApiAccessToken` | KEEP + HARDEN | server secret; must never appear in exports/logs/browser |
| `chatwootInboxId` | KEEP | connector setting |

### Widget / privacy / visibility

| Setting | Classification | Notes |
|---|---|---|
| `enableWidget` | KEEP | master widget on/off |
| `enableDebugMode` | KEEP | see `docs/v2/CORE_BRIDGE_GUIDE.md` §4 — never enable in production, logs identity/HMAC to the browser console |
| `enablePrivacyMode` | KEEP, HARDENED | blind-review widget masking (`docs/v2/CORE_BRIDGE_GUIDE.md` §3); role-wide masking is presentation-only, never the v2 authorization boundary — that moved to the Relationship Resolver + Policy Engine |
| `hideForGuests` | KEEP | visibility toggle |
| `hideForRole_1` (Site Admin) | KEEP | visibility toggle |
| `hideForRole_16` (Manager) | KEEP | visibility toggle |
| `hideForRole_17` (Sub Editor) | KEEP | visibility toggle |
| `hideForRole_4097` (Assistant) | KEEP | visibility toggle |
| `hideForRole_65536` (Author) | KEEP | visibility toggle |
| `hideForRole_4096` (Reviewer) | KEEP | visibility toggle |
| `hideForRole_1048576` (Reader) | KEEP | visibility toggle |
| `lazyLoadWidget` | KEEP | performance |
| `lazyLoadTrigger` | KEEP | performance (`idle`/`interaction`) |
| `excludedPages` | KEEP | matched against the exact real `getRequestedPage()` value — see `docs/v2/CORE_BRIDGE_GUIDE.md` §4 |
| `cspSafeMode` | KEEP | performance/compatibility |
| `skipBackendPages` | KEEP | performance |
| `widgetSettingsJson` | KEEP, EXPORT GAP CLOSED | read by v1's widget rendering; was not listed in v1's own `EXPORT_KEYS` (a real, pre-existing v1 gap, not introduced by v2) — v2's `LEGACY_EXPORT_KEYS` still does not include it either, so this gap is carried forward unchanged, not silently fixed. Flagged here rather than fixed to avoid an unreviewed behavior change to the export/import contract during this inventory pass. |

### Global defaults

| Setting | Classification | Notes |
|---|---|---|
| `enableGlobalDefaults` | KEEP PENDING COMPATIBILITY REVIEW | the existing site/global profile workflow uses context `0` for plugin settings — must be tested against each supported OJS target before v2 treats it as stable; not yet re-verified against a real OJS 3.5 install specifically (the real runtime harness work this session, TST-004/RUN-001, exercised the plugin's own v2 settings, not this v1 global-profile mechanism) |

### Retry/event bridge

| Setting | Classification | Notes |
|---|---|---|
| `retryQueueEnabled` | KEEP | gates the v1 JSON retry-queue path |
| `maxRetryAttempts` | KEEP | |
| `eventSyncMode` | KEEP, SUPERSEDED-BY-DEFAULT | still the real fallback default for `EventDeliverySettingsResolver` (`docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` §2) whenever the new Event Bridge global mode is left at "(use the legacy Sync Mode setting above)" — an existing install's behavior never changes silently on upgrade |
| `eventSubmissionCreated` | KEEP | per-event enable toggle |
| `eventRevisionRequested` | KEEP | per-event enable toggle |
| `eventAccepted` | KEEP | per-event enable toggle |
| `eventRejected` | KEEP | per-event enable toggle |
| `eventPublicationScheduled` | KEEP | per-event enable toggle |
| `eventPublicationPublished` | KEEP | per-event enable toggle |
| `eventDecisionRecorded` | KEEP | per-event enable toggle |
| `apiQueue` | MIGRATE (not yet started) | v1's own JSON-blob retry queue, distinct from v2's real `chatwoot_support_event_queue` table (`docs/v2/PRIVACY_DATA_RETENTION_GUIDE.md` §1); untouched until migration semantics are implemented and tested — the two queues currently coexist independently, v1's queue is not read or written by any v2 code |

### v2-added settings (for completeness — not v1 settings, listed so this remains the one real, current settings inventory)

| Setting | Classification | Notes |
|---|---|---|
| `chatwootSupportApiToken` | NEW (v2) | see `docs/v2/INSTALL_CONFIG_GUIDE.md` §4 |
| `mcpServiceToken` | NEW (v2) | see `docs/v2/INSTALL_CONFIG_GUIDE.md` §4 |
| `eventDeliveryGlobalMode` | NEW (v2) | see `docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` §2 |
| `eventDeliveryCustomerMessageConsent` | NEW (v2) | see `docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` §2 |
| `eventDeliveryPerEventOverridesJson` | NEW (v2) | see `docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` §2 |
| `launcherBottomOffset` | REAL, VERIFIED DEAD SETTING | saved/loaded by the settings form and rendered as a real input field, but never read anywhere in `addChatwootWidget()`'s actual widget-script assembly — confirmed by source: no reference to it outside `ChatwootSettingsForm.php`/`templates/settingsForm.tpl`. Setting it currently has zero effect. This is a real, pre-existing gap (present before this inventory pass), not introduced here; `docs/v2/CORE_BRIDGE_GUIDE.md` §5 is corrected in the same PR as this finding to stop claiming it has a real effect. REMOVE or WIRE UP is a genuine open decision for a future PR — not resolved here. |

## Current context sent to Chatwoot

v1 can currently send or derive:

- journal ID/name;
- requested page/operation;
- logged-in user ID/name/email;
- HMAC identity hash;
- ORCID/affiliation;
- journal role IDs;
- active submission count;
- article title/DOI/id/section;
- submission workflow/status/title/URL for event notes;
- priority flags such as overdue review/payment-page context.

v2 disposition: useful UX context is preserved, but no Chatwoot custom attribute becomes an authorization source. The server reloads and authorizes protected resources.

## Current event delivery behavior

v1 synchronously attempts Chatwoot delivery, then optionally stores failed work in a JSON retry queue. It may find/create contacts, add a private note to an existing conversation, or open a conversation depending on `eventSyncMode`.

v2 migration requirements:

1. keep ordinary OJS page/editorial workflow operational during Chatwoot outages;
2. introduce stable event IDs/idempotency;
3. use a durable outbox/queue rather than unbounded synchronous remote behavior;
4. preserve configured event choices;
5. filter sensitive context before destination delivery.

## Known v1 security/architecture debt frozen for Phase 0

1. `EXPORT_KEYS` currently includes `chatwootApiAccessToken` and `chatwootIdentityValidationSecret`; v2 export policy must redact them before the export path is considered safe.
2. reviewer privacy is role-wide rather than resource-specific.
3. the plugin class is a monolith containing OJS adapter, domain/context, Chatwoot client orchestration, queue and settings logic.
4. Chatwoot custom attributes carry rich state but must not be trusted as authorization.
5. retry jobs are stored as plugin-setting JSON and processed opportunistically during requests.
6. event notes append raw JSON context to message text; v2 should use purpose-built safe handoff/event DTOs.
7. the current implementation assumes a single Chatwoot account ID fallback of `1` until profile resolution succeeds; v2 connector health must make account resolution explicit.
8. current context helpers mix compatibility fallbacks directly into the plugin class; these are moving behind version adapters.

## Phase 0 compatibility posture

- OJS 3.5 receives the first compatibility adapter.
- OJS 3.6 intentionally has no adapter yet and must fail closed in foundation tests.
- No source-level existence of a hook/API is enough to declare a release compatible; exact target patches require integration tests.

## Migration rule

Refactoring v1 behavior happens incrementally. A behavior may move behind a v2 service only when:

- the original behavior is captured by a regression test or documented fixture;
- failure behavior is preserved or intentionally hardened;
- settings remain readable;
- multi-journal context remains isolated;
- the change does not expose new data to Chatwoot/Captain.
