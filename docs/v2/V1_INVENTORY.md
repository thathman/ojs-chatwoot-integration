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

`enableWidget`, `enableDebugMode`, `enablePrivacyMode`, `hideForGuests`, all current `hideForRole_*`, `lazyLoadWidget`, `lazyLoadTrigger`, `excludedPages`, `cspSafeMode`, `skipBackendPages` are **KEEP/MIGRATE**.

Important v2 behavior change: `enablePrivacyMode` may continue as a UI/config compatibility setting, but resource authorization and reviewer anonymity move to Relationship Resolver + Policy Engine. Role-wide reviewer masking is not the v2 security boundary.

### Global defaults

`enableGlobalDefaults` and the existing site/global profile workflow are **KEEP PENDING COMPATIBILITY REVIEW**. The use of context `0` for plugin settings must be tested against each supported OJS target before v2 treats it as stable.

### Retry/event bridge

`retryQueueEnabled`, `maxRetryAttempts`, `eventSyncMode`, `eventSubmissionCreated`, `eventRevisionRequested`, `eventAccepted`, `eventRejected`, `eventPublicationScheduled`, `eventPublicationPublished`, `eventDecisionRecorded` are **KEEP/MIGRATE**.

The current `apiQueue` plugin-setting JSON store is **MIGRATE** to a durable v2 queue/outbox model; it remains untouched until migration semantics are implemented and tested.

### Additional widget configuration

`widgetSettingsJson` is read by v1 and should be **KEEP/MIGRATE**, but it is not currently listed in `EXPORT_KEYS`; v2 export/import behavior should be made explicit rather than inferred.

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
