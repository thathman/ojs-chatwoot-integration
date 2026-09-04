# Proactive Product Hardening Audit

Status: ACTIVE CANDIDATE GATE

Purpose: capture correctness, security, reliability, UX and maintainability gaps that should be found proactively rather than waiting for an owner to notice confusing behavior or for Dell acceptance to trip over them. These items supplement the Product Bible/tasklists. A previously checked box does not override a source-proven finding here.

Severity:
- **MUST FIX** — must be resolved or explicitly proven N/A before the prepared candidate.
- **SHOULD FIX** — strong product/reliability improvement; resolve before candidate where practical.
- **VERIFY** — suspicious/ambiguous behavior requiring discriminating real evidence before deciding.

---

## Chatwoot client correctness

### HAR-001 — PARTIALLY FIXED (PR #217) — explicit Chatwoot account binding; never silently fall back to account 1

Original source: `ChatwootApiService::__construct()` initialized `$accountId = 1`, called `/api/v1/profile`, and silently kept account 1 if resolution failed for any reason (network error, bad token, malformed response) — indistinguishable from a real, confirmed account 1. Same "silently continue with stale/wrong state on failure" class of bug as KNO-011's outage-safety gap and HAR-007's `Repo::context()` bug found earlier this session.

**Closed by PR #217**: the fail-closed half. `ChatwootApiService` now tracks `$accountResolved` (true only once a real `/profile` response confirms the account, or a caller explicitly calls `setAccountId()`), and `requestJson()` refuses every `accounts/{id}/...` call while unresolved, returning the same `ok:false` contract every other failure path uses instead of silently guessing. Live-verified against the real Chatwoot API on dell:
- a deliberately invalid token: `isAccountResolved()` → `false`, `getCannedResponses()` → `[]` (refused, not a guessed-account result) — confirms the fail-closed path fires against Chatwoot's real API, not just structurally.
- the real production token/account: `isAccountResolved()` → `true`, `getAccountId()` → `2` (the real account, unchanged) — confirms no regression against the account the plugin has run against all session.

**Also closed by PR #229**: "cache/reuse resolved account identity rather than performing hidden account discovery in every API-service constructor" — added a static per-`(baseUrl, token)` cache consulted before `getProfile()` is ever called; a resolution failure is never cached (self-heals once Chatwoot recovers). Live-verified on dell against the real production account: first construction 768ms (real `/profile` round trip), second construction with identical credentials 0ms, same real account ID (2) both times.

**Still open** (not addressed by PRs #217/#229, tracked here for the next slice):
- when a token belongs to multiple accounts, surface human-readable account selection or prove the current account deterministically — no multi-account UX exists yet, only single-account fail-closed/fail-open;
- validate selected Inbox/Captain resources belong to the resolved account;
- multi-account real acceptance test.

### HAR-002 — MUST FIX — remote list calls must distinguish empty from failed and must be complete

Current list methods (`getCannedResponses`, Captain tools/scenarios, and PR #196 Assistant Responses) commonly return `[]` on API failure. Callers cannot distinguish “successful zero rows” from 401/403/429/5xx/network failure. Reconciliation/provisioning code must never perform destructive replacement or assume a resource is absent from an unknown/partial result.

Required:
- typed result/error semantics;
- safe error codes, not raw Guzzle messages;
- pagination/completeness support for every list endpoint that can paginate;
- no create/delete/replace decisions from partial or failed lists;
- tests for zero rows vs failure vs second/later page.

### HAR-003 — FIXED (PR #219) — scope existing-conversation selection to the configured OJS inbox

Original source: event delivery (`sendChatwootEvent()` and the v2 event-delivery path) called `getContactConversations()` and then used `conversations[0]` without proving the conversation belonged to `chatwootInboxId`. A Chatwoot contact may have conversations in multiple inboxes/channels (WhatsApp, email, another website) — an OJS event/private note/customer-visible message could silently land in an unrelated one.

Fixed via a shared `ChatwootIntegrationBasePlugin::selectConversationForInbox(array $conversations, int $inboxId): ?array` used by both call sites: requires `inbox_id === $inboxId` before a conversation is used; returns `null` (fail closed, no fallback to `conversations[0]`) when the inbox is unconfigured or no conversation matches, letting callers fall through to their existing create-new-conversation branch where the delivery mode permits it. `tests/v2/har-003-conversation-inbox-scoping.php` proves the selector's behavior against multi-inbox conversation arrays and that both real call sites now use it — no remaining `conversations[0]` reference in either file.

Not yet done: the audit's "real multi-inbox/contact acceptance" against Chatwoot's real API remains unexercised beyond this unit-level proof (a real contact with conversations in two real distinct inboxes) — deferred as lower priority since the fail-closed behavior itself already removes the cross-inbox leak regardless of live acceptance.

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

### HAR-006 — FIXED (PR #215) — resource-aware reviewer masking must be consistent across widget and bind

The widget path used `ReviewerMaskingPolicy`, but `bindSupportSessionRequest()` still computed the expected Chatwoot identifier from `enablePrivacyMode && has Reviewer journal role`. This reintroduced the old role-wide logic in a second security-sensitive path and could make widget identity projection and binding disagree for multi-role users.

Fixed: both call sites now go through one new shared method, `ChatwootIntegrationBasePlugin::resolveReviewerMasking()` — the widget's own previous inline `ReviewerMaskingPolicy` construction was removed and replaced with a call to it, and bind's `enablePrivacyMode && in_array(Role::ROLE_ID_REVIEWER, ...)` check was replaced with the same call, reusing its own already-resolved `SupportContext` so it can never disagree with what the widget rendered for the same page. `tests/v2/har-006-shared-reviewer-masking.php` proves both real call sites use the shared method and the old role-wide check in bind is gone. Live-verified on dell: the branch was checked out directly, real frontend widget-path requests returned 200 with no new errors, and the shared method was confirmed present in the deployed source.

Still open: the required "blind-review protection must not depend on a generic Privacy Mode toggle" item (a separate, larger product decision — see HAR-018's `enablePrivacyMode` note) and a real multi-role author-on-A/reviewer-on-B browser + bind acceptance walkthrough — the pure decision logic is proven (`tests/v2/pol-011-resource-aware-reviewer-masking.php`) and the two call sites are now provably consistent with each other, but an authenticated real-browser run through both paths for the exact same multi-role fixture has not been performed this session.

### HAR-007 — FIXED (PR #213) — ambiguous widget context must fail closed, not pick the first enabled journal

`resolveWidgetContext()` fell back to `fallbackWidgetContextFromSettings()`, which iterated journals and returned the first enabled/configured one when a request had no trustworthy journal context. Component/site/admin routes could therefore inherit another journal's widget/settings.

Investigating this also found a real, currently-shipping, high-volume bug: `fallbackWidgetContextFromSettings()` and two other call sites in the same method called `Repo::context()`, which does not exist as a real method on either `APP\facades\Repo` or `PKP\facades\Repo` (confirmed against a real local OJS checkout — no `context()` method on either class). Every call threw a real `\Error`, silently logged and swallowed by `PKP\plugins\Hook::run()`'s own plugin-exception handling — **confirmed live on dell: 3,081 real occurrences of this exact error in the prior 24 hours of production traffic**, dropping to 0 in the minute immediately after the fix deployed. The widget was silently failing to render on this path on essentially every relevant request.

Fixed: both real path-based lookups now use the real `Application::getContextDAO()->getByPath()`. The ambiguous fallback method is removed entirely — `resolveWidgetContext()` now fails closed (returns `null`) on an ambiguous request. `tests/v2/har-007-widget-context-fail-closed.php` covers the structural fix; the real Smarty compile-check technique from AUD-011/PR #195 confirmed the fatal error is gone. Not yet separately done: real multi-journal browser acceptance for the settings modal/component routes/site admin (the negative case — widget correctly absent on an ambiguous route — remains structural/log-based evidence only, not a live browser walkthrough).

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

### HAR-012 — PARTIALLY FIXED (PR #223) — finish retiring `apiQueue` and its incidental drain points

Original source: all eight known Event Bridge event types are now live-owned by v2, but the legacy `apiQueue` remained scheduled through `ProcessLegacyRetryQueueScheduledTask`, and additional opportunistic drain points existed in `dispatchEvent()` and `syncEmailTemplates()`.

**Closed by PR #223** (one of five required items — "remove opportunistic drains"): confirmed via `isLiveDeliveryOwnedByV2()` that all 8 real event-setting keys now return `true`, meaning every `dispatchEvent()` call site inside the four v1 event handlers (`handleSubmissionCreated`, `handleEditorDecision`, `handleSubmissionStatusUpdated`, `handlePublicationPublished`) is unreachable dead code — `dispatchEvent()`'s only live caller left is the deliberate, rare "Send Test Message" admin action. Removed its unconditional `processApiQueue($contextId, 4)` opportunistic drain accordingly; `ProcessLegacyRetryQueueScheduledTask` is now the sole reliable drain path. `tests/v2/har-012-no-opportunistic-drain-in-dispatch.php` proves the call is gone and that a queued job is untouched by a second, unrelated `dispatchEvent()` call.

**Also closed by PR #227**: `syncEmailTemplates()`'s own `processApiQueue($contextId, 8)` call removed too — the shared legacy queue mixes job types (`canned_response_sync` from this action, `conversation_event` from Send Test Message), so clicking "Sync Email Templates" could as a side effect redeliver an unrelated queued Test Message. This closes HAR-013's overlapping "do not drain unrelated queues as a side effect" requirement as well. `ProcessLegacyRetryQueueScheduledTask` is now the sole drain path anywhere in the plugin.

**Also closed by PR #231**: the settings-semantics ambiguity — `retryQueueEnabled`/`maxRetryAttempts`'s labels now explicitly say they only tune the legacy Send Test Message/Sync Email Templates path, not real-time v2 event delivery. `tests/v2/har-012-legacy-queue-settings-labels.php` also confirms the underlying code-level fact (v2 event delivery never reads either setting).

**Still open** (the last two required items):
- inventory/migrate the two remaining legitimate producers (Send Test Message, `syncEmailTemplates()`'s canned-response sync) to explicit v2 operations — both still use the legacy `apiQueue`/`enqueueApiJob()` path, just without opportunistically draining it anymore;
- drain/retire old rows safely and remove/deprecate the legacy queue settings and scheduled task once no producer remains — requires the producer migration above first.

### HAR-013 — PARTIALLY FIXED (PR #225) — “Sync Email Templates” is too broad and misleading

Original source: `syncEmailTemplates()` iterated the journal's OJS EmailTemplates and created Chatwoot canned responses for every non-empty body with no support-safe allowlist. Live-checked against this real installation's actual templates (via `Repo::emailTemplate()`): `PASSWORD_RESET_CONFIRM`, `MAGIC_LOGIN_LINK`, `USER_VALIDATE_CONTEXT`, and `USER_VALIDATE_SITE` all had non-empty bodies and would have been synced as plaintext canned responses visible to every support agent with Chatwoot access.

**Closed by PR #225** ("hard deny security/verification/internal templates"): added `isCannedResponseSafe()`, a keyword-based hard deny (`PASSWORD`, `RESET`, `VALIDATE`, `VERIF`, `LOGIN`, `REGISTER`, `ACTIVATE`, `TOKEN`, `SECRET`, `OTP`, `PIN`, `MAGIC`, `CREDENTIAL`) checked before `createCannedResponse()` is ever called. Keyword-matched, not an exact-key denylist, so a similarly-named template added later by this or any other installed plugin is denied by default. `tests/v2/har-013-canned-response-deny-sensitive-templates.php` proves the real deny/allow decision; live-verified on dell against this installation's actual 112 non-empty templates: `MAGIC_LOGIN_LINK`, `PASSWORD_RESET_CONFIRM`, `REVIEWER_REGISTER`, `USER_REGISTER`, `USER_VALIDATE_CONTEXT`, `USER_VALIDATE_SITE` are now denied (6 of 112), the other 106 ordinary editorial templates remain allowed.

**Also closed** (PR #227, tracked jointly with HAR-012): "do not drain unrelated queues as a side effect" — `syncEmailTemplates()`'s own `processApiQueue($contextId, 8)` call removed, since the shared legacy queue mixes job types and this action could have redelivered an unrelated queued Send Test Message as a side effect.

**Still open**: the larger product-redesign question — an explicit opt-in **Support Canned Responses** feature with template selection/classification, vs. removing the button entirely if there is no compelling product requirement.

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

### HAR-017 — PARTIALLY FIXED (PR #221) — optional modules must not make the whole product “degraded” merely because unused

Original source: `SupportGatewayHealthAggregator` degraded overall state whenever Support API, MCP, or verification was unconfigured — but none of the three has a separate "intended to be used" setting distinct from the credential itself, so an install that simply never opted into an optional module was permanently stuck at "degraded." The exact false-positive Captain's own `not_provisioned`-is-neutral precedent, in this same aggregator, already guards against.

Fixed by removing the three `*Configured` flags from the degraded-state computation; they remain exposed on `SupportGatewayHealthSummary`/`toArray()` so the Overview UI can still show each module's own configuration state. `tests/v2/support-gateway-health.php` proves the three previously-"must degrade" cases now resolve healthy. This is unit-level evidence of the aggregator's pure decision logic (the entire bug and fix), not a live-browser Overview check — live acceptance of the Settings Console Overview tab remains blocked by the pre-existing AJDSI theme override (see AUD-011/PR #195), same limitation already recorded for ADM-008/ADM-009.

Not yet done: `chatwootConfigured`'s own "configuration fact vs proof of live health" gap, and the audit's fuller module-state model (configured vs last-verified-healthy vs stale vs failed, with health actions/timestamps/reason codes) — deferred as separate, larger follow-up work.

### HAR-018 — MUST FIX (one item closed) — settings semantics must match runtime

Confirmed/suspect examples to close during Settings Console work:
- ~~`skipBackendPages` is saved/exposed but the inspected widget injection path does not check it; prove/wire or remove it.~~ **Fixed (PR #211).** Confirmed it was a genuine placebo — saved by the form, never read anywhere, and its companion `isBackendPage()` helper existed but was never called either. `addChatwootWidget()` now consults it via `getEffectiveSetting()` and calls `isBackendPage($requestedPage)` (the already-safely-resolved page string, not `$request` directly — avoids reintroducing TST-020's `PKPComponentRouter` crash), gated behind the same `$isPageRequest` guard `excludedPages` already uses. `tests/v2/har-018-skip-backend-pages.php` covers the real classification behavior and the wiring. Live-deployed to dell; the public frontend widget still injects normally (confirmed via a real page fetch), and backend pages are outside this session's live-browser reach (blocked by the same AJDSI theme constraint noted elsewhere) so the negative case (widget suppressed on a real `workflow`/`management` page) is structural/source-verified only, not yet browser-confirmed.
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
