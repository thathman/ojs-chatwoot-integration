# Proactive Product Hardening Audit

Status: ACTIVE CANDIDATE GATE

Purpose: capture correctness, security, reliability, UX and maintainability gaps that should be found proactively rather than waiting for an owner to notice confusing behavior or for Dell acceptance to trip over them. These items supplement the Product Bible/tasklists. A previously checked box does not override a source-proven finding here.

Severity:
- **MUST FIX** — must be resolved or explicitly proven N/A before the prepared candidate.
- **SHOULD FIX** — strong product/reliability improvement; resolve before candidate where practical.
- **VERIFY** — suspicious/ambiguous behavior requiring discriminating real evidence before deciding.

---

## Chatwoot client correctness

### HAR-001 — MUST FIX — explicit Chatwoot account binding; never silently fall back to account 1

Current source: `ChatwootApiService::__construct()` initializes `$accountId = 1`, calls `/api/v1/profile`, and keeps account 1 if resolution fails. Current Chatwoot profile responses expose both `account_id` and an `accounts[]` membership list. A failed profile request must not silently authorize operations against account 1.

Required:
- make account selection explicit/fail-closed;
- when a token belongs to multiple accounts, surface human-readable account selection or prove the current account deterministically;
- validate selected Inbox/Captain resources belong to that account;
- cache/reuse resolved account identity rather than performing hidden account discovery in every API-service constructor;
- multi-account real acceptance.

### HAR-002 — MUST FIX — remote list calls must distinguish empty from failed and must be complete

Current list methods (`getCannedResponses`, Captain tools/scenarios, and PR #196 Assistant Responses) commonly return `[]` on API failure. Callers cannot distinguish “successful zero rows” from 401/403/429/5xx/network failure. Reconciliation/provisioning code must never perform destructive replacement or assume a resource is absent from an unknown/partial result.

Required:
- typed result/error semantics;
- safe error codes, not raw Guzzle messages;
- pagination/completeness support for every list endpoint that can paginate;
- no create/delete/replace decisions from partial or failed lists;
- tests for zero rows vs failure vs second/later page.

### HAR-003 — MUST FIX — scope existing-conversation selection to the configured OJS inbox

Current event delivery calls `getContactConversations()` and then uses `conversations[0]` without proving the conversation belongs to `chatwootInboxId`. A Chatwoot contact may have conversations in multiple inboxes/channels. OJS events/private notes/customer-visible messages must never land in an unrelated WhatsApp/email/other Website inbox merely because it is first in the returned array.

Required:
- deterministic configured-inbox selection;
- fail closed when no matching conversation exists unless the selected delivery mode explicitly permits creating one;
- real multi-inbox/contact acceptance.

### HAR-004 — MUST FIX — external side-effect idempotency under uncertain HTTP outcomes

`createConversation()` currently generates `source_id` using contact ID + `time()`. A request can succeed remotely but time out locally; a queue retry can then create/post again because the caller cannot know the first side effect committed.

Required:
- stable per-occurrence external idempotency identity where Chatwoot supports it;
- retry/reconciliation logic for “remote succeeded, local response lost”;
- acceptance test that simulates an ambiguous post-commit timeout, not only a failure before the remote side effect.

### HAR-005 — SHOULD FIX — typed Chatwoot retry semantics

Current `requestJson()` collapses Guzzle failures to a message string and many callers collapse that further to false/empty. Distinguish at least authentication/authorization, rate limiting, validation/not-found, transient server failure and network timeout. Do not endlessly retry permanent 401/403 errors as generic `delivery_failed`; respect retry-after where available.

---

## Identity, privacy and cross-journal safety

### HAR-006 — MUST FIX — resource-aware reviewer masking must be consistent across widget and bind

The widget path now uses `ReviewerMaskingPolicy`, but `bindSupportSessionRequest()` still computes the expected Chatwoot identifier from `enablePrivacyMode && has Reviewer journal role`. This reintroduces the old role-wide logic in a second security-sensitive path and can make widget identity projection and binding disagree for multi-role users.

Required:
- one shared masking/identity-projection service used by widget and bind;
- live current-resource relationship evidence where resource-specific masking applies;
- blind-review protection must not depend on an administrator disabling/enabling a generic “Privacy Mode” toggle;
- multi-role author-on-A/reviewer-on-B browser + bind acceptance.

### HAR-007 — MUST FIX — ambiguous widget context must fail closed, not pick the first enabled journal

`resolveWidgetContext()` currently falls back to `fallbackWidgetContextFromSettings()`, which iterates journals and returns the first enabled/configured one when a request has no trustworthy journal context. Component/site/admin routes can therefore inherit another journal’s widget/settings.

Required:
- remove ambiguous first-enabled-journal fallback or constrain it to evidence that uniquely identifies the journal;
- component/AJAX/site-level requests with no journal must not invent one;
- multi-journal acceptance for settings modal, component routes, site admin and frontend.

### HAR-008 — FIXED (PR #200/#201) — global defaults must not silently share security credentials across journals

Inherited `saveGlobalProfile()` copies the base export-key set into context 0, including Chatwoot API access token, Identity Validation secret and Support API token (MCP is already excluded). Effective-setting fallback can therefore make multiple journals share credentials simply because “Use Global Defaults” is enabled.

Required:
- classify global-profile-eligible settings explicitly; — done: `SettingsRegistry` (PR #200) declares `globalEligible: false` on `chatwootApiAccessToken`, `chatwootIdentityValidationSecret`, `chatwootSupportApiToken`.
- service/end-user trust-plane credentials must be per-journal by default and must not silently inherit globally; — done: `saveGlobalProfile()`/`applyGlobalProfile()` (PR #201) now skip `SettingsRegistry::nonGlobalEligibleKeys()` in addition to `enableGlobalDefaults`.
- if a shared Chatwoot account credential is intentionally supported, make it an explicit connection profile with clear scope rather than incidental context-0 fallback; — not needed: no shared-credential feature is intentionally supported today, so the fix is simply to stop the accidental sharing.
- prove Journal A credential cannot authorize Journal B by fallback accident. — done: `tests/v2/har-008-global-profile-credential-isolation.php` proves this against the real, unmocked public methods (a secret set on journal A never reaches context 0; a leftover context-0 secret never reaches journal B; ordinary settings still propagate normally). Live-deployed to dell (site served normally afterward, no errors); the actual admin-UI "Save/Apply Global Profile" buttons have not yet been exercised through a real authenticated browser session — only the underlying methods are live-verified as deployed and unit-behaviorally proven.

### HAR-009 — MUST FIX/VERIFY — cross-worker abuse controls

`RateLimiter` is APCu/per-worker/fail-open and `EscalationIdempotencyGuard` is APCu/per-worker/fail-open. That is acceptable only as defense-in-depth if the real primary controls are present.

Required:
- live verify reverse-proxy/load-balancer rate limiting on Dell/production-shaped ingress;
- durable DB-backed escalation idempotency if duplicate handoff notes across workers/retries are unacceptable (they are likely unacceptable for a prepared support product);
- document actual enforcement, not only intended deployment architecture.

### HAR-010 — SHOULD FIX — sanitizer hardening

`KnowledgeSanitizer` is a custom regex sanitizer for HTML that can ultimately feed public Knowledge/Captain content. Either use a vetted OJS/PKP sanitization facility if available without inappropriate dependencies, or maintain a serious adversarial corpus covering malformed HTML, entities/encoded schemes, case variants, broken quoting, nested tags, dangerous URI obfuscation and relevant SVG/XML-like edge cases.

### HAR-011 — MUST FIX — repository-wide safe logging

Raw `$e->getMessage()` still appears in scheduled-task execution logs, legacy event error logs and Chatwoot client error results. External HTTP/SMTP/provider exceptions may contain URLs, request details or other data that should not become operator-visible logs.

Required:
- safe normalized error codes/messages;
- detailed exception data only in an explicitly safe internal diagnostic sink if needed;
- no tokens, emails, OTPs, reviewer identity, manuscript content or raw provider bodies.

---

## Legacy architecture retirement

### HAR-012 — MUST FIX — finish retiring `apiQueue` and its incidental drain points

All eight known Event Bridge event types are now live-owned by v2, but the legacy `apiQueue` remains scheduled through `ProcessLegacyRetryQueueScheduledTask`. Source comments confirm additional drain points still exist in `dispatchEvent()` and `syncEmailTemplates()`.

Also, normal settings still expose `retryQueueEnabled`/`maxRetryAttempts`, while v2 event delivery currently hardcodes its own attempt ceiling. An administrator can reasonably believe those fields tune the current queue when they mostly tune the legacy path.

Required:
- inventory remaining legitimate producers (notably legacy Test Message / canned-response sync);
- migrate or replace them with explicit v2 operations;
- remove opportunistic drains;
- drain/retire old rows safely;
- remove/deprecate legacy queue settings and scheduled task once no producer remains;
- preserve a bounded compatibility drain only as long as required by real upgrade evidence.

### HAR-013 — MUST FIX — “Sync Email Templates” is too broad and misleading

`syncEmailTemplates()` currently iterates the journal’s OJS EmailTemplates and creates Chatwoot canned responses for every non-empty body. There is no support-safe allowlist. Security/verification/password-reset/internal editorial templates must never be promoted to canned responses merely because the button exists.

Required:
- preferably redesign as an explicit **Support Canned Responses** feature with opt-in template selection/classification;
- hard deny security/verification/internal templates;
- do not drain unrelated queues as a side effect;
- if there is no compelling product requirement, remove the feature rather than preserve dangerous legacy behavior.

### HAR-014 — MUST FIX — verification email composition / EmailTemplates

`VerificationEmailContentBuilder` is still fixed-English hand-built HTML. `$journalName` is interpolated into HTML without escaping (the URL is escaped, the journal name is not). The subject also uses journal-controlled text without explicit header/CRLF normalization.

Required:
- proper OJS 3.5 EmailTemplate lifecycle for PIN and secure-link mail;
- locale/fallback behavior;
- context-correct HTML escaping and subject/header normalization;
- strict variable allowlist;
- exclude verification/security templates from any canned-response sync;
- real Mailpit acceptance including special-character/malicious journal-name fixture.

### HAR-015 — MUST FIX — complete retention lifecycle

`PurgeExpiredSupportDataTask` currently purges sessions, verification challenges and audit rows only. Define/purge retention for delivered/dead-letter event rows, Knowledge/Captain sync records where appropriate, FAQ cache, legacy `apiQueue`, and future provider/payment caches. Retain only what has a product/audit purpose.

### HAR-016 — MUST FIX — post-2.0 additive/versioned migration architecture

`version.xml` remains `2.0.0.0`; `getInstallMigration()` returns `InstallSupportGatewayMigration`; the original migration has already been edited additively for a queue column and PR #196 attempted to add a whole FAQ table there. Once `2.0.0.0` is an immutable installed state, new schema must not depend on rewriting the original install definition.

Required:
- a real additive/versioned OJS plugin migration path;
- exact `2.0.0.0 -> current candidate` upgrade test with existing data;
- idempotency and supported DB-driver tests;
- no destructive reinstall requirement.

---

## Product/operational semantics

### HAR-017 — MUST FIX — optional modules must not make the whole product “degraded” merely because unused

`SupportGatewayHealthAggregator` currently degrades overall state when Support API, MCP or verification is not configured, without first establishing whether that module is intentionally enabled/required. `chatwootConfigured` is also primarily a configuration fact, not proof of live health.

Required:
- module state: enabled/required vs optional/off;
- configured vs last verified healthy vs stale/not checked vs failed;
- health actions/timestamps and safe reason codes;
- the Overview redesign must not punish intentional feature selection.

### HAR-018 — MUST FIX — settings semantics must match runtime

Confirmed/suspect examples to close during Settings Console work:
- `skipBackendPages` is saved/exposed but the inspected widget injection path does not check it; prove/wire or remove it.
- `enablePrivacyMode` is not a truthful label for general privacy and must not make blind-review safety optional.
- Base URL + Website Token validation is unconditional despite the product now having non-widget modules.
- `widgetSettingsJson` and per-event JSON are implementation details, not normal UX.
- legacy retry/event controls remain visible after v2 ownership transfer.

Use `SETTINGS_UI_REDESIGN.md` as the detailed implementation brief.

### HAR-019 — SHOULD FIX — Chatwoot-owned vs OJS-owned configuration boundary

Use Chatwoot resource discovery/read-only status and “Open in Chatwoot” navigation instead of duplicating Chatwoot-owned inbox/Captain service configuration. OJS owns integration/embed/privacy/policy; Chatwoot owns agents, greetings, business hours, pre-chat, CSAT, Captain Audience/Schedule and similar service behavior.

### HAR-020 — SHOULD FIX — review the 12-tool Captain footprint

Current plugin provisions 12 Custom Tools. Current Chatwoot guidance caps tools at 15/account and recommends 10 or fewer because larger sets make tool selection harder. Review whether related read-only tools can be safely consolidated without creating overloaded/ambiguous tools. Do not delete tools merely to satisfy a number; validate actual Captain selection quality.

Also verify every customer-specific tool enforces the established verified-identity path; current Chatwoot guidance explicitly requires verification for customer-specific data.

---

## Performance and external API behavior

### HAR-021 — SHOULD FIX — eliminate constructor-time/N+1 profile calls

Every `new ChatwootApiService(...)` currently performs profile/account resolution in its constructor. Queue delivery constructs a client per event row, producing an avoidable profile API call per row before the actual contact/conversation calls.

Required:
- constructors should not hide network I/O;
- resolve account explicitly once and cache/reuse within an operation/context;
- batch queue delivery should reuse an appropriately scoped client where safe;
- performance acceptance with a realistic pending batch.

### HAR-022 — VERIFY/MUST FIX — contact identity resolution should not rely on email alone where duplicates are possible

Event delivery finds contacts by exact email and returns the first matching result. The plugin itself sets a stable OJS identifier when it creates contacts. Audit Chatwoot duplicate-contact behavior and prefer a stable identifier/contact-inbox relationship where available so an OJS event cannot attach to an unrelated duplicate contact sharing an email.

### HAR-023 — VERIFY — widget injection multiplicity

The plugin hooks `TemplateManager::display`, `TemplateManager::fetch` and a footer hook, adds frontend/backend headers, and can also append the script to rendered output. `window.__chatwootLoaded` prevents duplicate SDK boot, but multiple script blocks/listeners may still be registered.

Required:
- discriminating DOM/runtime test proving one effective integration script/ready handler per full page;
- no duplicate `setUser`/custom-attribute calls caused by hook multiplicity;
- component/fetch rendering must not pollute unrelated responses.

---

## Maintainability and development process

### HAR-024 — SHOULD FIX — reduce orchestration god classes and stale source commentary

Current tree size is a warning sign:
- `ChatwootIntegrationV2Plugin.php` ~184 KB;
- `ChatwootIntegrationBasePlugin.php` ~59 KB.

The v2 class spans HTTP, MCP, verification, events, Captain, Knowledge, health, admin, queue and provider coordination. Source comments are already stale in places (for example comments describing queue ownership/scheduling from earlier slices after the runtime changed).

Do not perform a risky rewrite. Extract boundaries as touched by current work: settings/console service, Chatwoot resource client, widget context/identity projection, Event Bridge coordinator, Captain/Knowledge coordinator, health service. Delete/update stale comments in the same PR that changes behavior.

### HAR-025 — MUST FIX (process) — current-work signal must be small and authoritative

`TASKLIST.md` is now over 200 KB and the builder ignored the Settings UI redirect twice, including opening PR #196 seven minutes after #187 was explicitly updated. This is a development-control defect.

`docs/v2/CURRENT_WORK.md` is now the short continuation pointer. Builder sessions must read it first. Close or mark stale/superseded roadmap issues (for example old issue #9) so they cannot compete with current instructions. Update `CURRENT_WORK.md` whenever the immediate execution order changes.

---

## Candidate-gate acceptance additions

Before the final prepared candidate, explicitly exercise:

- multi-Chatwoot-account token behavior and account selection/failure;
- one OJS contact with multiple Chatwoot inbox conversations;
- duplicate/contact-identifier ambiguity;
- API list failure vs successful empty vs multi-page list;
- remote-side-effect succeeded but local HTTP response lost;
- multi-role reviewer widget + bind identity consistency;
- component/site/no-context widget fail-closed behavior in multi-journal OJS;
- global defaults cannot cross-share service credentials accidentally;
- proxy/shared rate limiting and durable handoff idempotency;
- malicious/special-character Knowledge and verification-mail content;
- terminal queue/Knowledge/FAQ retention;
- exact 2.0.0.0 database upgrade into the candidate;
- module-off health state vs module-failed health state;
- one effective widget injection/ready handler;
- Captain tool-selection behavior with the current tool count;
- real Dell rendering through the effective active theme override, not merely plugin source.

## Completion rule

A MUST-FIX item may close only with implementation plus the evidence tier implied by the risk. “Existing tests pass”, “source looks correct”, or “the old task was checked” is not sufficient when the finding concerns a real browser, real OJS hook, real Chatwoot API, real migration or real scheduler behavior.
