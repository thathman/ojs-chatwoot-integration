# v2 Master Tasklist

This is the implementation backlog. Checkboxes are not release claims; they become complete only with acceptance tests.

## FND — Foundation

- [x] **FND-001** Add root GPL-compatible `LICENSE`. — root `LICENSE` (GPL-3.0-or-later, SPDX-tagged).
- [x] **FND-002** Add root `SECURITY.md` and private vulnerability process.
- [x] **FND-003** Add `CONTRIBUTING.md` and v2 branch/review conventions.
- [ ] **FND-004** Inventory all v1 settings and classify keep/migrate/deprecate/remove. — partial: `docs/v2/V1_INVENTORY.md` §"v1 settings classification" covers connection/identity, widget/privacy/visibility, global defaults, and retry/event-bridge settings groups with a keep/migrate/deprecate disposition each; not a literal per-setting-key table.
- [ ] **FND-005** Inventory all v1 hooks against supported OJS versions. — partial: `docs/v2/V1_INVENTORY.md` §"Registered hooks in v1" lists the hooks themselves; does not cross-check each against a supported-OJS-version compatibility matrix.
- [ ] **FND-006** Baseline v1 widget/API/event regression tests. — partial: `tests/v2/live-plugin.php` covers v1 widget-rendering fallback (v2 bootstrap failure must never block `parent::addChatwootWidget()`) and settings export/redaction; no baseline coverage exists yet for v1's original event-sync/retry-queue behavior itself.
- [x] **FND-007** Define service/container structure for v2 modules. — `classes/v2/` is organized by module (`Api`, `Audit`, `Captain`, `Chatwoot`, `Compatibility`, `Context`, `Contracts`, `Diagnostics`, `Handoff`, `Http`, `Knowledge`, `Migration`, `Plugin`, `Policy`, `Provider`, `Relationship`, `Runtime`, `Session`, `Settings`, `State`, `Task`, `Verification`), composed through `SupportGatewayKernel`/`RuntimeContextBridge` rather than a DI container — no framework container exists to hook into in a PKP plugin, so a hand-wired composition root is the real service-structure equivalent here.
- [x] **FND-008** Create OJS compatibility adapter interface(s). — `OjsCompatibilityAdapterInterface` + `Ojs35CompatibilityAdapter`.
- [x] **FND-009** Build install/upgrade/uninstall migration framework. — `InstallSupportGatewayMigration` (sessions/challenges/knowledge-sync/audit-log tables), edited in place pre-release rather than versioned, per docs/v2 pre-release migration policy.
- [x] **FND-010** Remove secrets from normal settings export/default diagnostics. — `exportSettings()` strips `chatwootApiAccessToken`/`chatwootIdentityValidationSecret`/`chatwootSupportApiToken` and reports which keys were redacted rather than exporting them.
- [x] **FND-011** Add coding/static-analysis rules compatible with PKP target. — `.php-cs-fixer.php`, the exact ruleset from a real local `pkp-lib` checkout's own `.php_cs_rules` (minus its repo-internal `PKP/hookfixer` custom fixer, which has no equivalent here). Gated in CI (`.forgejo/workflows/ci.yml`) as `--dry-run` against `classes/v2`/`tests/v2` only — legacy v1 files predate this ruleset and have not been reformatted (a separate, out-of-scope change); a repo-wide gate today would fail on unrelated legacy code. The `.github/workflows/ci.yml` mirror is publication-only per house rules (never a merge gate) and was already stale before this change (it lists a narrower, outdated subset of v2 test files); not touched here.
- [x] **FND-012** Add CI skeleton for package/tests. — `.forgejo/workflows/ci.yml` (real gate for every PR merged into `v2-dev` this whole build) and a mirrored `.github/workflows/ci.yml`.

## CTX — OJS Context

- [x] **CTX-001** Implement journal/context resolver. — `ContextResolver::resolve()` → `SupportContext::contextId()`.
- [x] **CTX-002** Implement authenticated user resolver. — `SupportContext::userId()`/`isAuthenticated()`.
- [x] **CTX-003** Implement current journal role resolver. — `SupportContext::roleIds()`.
- [x] **CTX-004** Implement page/operation context DTO. — `SupportContext::page()`/`operation()`.
- [x] **CTX-005** Implement article context adapter. — `ResourceContextResolver` resolves an `article` template key to a `submission`-typed `ResourceContext` (an OJS published article is the same underlying submission, not a distinct resource kind).
- [x] **CTX-006** Implement submission/workflow context adapter. — `ResourceContextResolver::resolve()` (template/request-parameter/known-route detection, all re-authorized downstream — detection is never authorization).
- [ ] **CTX-007** Implement review context adapter. — not done: `ResourceContextResolver` only ever produces a `submission`-typed `ResourceContext`; there is no distinct review-resource detection. Review *relationship* resolution exists (REL-003, `Repo::reviewAssignment()`), but that answers "is this user a reviewer of this submission," not "what review is currently in view."
- [x] **CTX-008** Implement payment/support intent context. — `SupportIntentResolver::resolve()`'s `payment_help` branch, wired live via `ChatwootContextProjector`.
- [ ] **CTX-009** Add multi-journal isolation tests. — partial: cross-journal isolation is extensively tested for downstream features built on `SupportContext` (Captain provisioning, knowledge, health reports — see their own "multi-journal isolation" test sections), but there is no test targeting `ContextResolver`/`SupportContext` itself.
- [ ] **CTX-010** Add locale normalization/fallback tests. — not done: no dedicated test exercises locale normalization/fallback in `ContextResolver`, `ResourceContextResolver`, or `SupportIntentResolver`.

## CWO — Chatwoot Connector

- [x] **CWO-001** Refactor Chatwoot API client behind interface. — `ChatwootApiService implements ChatwootConversationClientInterface, ChatwootCaptainClientInterface`.
- [x] **CWO-002** Preserve/verify HMAC `setUser` integration. — the v1 `setUser`/`identifier_hash` HMAC (`ChatwootIntegrationPlugin::addChatwootWidget()`) is untouched by v2 and verified to always still run: `tests/v2/live-plugin.php` asserts `parent::addChatwootWidget()` executes unconditionally even if v2's own support-session bootstrap throws.
- [ ] **CWO-003** Define safe custom-attribute schema. — not done: the v1 widget custom-attribute payload (`journal_id`, `roles`, `active_submissions`, `orcid`, `affiliation`, `article_*`, etc. in `ChatwootIntegrationPlugin::addChatwootWidget()`) remains ad hoc/legacy; no v2 schema formalizes which fields are safe to send.
- [ ] **CWO-004** Remove any authorization dependency on custom attributes. — not done as a verified guarantee: v2's Identity→Relationship→Capability→Serializer chain never reads these client-side attributes back for authorization (architecturally they can't — nothing in `classes/v2/` consumes them), but this has not been asserted by a dedicated test the way the tool-slug/metadata-header equivalent was analyzed for Custom Tools.
- [x] **CWO-005** Implement contextual launcher intents. — `SupportIntentResolver` + `ChatwootContextProjector::project()`, wired live via `ChatwootIntegrationV2Plugin`'s template-header injection (not just built-but-unused: confirmed called from the plugin, not only from tests).
- [ ] **CWO-006** Add idempotent contact/conversation lookup strategy. — not started; belongs with Event Bridge v2 (still on the roadmap), not the Support Gateway API surface built so far.
- [ ] **CWO-007** Add queued retry/dead-letter structure. — not started for v2; v1's own retry/event-bridge queue (`docs/v2/V1_INVENTORY.md` §"Retry/event bridge") is untouched and still the only one that exists. Also belongs with Event Bridge v2.
- [ ] **CWO-008** Detect/report Captain API feature availability where possible. — partial: `ChatwootCaptainClientInterface`'s docblock records that Captain (incl. Documents) is Enterprise-Edition-gated in self-hosted Chatwoot (verified against `chatwoot/chatwoot` `develop` `enterprise/app/controllers/api/v1/accounts/captain/documents_controller.rb`), and every call degrades to `null`/unavailable rather than a fatal — but there is no explicit "Captain unavailable" health signal surfaced anywhere yet (that belongs with KNO-020's admin UI, still open).
- [x] **CWO-009** Implement optional Captain Document provisioning. — `CaptainDocumentProvisioner`: idempotent create-or-sync keyed on the Knowledge Compiler's own fingerprint, verified against real `chatwoot/chatwoot` `develop` routes (`config/routes.rb`: `namespace :captain do resources :documents, only: [:index,:show,:create,:destroy] do post :sync ... end end`) and the enterprise controller's actual permitted params (`name`, `external_link`, `assistant_id`). No update/PATCH exists in the real API for web-sourced documents — content changes go through `sync` (re-crawl), never a field edit, which is exactly what this provisioner does.
- [x] **CWO-010** Implement optional Captain Custom Tool provisioning. — `CaptainCustomToolProvisioner` + `CanonicalToolCatalog` (12 fixed tools, one per Support API endpoint that makes sense as an LLM-callable tool — never one per provider/journal-fact). Verified against real `chatwoot/chatwoot` `develop` source: `config/routes.rb`'s `resources :custom_tools do post :test, on: :collection end`, `enterprise/app/controllers/api/v1/accounts/captain/custom_tools_controller.rb`'s actual permitted params, `enterprise/app/models/captain/custom_tool.rb`'s `auth_type` enum (`none`/`bearer`/`basic`/`api_key`) and 15-tool account cap, and `enterprise/app/models/concerns/toolable.rb`'s Liquid template rendering (`strict_variables: true`) — which is why every canonical tool param is `required: true` rather than truly optional. Unlike Documents, Custom Tools have a real update endpoint, so a service-token rotation genuinely pushes an update rather than a no-op. **Also fixed a real integration bug found during this work**: Chatwoot's Custom Tool HTTP client always sends `Content-Type: application/json`, but this plugin's Support API only ever read `$request->getUserVar()` (PHP never populates `$_POST` for a raw JSON body) — every provisioned tool call would have seen every field as missing. Fixed with `JsonRequestBodyParser`, wired into `resolveSupportApiRequest()`.
- [x] **CWO-011** Implement optional Captain Scenario provisioning. — `CaptainScenarioProvisioner` + `CanonicalScenarioCatalog` (the 5 families already specified in `KNOWLEDGE_DIAGNOSTICS.md` §7: Journal Information, Account Support, Submission Support, Payment & Publication Support, Human Escalation). Verified against real `chatwoot/chatwoot` `develop` `enterprise/app/controllers/api/v1/accounts/captain/scenarios_controller.rb` and `enterprise/app/models/captain/scenario.rb`. Key finding: `Captain::Scenario` has a `before_save :resolve_tool_references` callback that recomputes its `tools` column by parsing `[Title](tool://slug)` markdown references out of the `instruction` text — the `tools` param the API accepts is not the actual source of truth, so this provisioner never sends it and instead embeds real tool-slug references directly in each scenario's instruction. Depends on Custom Tool provisioning having already run (a tool can only be referenced by its real, Chatwoot-assigned slug); a scenario whose required tools are not yet resolvable fails closed (`required_tool_unavailable`) rather than provisioning a broken reference — verified this can never silently create a scenario with a dangling tool link.
- [x] **CWO-012** Ensure provisioner never deletes unrelated admin resources. — `CaptainDocumentProvisioner` never calls a delete/destroy endpoint at all. Ownership is proven only by a local `CaptainSyncState` record (`chatwoot_support_knowledge_sync` table, keyed by context/locale/resource type) — a name/`external_link` match at the remote API is treated as `unmanaged_document_exists` (a conflict, recorded but never created, never adopted, never touched), exactly per the freeze directive ("never assume same name means plugin-owned").
- [ ] **CWO-013** Add configuration drift/health report. — partial: `CaptainProvisioningHealthService`/`CaptainProvisioningHealthReport`/`CaptainResourceHealth` build a full drift/health snapshot across every expected Captain resource (the one Document, all 12 `CanonicalToolCatalog` tools, all 5 `CanonicalScenarioCatalog` scenarios) — `owned`/`degraded`/`conflict`/`failed`/`not_provisioned` per resource, deterministic overall state. Deliberately a pure read over the local `chatwoot_support_knowledge_sync` records already written during provisioning — never a Chatwoot API call itself, so it is always cheap/safe to build. No admin UI consumes it yet (same deliberate scope boundary as KNO-020).

## IDN — Identity & Verification

Reconciled 2026-08-30: several boxes below were stale (unchecked despite being shipped in earlier PRs, well before external PIN/link verification started). Do not reimplement these.

- [x] **IDN-001** Define verification assurance levels and data types. — V0-V4 (`CapabilityRequest::ASSURANCE_LEVELS`, `SupportSession::assuranceLevel()`), used throughout the capability engine since the first PRs.
- [x] **IDN-002** Create verification challenge migration/repository. — `InstallSupportGatewayMigration::CHALLENGE_TABLE` + `DatabaseVerificationChallengeRepository`/`VerificationChallengeRepositoryInterface`. One shared table/model for both PIN and secure-link challenges (see ADR-005) — never two verification systems.
- [x] **IDN-003** Create support session migration/repository. — `InstallSupportGatewayMigration` + `DatabaseSupportSessionRepository`/`SupportSessionRepositoryInterface`, shipped with the earliest identity PRs.
- [x] **IDN-004** Prototype secure OJS-session ↔ Chatwoot conversation binding. — the `/bind` flow + `ChatwootConversationVerifier`, shipped with the earliest identity PRs.
- [x] **IDN-005** Write ADR selecting authenticated-session handshake. — `docs/v2/ADRS.md` ADR-005 "Adaptive verification".
- [x] **IDN-006** Implement logged-in OJS silent V2 identity path. — authenticated OJS session bootstraps a V2 support session with no PIN/email step (`SupportSessionService::bootstrapAuthenticated`).
- [x] **IDN-007** Implement OJS verification-code Mailable/template. — `SupportVerificationMailable` (extends PKP `Mailable`, sent via `Mail::send()` — real journal SMTP transport, not a bespoke mail stack). Deliberately does not use the full admin-configurable EmailTemplate framework yet (fixed, localizable-in-code content via `VerificationEmailContentBuilder` instead) — a scope decision, not an oversight; a future slice could register a real EmailTemplate row for Journal Setup > Emails customization.
- [x] **IDN-008** Implement verification request endpoint. — `POST /ojsSupportGateway/verificationRequest`.
- [x] **IDN-009** Implement verification confirm endpoint. — `POST /ojsSupportGateway/verificationConfirm` (PIN) + `GET /ojsSupportGateway/verify` (secure link, browser-facing).
- [x] **IDN-010** Implement secure verification link. — shares the same challenge engine as PIN (`VerificationChallengeService::confirmLinkToken()`); binds via the challenge's own server-side stored conversation tuple, since a browser opening the link cannot supply one.
- [x] **IDN-011** Store only challenge secret hash. — PIN: HMAC-SHA256 keyed by a per-journal pepper never stored in the challenge table (`chatwootVerificationPepper` plugin setting). Link: SHA-256 of a 256-bit token (already appropriate given the token's own entropy). See `VerificationSecretHasher`'s docblock for the reasoning.
- [x] **IDN-012** Implement single-use/expiry/revocation. — `VerificationChallenge::isConsumed()/isExpired()/isRevoked()`, enforced atomically in `DatabaseVerificationChallengeRepository::attemptConsume()` (single transaction, row-locked, consume guarded by a `WHERE consumed_at IS NULL` + affected-row-count check — a simultaneous replay can only produce one success). No public admin action to revoke a challenge exists yet (only supersession via resend); the state check itself is implemented and tested.
- [x] **IDN-013** Implement resend invalidation/cooldown. — `VerificationChallengeService::requestChallenge()`: a fresh valid request supersedes the prior unconsumed challenge for the same context+conversation+purpose; a cooldown (default 60s) silently throttles an immediate resend.
- [x] **IDN-014** Implement attempt lockout/rate limits. — per-challenge attempt lockout (default 5, configurable), plus rolling per-conversation and per-identity request limits (default 5 per hour each) — all enforced in `VerificationChallengeService`, all fail silently into the same generic response (never revealed to the caller).
- [ ] **IDN-015** Implement anti-enumeration response/timing tests. — partial: response-content anti-enumeration is implemented and tested (nonexistent email, disabled account, throttling, and mail failure all produce the identical `{verificationRequested:true}` response, verified by a source-level check that the endpoint has exactly one success call site). Response-*timing* is not equalized — no artificial delay is added to the "no matching user" path, so a sufficiently precise timing side-channel is not defended against. Documented as a known gap, not fixed here.
- [x] **IDN-016** Implement support-session idle/absolute expiry. — `SupportSession::idleExpiresAt()`/`absoluteExpiresAt()`, enforced in `SupportApiRequestResolver`, shipped with the earliest identity PRs.
- [x] **IDN-017** Implement session revocation/cleanup task. — `revokeActiveUnboundForUser()` is wired into `SupportSessionService::bootstrapAuthenticated` (a fresh authenticated bootstrap revokes the user's own stale unbound sessions), and `purgeExpired()` on both the session and verification-challenge repositories is now actually swept: `PurgeExpiredSupportDataTask` extends the real `PKP\scheduledTask\ScheduledTask` and `ChatwootIntegrationV2Plugin implements PKP\plugins\interfaces\HasTaskScheduler`, registering the task daily via `PKP\scheduledTask\PKPScheduler::registerPluginSchedules()` — verified against a real local checkout of `pkp-lib` (the current 3.5 mechanism; the older `scheduledTasks.xml`/`getScheduledTaskPaths()` approach no longer exists in this codebase's target version).
- [x] **IDN-018** Implement conversation/context binding checks. — `SupportSession::matchesConversationBinding()` / `findByConversationBinding()`, shipped with the earliest identity PRs.
- [ ] **IDN-019** Audit verification lifecycle.

## REL — Relationship Resolver

- [x] **REL-001** Define normalized relationship enum/model. — `ResourceRelationship` (types set, not single role).
- [x] **REL-002** Resolve author/submitter relationship. — via workflow-stage access (`OjsSubmissionRelationshipEvidenceProvider`).
- [x] **REL-003** Resolve reviewer relationship per review assignment. — actual `Repo::reviewAssignment()` lookup, not the journal-level Reviewer role.
- [x] **REL-004** Resolve editor/sub-editor/assistant/manager/site-admin relationship.
- [ ] **REL-005** Resolve reader/subscriber relationship if used.
- [x] **REL-006** Confirm context/resource ownership before relationship evaluation. — `SubmissionRelationshipResolver` fails closed on a cross-journal submission.
- [x] **REL-007** Multi-role resource-specific relationship tests. — `tests/v2/submission-verify.php` (author+reviewer same submission, plural, not collapsed).
- [x] **REL-008** Horizontal IDOR tests. — `tests/v2/submission-verify.php` (guessed submission ID, cross-journal submission, unrelated user all resolve to empty/no-error).

## POL — Capability & Privacy Policy

- [x] **POL-001** Define capability namespace. — dot-notation names in `CapabilityCatalog::DEFINITIONS` (`journal.read_public_info`, `submission.read_own_payment_status`, etc.).
- [x] **POL-002** Implement deny-by-default Policy Engine. — `CapabilityPolicyEngine::evaluate()`: providers only nominate, every nomination is re-checked against the catalog, an unknown capability fails closed.
- [x] **POL-003** Define public-support consumer plane. — the `public_support`/`support_escalation`/`account_support`/`submission_support`/`publication_support`/`payment_support`/`file_support` policy groups in `CapabilityCatalog`.
- [ ] **POL-004** Define staff consumer plane. — not started, deliberately: this build has stayed public-support-only throughout (the "no public staff mutations" guardrail); `CapabilityCatalog`'s docblock records staff/editorial capabilities as "intentionally absent from this first implementation."
- [ ] **POL-005** Implement author support policy. — partial: author-scoped capabilities exist and gate on the real submitter relationship (`submission.read_own_*` capabilities, `REL-002`'s workflow-stage-access evidence), but there is no single named "author support policy" object distinct from the capability catalog entries themselves — the catalog *is* the policy here, not a separate layer.
- [x] **POL-006** Implement reviewer support policy. — the `reviewer_support` policy group (`review.read_own_assignment`), gated on `REL-003`'s real `Repo::reviewAssignment()` lookup, never the journal-level Reviewer role.
- [ ] **POL-007** Implement staff read policy. — not started; same deliberate scope boundary as POL-004.
- [x] **POL-008** Implement field allowlist serializers. — every `classes/v2/Api/*Serializer.php` (`SubmissionListSerializer`, `PaymentStatusSerializer`, `PublicationStatusSerializer`, `RequiredActionsSerializer`, etc.) is an explicit field allowlist, never a raw-object passthrough.
- [x] **POL-009** Implement blind-review author serializer tests. — `tests/v2/blind-review-anonymity.php`: with two real reviewer assignments on one submission, the author's resolved relationship/serialized `SubmissionSupportSerializer` response is asserted to contain neither reviewer's user id nor any `reviewer_id`/`reviewerName`/`reviewerCount`/`reviewer` text.
- [x] **POL-010** Implement reviewer anonymity serializer tests. — same test file: reviewer A and reviewer B (different assignment statuses on the same submission) each resolve only their own relationship/status/required-action/serialized response — proven against the real `OjsSubmissionRelationshipEvidenceProvider`/`Ojs35CompatibilityAdapter::getReviewAssignmentStatuses()`/`RequiredActionMapper`/`SubmissionSupportSerializer` end-to-end, not isolated units. The underlying guarantee is structural: those lookups only ever call `filterByReviewerIds([$userId])` — "does this user have an assignment," never "list every reviewer" — so there was no enumeration path to redact in the first place.
- [ ] **POL-011** Replace v1 global reviewer masking behavior. — not started; v1's `enablePrivacyMode`/reviewer-masking logic in `ChatwootIntegrationPlugin::addChatwootWidget()` (see CWO-003's `is_masked` attribute) is untouched, and v2 has not built an equivalent or replacement.
- [x] **POL-012** Implement `get_available_actions`. — `AvailableActionMapper::map()`, converting a `CapabilityDecision` into stable support-facing action names (`RequiredActionsSerializer` consumes it).
- [x] **POL-013** Audit allow/deny reason codes. — `CapabilityDecision`'s denial reasons, filtered through `AvailableActionMapper::SAFE_DENIAL_REASONS` before ever reaching a support-facing response (internal plumbing states excluded).

## API — REST Support API

- [x] **API-001** Implement API route/PageHandler strategy per supported OJS versions. — `LoadHandler` + `SupportGatewayPageHandler`, same pattern as `/bind`; status/identity/actions operations.
- [x] **API-002** Implement service authentication. — `ServiceTokenAuthenticator`, Bearer token, comma-separated for rotation, constant-time comparison.
- [x] **API-003** Require HTTPS/config check for production. — `SupportApiRequestResolver::transportSecure()`; accepts direct HTTPS or `X-Forwarded-Proto: https` from a fronting reverse proxy. No trusted-proxy allowlist yet (see its docblock).
- [x] **API-004** Implement correlation/request IDs. — `CorrelationId`; echoes a caller-supplied `X-Correlation-Id` when it matches a safe pattern, otherwise generates one.
- [x] **API-005** Implement common response/error envelope. — `SupportApiResponse` (`{ok,data,meta}` / `{ok,error,meta}`), used by status/identity/actions. `/bind` intentionally keeps PKP `JSONMessage` (browser handshake, separate transport).
- [ ] **API-006** Implement request validation/unknown-field rejection. — required-field presence is validated; extra/unknown fields are not yet rejected.
- [ ] **API-007** Implement REST rate limits. — best-effort fixed-window limiter (`RateLimiter`) exists and is wired in, but fails open without APCu and is per-worker only; not yet a real cross-worker ceiling.
- [x] **API-008** `ojs_get_support_identity`. — `POST /ojsSupportGateway/identity`, sanitized via `SupportIdentitySerializer` (no email, no raw relationship evidence, no raw OJS object).
- [x] **API-009** `ojs_list_my_submissions`. — `POST /ojsSupportGateway/submissions`, gated on `submission.list_own`, candidates from the OJS-native submission collector filtered through `SubmissionRelationshipResolver`. No milestone/date field yet.
- [x] **API-010** `ojs_get_submission_support`. — `POST /ojsSupportGateway/submissionSupport`, establishes its own request-time V3 the same way `submissionVerify` does, gated on `submission.read_own_support_status`. Returns normalized support state, one safe explanatory sentence (`SupportStateMapper::explain()`), title, relationships, and capability-derived actions. Required actions, publication detail, and milestone dates are separate not-yet-built endpoints (API-011/API-012/API-013) — this one does not anticipate their shape.
- [x] **API-011** `ojs_get_required_actions`. — `POST /ojsSupportGateway/requiredActions`, establishes its own request-time V3 the same way `submissionVerify`/`submissionSupport` do, gated on `submission.read_own_required_actions`. New `RequiredActionMapper` only reports an action directly provable from existing evidence: author `draft`→`complete_submission`, `revision_requested`→`submit_revisions`; reviewer statuses via PKP's own computed `ReviewAssignment::getStatus()` (verified against `pkp-lib` stable-3_5_0) mapped to `respond_to_review_invitation`/`submit_review`, most-urgent-wins across multiple review rounds. Empty list is a correct answer for every other state, not a placeholder.
- [x] **API-012** `ojs_get_payment_status`. — `POST /ojsSupportGateway/paymentStatus`, gated on `submission.read_own_payment_status` (V3 + author only; verified against `pkp-lib`/`ojs` `stable-3_5_0` `OJSPaymentManager`/`OJSCompletedPaymentDAO`, never re-derives their logic). Public fee facts (`feeEnabled`/`amount`/`currency`) are always returned regardless of verification — they're journal-level, not submission-specific. `payment_status` feature flag is derived live from `OJSPaymentManager::isConfigured() + publicationEnabled()`. The submission-specific `status` (paid/unpaid/not_applicable) additionally requires the `payment_support` journal policy, which defaults to `false` with no admin toggle built yet — intentionally unreachable in production until PRV/settings work exists to opt a journal in. `waived` is not implemented for the native OJS producer: no genuine evidence of a fee-waiver concept was found in OJS core. Since PRV-001/PRV-005, the endpoint additionally returns an additive `obligations` array (empty unless the Airix Submission Fee sibling plugin is installed/enabled/compatible) via `SupportProviderRegistry`; when a provider reports an obligation it — not the native publication fee — becomes the authoritative `status`/`amount`/`currency` for that submission (see `AIRIX360_INTEGRATIONS.md` §5.8 on producer vs. collector). This is the one Airix payment adapter built so far (APS-*); waiver/gateway/orchestrator adapters (AWA/APM/AGW) remain unbuilt.
- [x] **API-013** `ojs_get_publication_status`. — `POST /ojsSupportGateway/publicationStatus`, establishes its own request-time V3 the same way the other submission-scoped endpoints do, gated on `submission.read_own_publication_status`. `doi`/`publicUrl`/`issue` are only populated when the normalized support state is exactly `published` or `scheduled_for_publication`; `publicUrl` further restricted to `published` only (a scheduled article isn't yet publicly visible). Issue metadata (volume/number/year) is only surfaced when the linked `Issue` itself reports `getPublished() === true`, as a fail-safe against ever leaking an unpublished issue's data through a published article. Every other state returns `status: 'not_yet_published'` with no other fields.
- [x] **API-014** `ojs_diagnose_account`. — `POST /ojsSupportGateway/accountDiagnostics`, gated on new capability `account.diagnose_own` (V2, no resource relationship — this is the caller's own account). New `classes/v2/Diagnostics/DiagnosticResult.php` (shared status/code/summary/evidenceCodes/nextActions/retryable contract, reused by future `ojs_diagnose_submission`) and `AccountDiagnosticEngine` covering scopes `account_access`/`login`/`profile` as `confirmed` (from `User::getDisabled()`/`getDateValidated()`, verified against `pkp-lib` stable-3_5_0) and `password_reset` always as `unknown` (no OJS evidence of email delivery exists). Never accepts a caller-supplied email/username — diagnoses only the verified caller's own account, never an arbitrary lookup.
- [x] **API-015** `ojs_diagnose_submission`. — `POST /ojsSupportGateway/submissionDiagnostics`, gated on new capability `submission.diagnose_own` (V3, author/reviewer). New `SubmissionDiagnosticEngine` covers scopes `submission_access`/`submission_progress`/`required_action`/`review_access`/`publication`/`payment` — every scope is a thin wrapper over the existing `SubmissionRelationshipResolver`/`SupportStateMapper`/`RequiredActionMapper`/publication+payment field services, not a second workflow interpreter. The `payment` scope independently re-evaluates `submission.read_own_payment_status` exactly like the dedicated payment endpoint, so it can never reveal more than that endpoint would (still gated off by the `payment_support` policy default).
- [x] **API-016** `ojs_escalate_support`. — `POST /ojsSupportGateway/escalate`, gated on the existing `support.escalate` capability (deliberately V0/unauthenticated — a handoff must remain available even when verification itself is failing). New `HandoffSummaryFormatter` (HOF-001..006) composes a safe DTO from `SupportIdentitySerializer` (verification method/expiry) plus, only when independently re-checked against each fact's own dedicated capability (`submission.read_own_support_status`/`read_own_required_actions`/`read_own_publication_status`/`read_own_payment_status`), resource relationship/support state/required actions/publication/payment facts — never more than the verified caller could already read via those dedicated endpoints. Posts a Chatwoot private note via the existing (v1) `ChatwootApiService::createConversationNote()`, reused rather than rebuilt; posting is best-effort and never fails the whole request. `EscalationIdempotencyGuard` (HOF-007) is APCu-backed, same fail-open/per-worker character as `RateLimiter` — a durable idempotency ledger is the upgrade path, not built here.
- [ ] **API-017** Generate OpenAPI/machine-readable schema.
- [ ] **API-018** Contract-test all public responses.
- [x] **API-019** Keep Captain-provisioned custom tools <=12. — `CanonicalToolCatalog::all()` defines exactly 12, enforced by a test assertion (`count($tools) <= 12`), well under Chatwoot's own account-wide cap of 15 (`Captain::CustomTool::MAX_PER_ACCOUNT`).

## STA — Support State Engine

- [x] **STA-001** Define normalized support states. — `SupportStateMapper` (`classes/v2/State/`) now covers draft/submitted/review_in_progress/revision_requested/copyediting_in_progress/production_in_progress/published/declined/scheduled_for_publication/unknown. Correction to an earlier note: draft did *not* need a separate candidate-discovery path — OJS 3.5 creates the author's StageAssignment immediately at submission creation (verified against `pkp-lib` stable-3_5_0 `PKPSubmissionController::add()`), so the existing `assignedTo()`-based discovery already reaches drafts; only `submissionProgress` needed reading. Still missing: revision_received (needs revision-file evidence).
- [x] **STA-002** Map submission stages/statuses for OJS 3.5 target. — `status`/`stageId` mapped as before; `revision_requested` now additionally derived from the current review round's live `status` column (`ReviewRoundDAO::getLastReviewRoundBySubmissionId`, verified against `pkp-lib` stable-3_5_0), read-only, never recomputed independently.
- [ ] **STA-003** Map OJS 3.6 target after compatibility verification.
- [ ] **STA-004** Determine safe reviewer-progress facts without identities.
- [x] **STA-005** Determine author-action-required rules. — `RequiredActionMapper::forAuthor()` covers the two provable cases (draft→complete_submission, revision_requested→submit_revisions); every other state correctly returns no action rather than a guess.
- [ ] **STA-006** Determine revision status/deadline rules. — round-level `revision_requested` detection exists (STA-002); no deadline-date field is read/returned yet.
- [ ] **STA-007** Determine copyediting/production/publication rules.
- [ ] **STA-008** Return confidence/evidence codes.
- [x] **STA-009** Unknown-state fallback tests. — `tests/v2/submission-list.php` covers unrecognized status, unrecognized stageId, and missing status/stageId all falling back to `unknown` rather than a guess.

## KNO — Journal Knowledge Compiler

Note: this is the foundation slice only, per the compiler-freeze directive — a
KnowledgeProvider must never depend on a SupportSession, Chatwoot conversation,
OJS user, submission relationship, or V2/V3 capability, and only the exact
classification `public` (not `private`/`unsupported`, and never an
unrecognized value) ever reaches a generated page. Categories are built
incrementally (about/submissions/review/policies first); fees/publication/
accounts/official-pages/sitemap/Captain sync are deliberately deferred to
later PRs, not attempted in one pass.

`PaymentSupportProviderInterface` (Provider Registry, PRV/AXP/APS above) and
`KnowledgeProviderInterface` are different trust contracts and must not be
merged just because both are "providers": a submission's specific payment
*obligation* (paid/unpaid/waived/refund_review/refunded) is private live
state and must never become a KnowledgeFact; a journal's configured fee
*policy* ("this journal charges a $50 submission fee") could become public
knowledge, but only through a provider explicitly implementing
`KnowledgeProviderInterface` — never inferred by a Knowledge provider
inspecting `AirixSubmissionFeeProvider`'s obligations.

- [x] **KNO-001** Define `KnowledgeProvider` contract. — `KnowledgeProviderInterface` (`classes/v2/Contracts/`).
- [x] **KNO-002** Define classification/provenance model. — `KnowledgeFact` (key/value/classification/source/sourceReference/locale/updatedAt/providerId) + `KnowledgeClassification` (`public`/`private`/`unsupported` only; the constructor rejects anything else).
- [x] **KNO-003** Core journal/contact provider. — `CoreJournalKnowledgeProvider`: name, description, about, publisher, contact name/email, ISSN (online/print), languages, public journal URL — every accessor verified against a real local checkout of `pkp-lib`/`ojs` `stable-3_5_0` (`schemas/context.json`, `classes/context/Context.php`).
- [x] **KNO-004** Submission guidelines/checklist provider. — folded into `CoreJournalKnowledgeProvider` (`submission.authorGuidelines`, `submission.checklist`) rather than a separate class; one core provider for now, split out only if a real second source needs its own adapter.
- [x] **KNO-005** Sections/languages provider. — folded into `CoreJournalKnowledgeProvider` (`submission.sections` via `Repo::section()->getSectionList()`, `journal.languages` via `getSupportedLocales()`).
- [x] **KNO-006** Review policy provider. — folded into `CoreJournalKnowledgeProvider` (`review.model`, deterministically derived from `ReviewAssignment::SUBMISSION_REVIEW_METHOD_*` — never an invented sentence like "reviews take 4-6 weeks"; `review.guidelines` from the journal's own configured text).
- [x] **KNO-007** Publication/open-access/licence provider. — `policy.copyright`/`policy.licenseTerms`/`policy.licenseUrl` (KNO-003) plus new `CorePublicationKnowledgeProvider`: `publication.accessModel` (deterministic sentence from `Journal::PUBLISHING_MODE_{OPEN,SUBSCRIPTION,NONE}`, verified against `ojs` `stable-3_5_0`; a configured `delayedOpenAccessDuration` is stated verbatim, never an invented timeline), `policy.openAccessPolicy` (the journal's own configured text), `publication.doiAssigned` (from `enableDois`), `publication.currentIssueUrl`/`publication.archiveUrl` (the real `issue/current`/`issue/archive` page routes OJS core itself uses in `NotificationManager`/`OJSPaymentManager`/`SitemapHandler`). DOI prefix/suffix pattern, archival-preservation participation (CLOCKSS/LOCKSS), and publication frequency are not implemented — deferred, not guessed.
- [x] **KNO-008** APC/payment public policy provider. — `CorePaymentKnowledgeProvider`: native OJS publication fee (`fee.publicationEnabled`/`Amount`/`Currency`, via the same verified `OJSPaymentManager` path `ojs_get_payment_status` uses) and Airix Submission Fee policy (`fee.submissionEnabled`/`Amount`/`Currency`, via the new `Ojs35CompatibilityAdapter::getAirixSubmissionFeePolicy()` — policy-only, calls only `PaymentHelper::feeEnabled()/amount()/currency()`, never `hasPaid()`/`waiverDiscount()`/`needsRefundReview()`). Distinct key prefixes (`publication`/`submission`) so the two producers can never collide or silently overwrite each other. Waiver policy text and payment instructions are not implemented — no verified public-facing accessor was found for either.
- [ ] **KNO-009** DOI/public identifier provider.
- [x] **KNO-010** Official page/navigation provider. — `OfficialPageKnowledgeProvider`, narrowly scoped to OJS core's own Static Pages plugin (verified against a real local checkout of `pkp/staticPages` @ `0a84bbe738b3356ac57fe99c66f2792f0d7016bb`) via new `Ojs35CompatibilityAdapter::getOfficialPublicPages()` — never a crawl of arbitrary journal-domain URLs. Each journal-manager-authored static page becomes one `officialPage.<path>` fact, title+content sanitized. Ranked lowest of the structured sources in `KnowledgeSourcePrecedence` (tier 3), so a stale static page can never override current structured configuration.
- [ ] **KNO-011** Approved FAQ/support knowledge provider.
- [x] **KNO-012** Fingerprinting/staleness model. — `KnowledgeFingerprint::compute()`: deterministic, order-independent, sensitive only to normalized `locale`+`key`+`value` (never rendered HTML, never provenance metadata).
- [x] **KNO-013** Generate `/support-knowledge/` root. — `support-knowledge` page (`SupportKnowledgePageHandler::index`), links every generated category page.
- [x] **KNO-014** Generate category pages. — `about`/`submissions`/`review`/`fees`/`publication`/`pages`/`accounts`/`policies` — the full first-slice category set.
- [x] **KNO-014a** Precedence/conflict handling (not separately numbered in the original backlog; added once a second structured/semi-structured source existed). `KnowledgeSourcePrecedence` ranks sources into the four tiers from the freeze directive (structured OJS config > verified structured third-party provider > official OJS-managed page > approved FAQ; an unrecognized source ranks last, never silently wins). `KnowledgeCompiler` deduplicates same-`(locale,key)` collisions by rank and records every loser as a `KnowledgeConflict` on the compilation — never rendered, exposed only as safe metadata via `KnowledgeHealthService` (KNO-020).
- [x] **KNO-015** Generate sitemap. — `/support-knowledge/sitemap` (see the URL-suffix note in `KNOWLEDGE_DIAGNOSTICS.md` §4 — PKP page/operation routing cannot dispatch a literal `sitemap.xml` segment to a handler method), `Content-Type: application/xml`, XML-escaped, drawing its route list from the same `KnowledgeRouteCatalog` the root navigation uses.
- [x] **KNO-016** HTML sanitization/escaping. — `KnowledgeSanitizer`: strips `script`/`style`/`iframe`/`object`/`embed`/`form`/`applet`/`link`/`meta`/`base`/`noscript`, event-handler attributes, and `javascript:`/`vbscript:`/`data:` URLs; permits a small safe presentation tag subset. Regex-based by design, not a new HTML-parser dependency (PR #19 lesson).
- [x] **KNO-017** Private-data exclusion tests. — `tests/v2/knowledge-compiler.php`: multi-journal isolation, private/unsupported facts never render, provider-exception isolation, no `SupportSession`/conversation ID/Chatwoot token/payment-obligation-state string anywhere in `classes/v2/Knowledge/`.
- [x] **KNO-018** Multi-locale support. — `KnowledgeCompiler::resolveLocale()`: requested locale -> journal-supported locale -> journal primary locale -> `en`, reusing `Context::getSupportedLocales()`/`getPrimaryLocale()` rather than re-deriving OJS's own locale rules; fingerprint is per resolved locale.
- [x] **KNO-019** Captain document sync trigger/manual fallback. — partial: `CaptainSyncScheduledTask` now drives `provisionCaptainKnowledgeDocument()`/`provisionCaptainCustomTools()`/`provisionCaptainScenarios()` daily for every enabled journal, registered alongside `PurgeExpiredSupportDataTask` via the same `HasTaskScheduler`/`PKPScheduler` mechanism (IDN-017). Tools are provisioned before scenarios per journal since a scenario instruction can only reference an already-assigned tool slug. A manual on-demand trigger (an admin settings action, Phase 13) is still a separate, unbuilt path — this closes the periodic/automatic half of the item, not the manual-fallback half.
- [ ] **KNO-020** Knowledge health UI. — partial: the service/model half is done: `KnowledgeHealthService`/`KnowledgeHealthReport` (deterministic `healthy`/`degraded`/`empty`/`failed` state, per-provider health, excluded-fact counts, safe conflict metadata, generated-routes list). No admin UI consumes it yet — deliberately out of this PR's scope per the freeze directive ("no admin UI necessary in the same PR; the service/model is enough").

### Also added this phase (accounts category, KNO-006 scope)

- `accounts.registrationAvailable`/`registrationUrl` (only when available)/`loginUrl`/`passwordResetUrl`/`orcidEnabled` — `AccountsKnowledgeProvider`, verified against `pkp-lib` `stable-3_5_0` (`RegistrationHandler::validate()`'s exact `disableUserReg` gate, `\PKP\orcid\OrcidManager::isEnabled()`, and the real `user/register`/`login`/`login/lostPassword` routes OJS core's own frontend templates use). Journal-specific approved FAQs (the rest of the original "Accounts/support" domain) remain unbuilt.

## PAY — Payment & Publication

- [x] **PAY-001** Read public publication fee/currency through OJS adapter. — `Ojs35CompatibilityAdapter::getPaymentFeeInfo()`.
- [x] **PAY-002** Resolve completed publication payment for authorized submission. — `Ojs35CompatibilityAdapter::hasPaidPublicationFee()`.
- [x] **PAY-003** Normalize `not_applicable|unpaid|paid|waived|unknown`. — `PaymentObligationStatus` (also `PARTIALLY_WAIVED`/`REFUND_REVIEW`/`REFUNDED`, a superset of the originally-scoped set).
- [x] **PAY-004** Verify unauthorized submission payment query is denied. — `tests/v2/payment-status.php`'s "capability denied by default (payment_support policy is off)" case, exercised through the real `CapabilityPolicyEngine`.
- [x] **PAY-005** Ensure payment provider failure returns unknown, not unpaid. — `SupportProviderRegistry::resolveObligations()`'s `ObligationResolution::hasFailures()` branch; `supportPaymentStatusRequest()` reports `PaymentObligationStatus::UNKNOWN` rather than silently falling back to a different producer's state (verified by both `tests/v2/provider-registry.php` and a plugin-source check).
- [x] **PAY-006** Publication status provider. — `PublicationStatusSerializer` + `Ojs35CompatibilityAdapter::getPublicationFields()`.
- [x] **PAY-007** Issue assignment/public URL provider. — `Ojs35CompatibilityAdapter::getIssueInfo()`/`getPublicSubmissionUrl()`.
- [x] **PAY-008** DOI safe status provider. — `getPublicationFields()`'s `doi` field, exposed through `PublicationStatusSerializer`.
- [x] **PAY-009** Keep public plane payment writes disabled. — no payment write/mutation endpoint exists anywhere in the plugin; confirmed by absence rather than an explicit block (there is nothing to disable — consistent with the "no public staff mutations" guardrail applied throughout this build).

## DIA — Diagnostics

- [x] **DIA-001** Define diagnostic result schema. — `DiagnosticResult` (`confirmed`/`likely`/`unknown`/`needs_human`, privacy-safe machine-readable evidence codes only, never a raw DAO row/exception message/internal config value).
- [x] **DIA-002** Account/login diagnostic. — `AccountDiagnosticEngine::SCOPE_ACCOUNT_ACCESS`/`SCOPE_LOGIN`.
- [x] **DIA-003** Registration/reset-path diagnostic. — `AccountDiagnosticEngine::SCOPE_PASSWORD_RESET` (deliberately always `unknown` — no OJS evidence about email delivery/reset-link validity exists to check, so this scope's whole job is refusing to guess) / `SCOPE_PROFILE` for email validation.
- [x] **DIA-004** Submission access diagnostic. — `SubmissionDiagnosticEngine::SCOPE_SUBMISSION_ACCESS`.
- [x] **DIA-005** Submission workflow/progress diagnostic. — `SubmissionDiagnosticEngine::SCOPE_SUBMISSION_PROGRESS`.
- [x] **DIA-006** Required metadata/file diagnostic where deterministic. — new `SubmissionDiagnosticEngine::SCOPE_REQUIRED_FILES`/`diagnoseRequiredFiles()`, backed by `Ojs35CompatibilityAdapter::getMissingRequiredSubmissionFileGenreNames()`: compares the journal's Airix `RequiredSubmissionFilesPlugin`-configured required genres against the submission's real uploaded files (`Repo::submissionFile()`). Deterministic by construction — a genre either has a matching file or it does not. "Nothing required" and "everything uploaded" both report the same `REQUIRED_FILES_COMPLETE`, since both are factually true regardless of which applies. Wired into `supportSubmissionDiagnosticsRequest()`'s real scope dispatch, not just built-and-unused.
- [x] **DIA-007** Upload PHP/OJS limit diagnostic. — new `Ojs35CompatibilityAdapter::getUploadLimits()` reads the same `upload_max_filesize`/`post_max_size` php.ini values pkp-lib itself derives its `UPLOAD_MAX_FILESIZE` constant from (there is no separate OJS-level setting). `SubmissionDiagnosticEngine::SCOPE_UPLOAD_LIMIT`/`diagnoseUploadLimit()` flags the one universally well-known, deterministic misconfiguration (`post_max_size` below `upload_max_filesize`) — never a guessed "too small" threshold, since none is universally correct. System-wide, not submission-specific; wired into the same `supportSubmissionDiagnosticsRequest()` scope dispatch as the others.
- [x] **DIA-008** Review access diagnostic. — `SubmissionDiagnosticEngine::SCOPE_REVIEW_ACCESS`.
- [x] **DIA-009** Payment diagnostic. — `SubmissionDiagnosticEngine::SCOPE_PAYMENT`.
- [x] **DIA-010** Publication/DOI diagnostic. — `SubmissionDiagnosticEngine::SCOPE_PUBLICATION`.
- [ ] **DIA-011** Mail configuration/send-path diagnostic. — not done; `SCOPE_PASSWORD_RESET`'s docblock explicitly punts on email delivery evidence rather than implementing a mail-configuration check.
- [ ] **DIA-012** Public vs staff diagnostic serializers. — not done, deliberately: only `DiagnosticResultSerializer` (public) exists — same scope boundary as POL-004/007 (no staff consumer plane built yet).
- [x] **DIA-013** Unknown/needs-human behavior tests. — extensively covered for `unknown` across both `tests/v2/account-diagnostics.php` and `tests/v2/submission-diagnostics.php` (missing evidence, ambiguous null fields, unrecognized scope, denied capability never revealing specific state). No engine currently constructs `needs_human` — the status exists in the schema (`DiagnosticResult::needsHuman()`) but no scope has a real trigger for it yet, so that half of the status enum is untested by necessity, not by omission.

## EVT — Event Bridge

- [x] **EVT-001** Define normalized `SupportEvent` model. — `SupportEvent` (immutable DTO: type/contextId/resource/idempotencyKey/occurredAt/attributes, never a delivery mode — that's EVT-010's job) + `SupportEventType` (the 7 real v1-derived event kinds, dot-notation named consistent with `CapabilityCatalog`). Not yet wired to any real OJS hook — that begins at EVT-003.
- [x] **EVT-002** Stable idempotency keys. — `SupportEvent::create()`'s `$naturalKey` param + `deriveIdempotencyKey()`: a deterministic hash of (type, contextId, resourceType, resourceId, naturalKey) — never a random value, never dependent on `occurredAt`, so a genuine retry of the same real occurrence always collides while a different decision/type/resource/journal never does. `naturalKey` sourcing per real event (e.g. a real OJS decision's own id for `submission.decision_recorded`) is left to each EVT-003+ event adapter, since only that adapter knows the real distinguishing detail for its event kind.
- [ ] **EVT-003** Migrate v1 submission-created event. — partial: `SubmissionCreatedEventAdapter::fromSubmission()` converts a real submission into a normalized `submission.created` `SupportEvent`, deliberately excluding the author identity fields v1 bundles into the same payload (a delivery-target concern, not an event fact). Not wired to the real `handleSubmissionCreated()` hook or any delivery path yet — that wiring is a separate, higher-risk slice given it touches live production hook behavior.
- [ ] **EVT-004** Migrate v1 decision event. — partial: `DecisionRecordedEventAdapter::fromDecision()` converts a real editorial decision into a normalized `SupportEvent`, mirroring `handleEditorDecision()`'s `mapDecisionEventKey()` — a decision code maps to the specific `submission.revision_requested`/`accepted`/`rejected` type where one applies (see EVT-006), falling back to the generic `submission.decision_recorded` otherwise (excluding author identity, same as EVT-003). Idempotency key is keyed on the decision's own id (not the submission id alone), since a submission receives many decisions over its lifetime. Not wired to the real hook or any delivery path yet.
- [ ] **EVT-005** Migrate v1 status/publication event. — partial: `SubmissionStatusChangedEventAdapter` (mirrors `handleSubmissionStatusUpdated()`, `submission.accepted`/`submission.rejected`) and `PublicationStatusEventAdapter` (mirrors `handlePublicationPublished()`, `publication.scheduled`/`publication.published`, keyed on the publication's own id) both convert real OJS state into normalized events. Not wired to any real hook or delivery path yet.
- [x] **EVT-006** Add revision event adapters where stable. — v1 has no separate revision hook; `DecisionRecordedEventAdapter` (EVT-004) now maps `PENDING_REVISIONS`/`RESUBMIT`/`RECOMMEND_PENDING_REVISIONS`/`RECOMMEND_RESUBMIT` decision codes to `submission.revision_requested`, the exact same real source `handleEditorDecision()`'s `mapDecisionEventKey()` uses for v1's `eventRevisionRequested` setting.
- [x] **EVT-007** Add review event adapters where stable. — `ReviewSubmittedEventAdapter` (`submission.review_submitted`, new `SupportEventType` — v1 never had a review event, so nothing to migrate) built on the real, stable `PKP\submission\reviewAssignment\Repository::edit()`'s `ReviewAssignment::edit` hook, keyed on the review assignment's own id. Deliberately scoped to the single clearest, most support-relevant transition (a review was submitted); never includes reviewer identity (POL-009/010 discipline). Not wired to the real hook or any delivery path yet.
- [ ] **EVT-008** Add payment event adapters where stable. — verified against a real local `pkp-lib` checkout: there is no `Hook::call`/`Hook::run` anywhere in `classes/payment/`. No stable hook exists to adapt; this item has nothing to build until pkp-lib adds one. Consistent with `PaymentSupportProviderInterface`'s existing on-demand-poll design rather than a payment event.
- [ ] **EVT-009** Add DOI event adapters where stable. — same finding as EVT-008: no `Hook::call`/`Hook::run` exists anywhere in `classes/doi/` in a real local `pkp-lib` checkout. Nothing stable to build.
- [ ] **EVT-010** Delivery policy per journal/event. — partial: `EventDeliveryPolicy::resolve()` + `EventDeliveryMode` implement the pure policy/filter decision (docs/v2/ARCHITECTURE.md §3.9's 5 modes), preserving v1's real `eventSyncMode` values (`note`/`open_update`) as the per-journal global default and adding per-event-type overrides v1 never had. No admin settings UI exists yet to populate per-event overrides (same deliberate scope boundary as KNO-020/CWO-013) — global-mode-only until one does. Not wired to any real delivery path yet.
- [ ] **EVT-011** Private note delivery.
- [ ] **EVT-012** Open/update conversation delivery.
- [ ] **EVT-013** Opt-in proactive message delivery.
- [ ] **EVT-014** Dead-letter/retry UI.
- [ ] **EVT-015** Replay/duplicate tests.

## HOF — Human Handoff

- [x] **HOF-001** Define safe handoff summary DTO. — `HandoffSummaryFormatter::build()`.
- [x] **HOF-002** Include verification method/expiry safely. — reuses `SupportIdentitySerializer::serialize()` directly rather than re-deriving.
- [x] **HOF-003** Include verified relationship/resource. — only when a real, independently-resolved relationship exists.
- [x] **HOF-004** Include normalized support state/action. — only after independently re-checking `submission.read_own_support_status`/`read_own_required_actions`.
- [ ] **HOF-005** Include safe recent event/diagnostic context. — not wired: `ojs_escalate_support` does not accept a caller-supplied diagnostic result (untrustworthy — Captain could claim any code) and does not internally re-run `SubmissionDiagnosticEngine` itself yet. A future slice could have the endpoint run the same diagnostic scopes it already has access to and fold the result in the same independently-re-checked way the other facts are.
- [x] **HOF-006** Explicitly exclude confidential review/editorial data. — reviewer identities/recommendations are never read; `HandoffSummaryFormatter` only arranges facts already proven safe elsewhere.
- [x] **HOF-007** Create/update private note idempotently. — `EscalationIdempotencyGuard`, best-effort/APCu-backed only (see API-016 note); not a durable cross-worker ledger.

## PRV — Provider Registry

Note: `ojs_get_payment_status` (API-012) was originally built directly against OJS's own native `OJSPaymentManager`/`OJSCompletedPaymentDAO` with no Provider Registry — there was exactly one real payment provider (OJS itself) to support at the time. A second, genuinely different provider now exists (Airix Submission Fee, verified against `Airix360/submissionFee-OJS` 1.7.0.0), so a minimal registry was built to support it — see `classes/v2/Provider/SupportProviderRegistry.php` and `docs/v2/AIRIX360_TASKLIST.md` AXP-*/APS-*. It is deliberately scoped to payment obligation providers only; the full Knowledge/Capability/Diagnostic/Event provider surface described in `AIRIX360_INTEGRATIONS.md` §4 remains unbuilt until a real provider needs each one.

- [x] **PRV-001** Define provider registry service. — `SupportProviderRegistry` (`registerPaymentProvider()`, `resolveObligations()`); payment-obligation providers only, not the full four-interface SDK.
- [x] **PRV-002** Define registration hook/API. — `discoverPaymentProviders()` fires `Hook::call('ChatwootIntegration::SupportProviders', [$registry])` so a sibling plugin can self-register without a hard-coded adapter (AXP-001); the first-party Airix Submission Fee provider is still detected/constructed directly by `Ojs35CompatibilityAdapter::getAirixSubmissionFeeProvider()`, not through the hook.
- [x] **PRV-003** Provider applicability/health contract. — `ProviderHealth::{AVAILABLE,DISABLED,NOT_INSTALLED,INCOMPATIBLE_VERSION,DEGRADED,UNAVAILABLE,UNKNOWN}`; the registry only calls `resolveObligation()` on a provider reporting `AVAILABLE`.
- [ ] **PRV-004** Provider capability/classification contract. — Only `PaymentSupportProviderInterface` exists; `AccountSupportProviderInterface`/`SubmissionRequirementProviderInterface`/`ContributorSupportProviderInterface` (AIRIX360_INTEGRATIONS.md §4.2-4.4) are not yet defined — no verified provider needs them yet.
- [x] **PRV-005** Reference example provider. — `AirixSubmissionFeeProvider`, verified against `Airix360/submissionFee-OJS` 1.7.0.0's public `PaymentHelper` surface (never its settings table directly).
- [x] **PRV-006** Provider isolation/failure tests. — `tests/v2/provider-registry.php`: a throwing provider, a non-`AVAILABLE` provider, and a null-returning provider must never prevent an unrelated real provider's obligation from resolving; a non-`AVAILABLE` provider's `resolveObligation()` must never even be called.
- [ ] **PRV-007** Document third-party integration guide. — The `ChatwootIntegration::SupportProviders` hook exists and is tested (a fake provider registers through it in `tests/v2/provider-registry.php`), but no standalone third-party-facing guide/reference plugin has been written yet.

## MCP — MCP Adapter

- [ ] **MCP-001** Select protocol/library implementation strategy.
- [ ] **MCP-002** Define MCP authentication/client model.
- [ ] **MCP-003** Implement public/read MCP tool set.
- [ ] **MCP-004** Implement safe public resources.
- [ ] **MCP-005** Separate staff tool namespace/credential.
- [ ] **MCP-006** REST/MCP equivalence contract tests.
- [ ] **MCP-007** Verify public client cannot reach staff capability.
- [ ] **MCP-008** OpenClaw integration test.
- [ ] **MCP-009** Document that Chatwoot-native MCP is not required.

## AUD — Audit & Observability

- [x] **AUD-001** Audit migration/repository. — `chatwoot_support_audit_log` table (`InstallSupportGatewayMigration::upAuditLog()`) + `DatabaseSupportApiAuditLogger`, now the default sink `SupportApiRequestResolver` constructs; `ErrorLogSupportApiAuditLogger` remains as a fallback-only implementation, no longer wired as the default. Query/browse UI not built (not required by AUD-001's own scope; see AUD-008 for a dashboard).
- [x] **AUD-002** Correlation IDs across REST/events/Chatwoot. — Support API side only so far (`CorrelationId`); not yet threaded through the event/Chatwoot side of the plugin.
- [ ] **AUD-003** Verification lifecycle audit.
- [x] **AUD-004** Protected read allow/deny audit. — `SupportApiRequestResolver` records every allow/deny decision (with reason code) through `SupportApiAuditLoggerInterface`.
- [ ] **AUD-005** Staff mutation audit.
- [x] **AUD-006** Secret/PII log redaction tests. — `DatabaseSupportApiAuditLogger::record()` allowlists exactly the fields the resolver ever emits (`correlationId`/`endpoint`/`contextId`/`decision`/`reason`/`assurance`); an unrecognized key is silently dropped, including on the DB-failure `error_log()` fallback path (fixed a real leak: the fallback previously logged the raw un-allowlisted `$event`, not the allowlisted row — caught by `tests/v2/audit-logger.php`). Scope is limited to this one sink; no redaction tests exist yet for other log call sites in the plugin.
- [x] **AUD-007** Configurable retention/purge. — `PurgeExpiredSupportDataTask` now also purges audit rows past a 90-day retention window (`DatabaseSupportApiAuditLogger::purgeOlderThan()`), registered via the same daily `HasTaskScheduler` schedule as session/challenge purging. Retention is a class constant, not an admin-configurable setting — "configurable" here means "one code-level knob," not a settings-UI field; revisit if a real need for a per-journal retention setting emerges.
- [ ] **AUD-008** Health dashboard for components/providers/queues.

## TST — Test Program

- [ ] **TST-001** Unit test suite.
- [ ] **TST-002** OJS integration test harness.
- [ ] **TST-003** Chatwoot mock/contract harness.
- [ ] **TST-004** OJS 3.5 exact-version matrix.
- [ ] **TST-005** OJS 3.6 exact-version matrix.
- [ ] **TST-006** Supported MySQL/MariaDB matrix where practical.
- [ ] **TST-007** Supported PostgreSQL matrix where practical.
- [ ] **TST-008** PHP matrix derived from target OJS requirements.
- [ ] **TST-009** Multi-journal tests.
- [ ] **TST-010** Multi-role tests.
- [ ] **TST-011** Blind-review/privacy tests.
- [ ] **TST-012** Verification abuse tests.
- [ ] **TST-013** API contract tests.
- [ ] **TST-014** Upgrade from v1.0.0.2 test.
- [ ] **TST-015** Package install/enable/disable/uninstall smoke test.
- [ ] **TST-016** Chatwoot Captain unavailable/disabled graceful-degradation test.

## SEC — Security Hardening

- [ ] **SEC-001** Threat-model review before Phase 2 merge.
- [ ] **SEC-002** Forged Chatwoot header test.
- [x] **SEC-003** Cross-journal IDOR test. — `tests/v2/submission-verify.php` (submission in another journal resolves no relationship; earlier chatwoot-binding tests cover the conversation-binding side).
- [ ] **SEC-004** Cross-conversation session replay test.
- [ ] **SEC-005** OTP brute-force/rate-limit test.
- [ ] **SEC-006** Prompt-injection/tool-abuse test.
- [ ] **SEC-007** Secrets absent from browser/logs/export.
- [ ] **SEC-008** Public knowledge contains no protected data.
- [ ] **SEC-009** Dependency/security scan.
- [ ] **SEC-010** Security review before stable release.

## RELS — PKP Plugin Gallery Release

- [ ] **RELS-001** Repository is public before Gallery submission.
- [ ] **RELS-002** Public release package available for download.
- [ ] **RELS-003** Root GPL-compatible `LICENSE`.
- [ ] **RELS-004** Tests/CI green.
- [ ] **RELS-005** Build `.tar.gz` with a single `chatwootIntegration` directory.
- [ ] **RELS-006** Remove nonessential dependency-manager demos/examples/dev files.
- [ ] **RELS-007** Four-part release version, e.g. `2.0.0.0`.
- [ ] **RELS-008** Exact OJS compatibility versions tested.
- [ ] **RELS-009** Create immutable GitHub release/tag/package.
- [ ] **RELS-010** Calculate/record MD5 for Gallery XML.
- [ ] **RELS-011** Prepare title/summary/description/homepage/maintainer metadata.
- [ ] **RELS-012** Prepare Gallery XML release snippet.
- [ ] **RELS-013** Validate XML/package URL/MD5.
- [ ] **RELS-014** Open PR to `pkp/plugin-gallery`.
- [ ] **RELS-015** Address PKP automated checks/code review.
- [ ] **RELS-016** Never modify an already-published Gallery release artifact.
- [ ] **RELS-017** Document compatibility-update process for future OJS releases.

## DOC — Documentation

- [ ] **DOC-001** User install/config guide.
- [ ] **DOC-002** Core Chatwoot Bridge guide.
- [ ] **DOC-003** Captain Intelligence prerequisites/provisioning guide.
- [ ] **DOC-004** Verification/security admin guide.
- [ ] **DOC-005** Knowledge provider guide.
- [ ] **DOC-006** MCP setup guide.
- [ ] **DOC-007** REST/OpenAPI guide.
- [ ] **DOC-008** Troubleshooting/health guide.
- [ ] **DOC-009** Upgrade from v1 guide.
- [ ] **DOC-010** Privacy/data-retention guide.
- [ ] **DOC-011** Gallery release runbook.

## Deferred / explicit post-baseline ideas

These remain in the product vision but are not silently included in the initial v2 release:

- [ ] staff write actions such as review reminders/deadline changes;
- [ ] secure author file download links;
- [ ] duplicate-account staff resolution workflow;
- [ ] Crossref/DataCite-specific deep diagnostics;
- [ ] payment-provider-specific remediation actions;
- [ ] automatic support analytics/FAQ approval workflow;
- [ ] proactive WhatsApp/Telegram messaging beyond Chatwoot’s configured channel capabilities;
- [ ] any native Chatwoot MCP integration if upstream later implements one.