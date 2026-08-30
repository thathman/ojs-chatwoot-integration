# v2 Support API & MCP Specification

## 1. Principle

REST and MCP are adapters over the same Support Core. They must not implement separate authorization, relationship logic or privacy filters.

The API is intentionally **support-safe**, not a transparent proxy for OJS REST or database objects.

## 2. API versioning

Initial namespace: `/support-gateway/v1/...` (exact OJS PageHandler route may vary by compatibility adapter).

Breaking response/semantic changes require a new API major version. OJS plugin version and Support API version are related but not identical.

**As actually implemented:** the OJS 3.5 route is `/ojsSupportGateway/<status|identity|actions>` (same `SupportGatewayPageHandler` that already serves the browser-facing `/bind` route, registered via `LoadHandler`). `apiVersion` in the response envelope (see §5) is the versioning mechanism described above; the URL path itself does not currently carry a version segment. `/bind` is a distinct, same-origin, CSRF-protected, PKP-`JSONMessage` route for the browser handshake — it is not part of this Captain-facing service API and does not use the envelope in §5.

## 3. Consumer classes

- `chatwoot_captain_public`
- `chatwoot_human_support`
- `mcp_public_support`
- `mcp_staff`
- future trusted service clients

Every credential maps to one consumer class and allowed capability envelope.

## 4. Common request requirements

Protected requests require:

- HTTPS in production;
- service authentication;
- request/correlation ID;
- journal context resolution;
- Chatwoot metadata where relevant;
- support session when user-private information is requested;
- strict input validation and unknown-field rejection for security-sensitive operations.

## 5. Common response envelope

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "requestId": "...",
    "apiVersion": "1",
    "verification": "v3",
    "expiresAt": "..."
  }
}
```

Error:

```json
{
  "ok": false,
  "error": {
    "code": "VERIFICATION_REQUIRED",
    "message": "Verification is required before accessing this information.",
    "retryable": true
  },
  "meta": { "requestId": "..." }
}
```

Do not expose stack traces, SQL, filesystem paths or internal exception text.

## 6. Error taxonomy

- `AUTHENTICATION_REQUIRED`
- `AUTHENTICATION_FAILED`
- `VERIFICATION_REQUIRED`
- `VERIFICATION_PENDING`
- `VERIFICATION_FAILED`
- `VERIFICATION_EXPIRED`
- `RATE_LIMITED`
- `RESOURCE_NOT_FOUND`
- `RELATIONSHIP_NOT_FOUND`
- `CAPABILITY_DENIED`
- `JOURNAL_CONTEXT_MISMATCH`
- `FEATURE_UNAVAILABLE`
- `PROVIDER_UNAVAILABLE`
- `DIAGNOSTIC_UNKNOWN`
- `CONFLICT`
- `VALIDATION_ERROR`
- `INTERNAL_ERROR`

For sensitive resources, prefer indistinguishable `RESOURCE_NOT_FOUND`/safe denial semantics where revealing existence would leak data.

## 7. Captain tool surface

Current Chatwoot source caps Custom Tools at 15/account and supports GET/POST. Target no more than 12 plugin-provisioned tools.

### 7.1 `ojs_request_verification`

Purpose: request external verification for current support conversation.

Inputs:

- journal/context hint if not derivable;
- claimed email/identity information only as needed;
- purpose (`account_support`, `submission_support`, etc.).

Output is always anti-enumeration-safe.

**As actually implemented:** `POST /ojsSupportGateway/verificationRequest`. Inputs: `email` (a lookup key only, never identity), `purpose` (`account_support` | `submission_support`), optional `method` (`pin` | `link`, defaults to `pin`). PIN and secure link share one challenge engine — `VerificationChallengeService` — not two separate verification systems (see `docs/v2/ADRS.md` ADR-005).

Anti-enumeration is enforced structurally: the endpoint has exactly one success response call site (`{ok:true, data:{verificationRequested:true}}`), and every branch — email not found, account disabled (PKP's own `getByEmail(..., allowDisabled: false)` already makes these indistinguishable), rate-limited, cooldown, or a mail-send exception — falls through to it silently. Response *content* is proven identical by a source-level test; response *timing* is not equalized (no artificial delay on the "not found" path) — a known, documented gap, not a claim of full anti-timing-analysis.

Rate limiting (all silent, all collapsing into the same response): a per-challenge attempt lockout (default 5), a resend cooldown (default 60s) that also supersedes the prior unconsumed challenge for the same context+conversation+purpose, and rolling per-conversation and per-identity limits (default 5/hour each).

The verification email is sent via `SupportVerificationMailable` through PKP's own `Mail::send()` (the journal's real configured mail transport), never a manuscript title, submission ID, or other resource detail — verification proves the OJS account only.

### 7.2 `ojs_confirm_verification`

Inputs: challenge reference + user-provided code (or equivalent flow token).

Output: verification success/failure state; never returns stored secret.

**As actually implemented:** two paths through the same challenge engine:

- **PIN**: `POST /ojsSupportGateway/verificationConfirm` — inputs `challenge`, `pin`, `purpose`, plus the same conversation tuple every Support API call carries. Consumption is atomic (`DatabaseVerificationChallengeRepository::attemptConsume()`, row-locked in a single transaction; a simultaneous replay can only produce one success). A binding mismatch (wrong journal, wrong conversation, wrong purpose) is deliberately indistinguishable from a wrong PIN — both fail the same way and both count against the attempt lockout, never silently ignored.
- **Secure link**: `GET /ojsSupportGateway/verify?challenge=...&token=...` — a plain browser GET, not part of the service-authenticated pipeline (a browser can't supply a Bearer token). The URL carries only the opaque challenge reference and a high-entropy token — never a user ID, email, capability, role, submission ID, or Chatwoot API token. Binding comes entirely from the challenge's own server-side stored conversation tuple (set at request time) — the link works from any device/browser by design, since its security is the token's entropy plus single-use consumption, not "the right browser opened it."

On success, both paths establish a normal V2 `SupportSession` directly (`SupportSessionService::establishFromExternalVerification()`) — already bound to the conversation, no separate binding-token step, and any other session already bound to that exact conversation is revoked. Successful verification is always V2, never V3, even when `purpose` was submission-related — resource-scoped assurance is always a separate, later step (`submissionVerify`).

### 7.3 `ojs_get_support_identity`

Returns safe identity status for the current authenticated support session:

- verified/unverified;
- method/level;
- journal;
- safe role labels where permitted;
- expiry;
- no sensitive profile dump.

### 7.4 `ojs_list_my_submissions`

V2+ identity required. Returns only submissions for which the gateway verifies the appropriate relationship.

Fields should be sufficient for conversational selection:

- submission ID;
- safe title;
- normalized support state;
- last safe milestone/date;
- action-required boolean.

**As actually implemented:** `POST /ojsSupportGateway/submissions`, gated on the `submission.list_own` capability. Candidate discovery uses the OJS-native submission collector (`filterByContextIds()->assignedTo()`, the same call PKP core itself uses for its own "my assignments" endpoints — verified against `pkp-lib` stable-3_5_0), which is deliberately broad (any stage/review assignment, including editorial); every candidate is then independently re-checked through the same `SubmissionRelationshipResolver` `submissionVerify` uses, and only author/reviewer results survive — editorial-only relationships are excluded from this baseline. `actionRequired` is `null` (explicit unknown), not `false` — this slice has no reliable way to prove it from `status`/`stageId` alone. "Last safe milestone/date" is not yet implemented. Bounded pagination (`limit`/`offset`, default 20, max 50) is applied *after* relationship filtering, against a fixed internal candidate cap of 200 — see the `ponytail:` comment at the call site for the tradeoff and upgrade path.

`supportState` now additionally distinguishes `revision_requested` (review-stage submissions whose current review round has status `REVISIONS_REQUESTED`, read live from `ReviewRoundDAO`, verified against `pkp-lib` stable-3_5_0 — never cached or recomputed independently) from plain `review_in_progress`, and `draft` (a submission still in the submission wizard). Draft detection reads `submissionProgress`: OJS sets it to a non-empty wizard-step value at creation and clears it to `''` in `Repository::submit()` at the exact moment of genuine completion (verified against `pkp-lib` stable-3_5_0). Contrary to an earlier note here, a draft *is* reachable through the existing candidate discovery — OJS creates the author's `StageAssignment` immediately at submission creation (`PKPSubmissionController::add()`), before the wizard completes. Still not attempted: `revision_received`.

### 7.5 `ojs_get_submission_support`

V3 resource relationship required.

**As actually implemented:** the V3 establishment step itself exists as `POST /ojsSupportGateway/submissionVerify` (input: the conversation tuple + `submissionId`; see docs/v2/ADRS.md for why this stays deliberately narrow). It returns only relationship/capability state — `verified`, `resourceVerified`, `assurance`, `resource {type,id}`, `relationships[]`, `availableActions[]` — never submission content.

The actual support DTO now exists as a separate endpoint, `POST /ojsSupportGateway/submissionSupport`, gated on `submission.read_own_support_status` (V3 + author/reviewer relationship). It establishes its own request-time V3 the same way `submissionVerify` does — it does not consume or trust a prior `submissionVerify` call's result, since V3 is never persisted. Returns `title`, `relationships`, `supportState` (via `SupportStateMapper::map()`), `workflowExplanation` (one safe generic sentence per state, via `SupportStateMapper::explain()` — never mentions reviewer identities or internal discussions), and `availableActions`. Deliberately does not include required-action detail, publication detail, or milestone dates — those are `ojs_get_required_actions` (§7.6), `ojs_get_publication_status` (§7.8), and a not-yet-built milestone field respectively.

V3 is a request-time-only decision, computed fresh for each submission on each call — it is never persisted onto the conversation's support session, so verifying one submission never grants blanket access to another.

Returns:

- normalized support state;
- safe workflow explanation;
- author/reviewer action state as relevant;
- safe milestone dates;
- publication state;
- available safe capabilities.

Never returns reviewer identities/internal discussions.

### 7.6 `ojs_get_required_actions`

Returns a normalized list of actions the verified user is currently expected/allowed to take for the resource.

**As actually implemented:** `POST /ojsSupportGateway/requiredActions`, gated on `submission.read_own_required_actions` (V3 + author/reviewer relationship). Establishes its own request-time V3 the same way `submissionVerify`/`submissionSupport` do. `requiredActions` only reports an action when directly provable from evidence this codebase already reads:

- Author: `draft` → `complete_submission`; `revision_requested` → `submit_revisions`. Every other normalized support state (submitted, review_in_progress, copyediting_in_progress, production_in_progress, published, declined, scheduled_for_publication, unknown) has no provable author action yet — an empty result, not a guess.
- Reviewer: each `ReviewAssignment` this user holds for the submission (there may be more than one across review rounds) is read via PKP's own computed `getStatus()` (verified against `pkp-lib` stable-3_5_0 — never re-derives its overdue-date/decline/resend logic independently). `AWAITING_RESPONSE`/`RESPONSE_OVERDUE`/`REQUEST_RESEND` → `respond_to_review_invitation`; `ACCEPTED`/`REVIEW_OVERDUE` → `submit_review`; every other status (`RECEIVED`, `COMPLETE`, `THANKED`, `VIEWED`, `DECLINED`, `CANCELLED`) is settled, no action. If multiple assignments disagree, the most urgent outstanding action wins (respond > submit > none).

Does not include a title, normalized support state, or publication detail — those are `ojs_get_submission_support` (§7.5) and `ojs_get_publication_status` (§7.8) respectively.

### 7.7 `ojs_get_payment_status`

Returns public fee facts plus verified submission-specific state when authorized:

- fee enabled;
- configured amount/currency if public;
- status `not_applicable|unpaid|paid|waived|unknown`;
- safe next action/payment URL where available and policy permits.

No provider secret, card/payment credential or unrelated transaction details.

**As actually implemented:** `POST /ojsSupportGateway/paymentStatus`, gated on `submission.read_own_payment_status` (V3, author relationship only — reviewers are not part of this capability's declared relationships). Built directly against OJS's own `OJSPaymentManager`/`OJSCompletedPaymentDAO` (verified against `pkp-lib`/`ojs` `stable-3_5_0`, never re-derives their logic), not a generic Provider Registry — see `docs/v2/TASKLIST.md`'s PRV section note.

`feeEnabled`/`amount`/`currency` are always returned regardless of verification — they describe the journal's own payment configuration, not any specific user or submission, so revealing them cannot leak anything. The `payment_status` feature flag that gates the capability is derived live from `OJSPaymentManager::isConfigured() + publicationEnabled()`, never a plugin setting of its own.

The submission-specific `status` (`not_applicable`/`unpaid`/`paid`) additionally requires the `payment_support` journal policy in `CapabilityCatalog`, which defaults to `false` with no admin toggle built yet — so that branch is intentionally unreachable in production until a future settings UI exists to opt a journal in. This is a deliberate conservative default the endpoint is correctly wired to, not a bug. `waived` and a payment URL are not implemented for the *native* OJS producer: no genuine evidence of a fee-waiver concept was found in OJS core.

As of the Provider Registry phase (`docs/v2/AIRIX360_TASKLIST.md` AXP-*/APS-*), the response additionally carries an `obligations` array — one entry per registered payment provider that reports something for this submission, each `{producer, feeKey, status, amount, payableAmount, currency, payUrl}`. It is empty unless the Airix Submission Fee sibling plugin (`Airix360/submissionFee-OJS`, verified against `1.7.0.0`) is installed, enabled, and version-compatible. When it does report an obligation, that provider — not the native publication fee — becomes the authoritative `status`/`amount`/`currency` for the top-level fields (a journal configures one real fee producer at a time; see `AIRIX360_INTEGRATIONS.md` §5.8 on producer vs. collector). `status` there additionally covers `waived`/`partially_waived`/`refund_review`/`refunded`, sourced from the provider's own `PaymentHelper` (never re-derived waiver-percent math). Gateway/orchestrator adapters (Paystack/Flutterwave/Bachs/MultiPay) and the waiver-plugin's own public knowledge/actions are not built yet.

### 7.8 `ojs_get_publication_status`

Returns safe publication, issue, DOI and public URL information for an authorized submission/article.

**As actually implemented:** `POST /ojsSupportGateway/publicationStatus`, gated on `submission.read_own_publication_status` (V3 + author/reviewer relationship). Establishes its own request-time V3 the same way `submissionVerify`/`submissionSupport`/`requiredActions` do.

Deliberately conservative: `doi`, `publicUrl`, and `issue` are only ever populated when the submission's own normalized support state (via the shared `SupportStateMapper`) is exactly `published` or `scheduled_for_publication`. Every other state returns `status: 'not_yet_published'` with no other fields — this codebase has no evidence those identifiers exist yet for an unpublished item. `publicUrl` is further restricted to `published` only, since `scheduled_for_publication` means the article is not yet visible to the public (verified against `pkp-lib`/`ojs` `stable-3_5_0` — `Publication::getDoi()`, `Publication::getIssueId()`, `Issue::getVolume()/getNumber()/getYear()/getPublished()`, and the same `$request->getDispatcher()->url(..., 'article', 'view', [$submission->getBestId()])` call `ArticleHandler` itself uses to build the public article URL). Issue metadata is only surfaced when the linked `Issue` itself reports `getPublished() === true` — a fail-safe layer independent of the submission's own status, in case an article's status and its containing issue's publish state ever diverge.

### 7.9 `ojs_diagnose_account`

Privacy-preserving account/login support diagnostic. Never becomes an arbitrary account lookup endpoint.

**As actually implemented:** `POST /ojsSupportGateway/accountDiagnostics`, gated on `account.diagnose_own` (V2 — no resource relationship, since this diagnoses the caller's own account only). Input is a `scope` (`account_access`/`login`/`password_reset`/`profile`); there is no email/username/userId parameter, structurally enforced by a source-level test.

Uses the new shared diagnostic contract, `classes/v2/Diagnostics/DiagnosticResult.php` — `status` (`confirmed`/`likely`/`unknown`/`needs_human`), `code`, `summary`, `evidenceCodes[]`, `nextActions[]`, `retryable` — reused by every diagnostic scope, present and future, so `ojs_diagnose_submission` will speak the same shape rather than inventing its own.

`AccountDiagnosticEngine` (verified against `pkp-lib` stable-3_5_0 `User::getDisabled()`/`getDateValidated()`, never `getDisabledReason()` — free-form admin text, unsafe to surface):

- `account_access`: `confirmed` `ACCOUNT_DISABLED`/`ACCOUNT_ACTIVE` from `getDisabled()`; `unknown` if that field can't be read.
- `login`: always `confirmed` `LOGIN_OK` — reaching this diagnostic at all requires an authenticated V2 session, which is itself direct proof login currently works. Cannot explain a past failure; no such evidence exists.
- `password_reset`: always `unknown` — no OJS evidence about email delivery or reset-link validity is available.
- `profile`: `confirmed` `EMAIL_VALIDATED` only when `getDateValidated()` is present; a `null` value is deliberately left `unknown` rather than confirmed as a problem, since it's ambiguous (genuinely unvalidated vs. predates the field vs. admin-created account).

Deliberately conservative per the status hierarchy: most rules land on `confirmed` or `unknown`; none currently use `likely` or `needs_human` — those are reserved for scopes with genuinely circumstantial or judgment-requiring evidence, which none of the four current scopes have.

### 7.10 `ojs_diagnose_submission`

Runs deterministic submission-flow/support diagnostics for an authorized identity/resource or public pre-submission context.

**As actually implemented:** `POST /ojsSupportGateway/submissionDiagnostics`, gated on `submission.diagnose_own` (V3 + author/reviewer relationship). Establishes its own request-time V3 the same way the other submission-scoped endpoints do. Input is a `submissionId` plus a `scope`.

Deliberately does not create a second workflow interpreter — `SubmissionDiagnosticEngine` is a thin wrapper over the existing domain services this codebase already built for the dedicated endpoints:

- `submission_access`: `confirmed` `SUBMISSION_ACCESS_CONFIRMED` from the relationship the endpoint already established (author/reviewer); the endpoint never even reaches the engine without one, since an empty relationship falls back to the same generic unverified shape every other endpoint uses.
- `submission_progress`: wraps `SupportStateMapper`'s state, mapped to a targeted code rather than echoed verbatim — e.g. `revision_requested` → `confirmed` `REVISION_REQUIRED` with `submit_revisions` as a next action, matching the worked example in the original spec discussion exactly.
- `required_action`: wraps `RequiredActionMapper::forAuthor()`/`forReviewer()` — `confirmed` `ACTION_REQUIRED` (with the actions themselves as `nextActions`) or `confirmed` `NO_ACTION_REQUIRED`.
- `review_access`: `confirmed` `REVIEWER_ASSIGNMENT_FOUND` only for an actual reviewer relationship with real `ReviewAssignment` evidence; an author-only identity gets `unknown` `NOT_A_REVIEWER`, never fabricated reviewer status.
- `publication`: the same 3-way `published`/`scheduled_for_publication`/otherwise split `ojs_get_publication_status` uses.
- `payment`: independently re-evaluates `submission.read_own_payment_status` — the exact same capability check the dedicated payment endpoint performs, with the same live-derived `payment_status` feature flag — before revealing anything. If that capability is denied (the `payment_support` journal policy still defaults off in production), this scope returns `unknown` `PAYMENT_STATUS_UNAVAILABLE`, never a workaround. This is the one scope most likely to be probed for a capability bypass, so it is the one most explicitly tested to prove it cannot become one.

Every scope's `unknown`/`needs_human` fallback is deliberate: this codebase does not fabricate a diagnosis from ambiguous or missing evidence. None of the six current scopes use `likely` yet.

### 7.11 `ojs_get_available_actions`

Returns capability-derived action names rather than asking the model to guess what it may do.

Example:

```json
{
  "actions": [
    "view_status",
    "view_publication_status",
    "view_revision_deadline",
    "contact_editorial_office"
  ]
}
```

### 7.12 `ojs_escalate_support`

Creates/updates a structured human handoff context or private note. It does not grant additional data access.

**As actually implemented:** `POST /ojsSupportGateway/escalate`, gated on the existing `support.escalate` capability — deliberately V0/unauthenticated, the same as every other version of this capability, since a human handoff must remain available even when verification itself is failing (often exactly why one is needed). Input is a required `reason` (free text, capped at 1000 characters and stripped of control characters) plus an optional `submissionId` and `idempotencyKey`.

"Does not grant additional data access" is enforced structurally, not just by convention: every fact folded into the handoff summary is independently re-checked against the exact same capability its own dedicated endpoint enforces (`submission.read_own_support_status`, `submission.read_own_required_actions`, `submission.read_own_publication_status`, `submission.read_own_payment_status`) before being included. In the current default configuration, payment facts are never includable here either, for the same reason `ojs_get_payment_status`'s personalized status isn't — the `payment_support` journal policy still defaults off.

`HandoffSummaryFormatter` builds one summary used for both the JSON response and the rendered Chatwoot private-note text, so the two can never drift on what's safe to include. The note is posted via the existing v1 `ChatwootApiService::createConversationNote()` (reused, not rebuilt) to the exact conversation tuple the request itself carries — never a caller-supplied override. Posting is best-effort: a Chatwoot API failure (missing configuration, network error) never fails the whole request, since the meaningful outcome for Captain is that the escalation was recorded, not that a third-party API call happened to succeed.

Idempotency (`EscalationIdempotencyGuard`) is APCu-backed with the same fail-open, per-worker character as `RateLimiter` — good enough to absorb a naive client retry within one worker, not a durable cross-worker ledger. Not yet implemented: folding a diagnostic result into the summary (HOF-005) — see `docs/v2/TASKLIST.md`'s note on why that was deliberately deferred rather than accepting an untrustworthy caller-supplied diagnostic code.

## 8. Public knowledge endpoints

Generated support knowledge pages are separate from tool API.

Suggested routes:

- `/support-knowledge/`
- `/support-knowledge/about`
- `/support-knowledge/submissions`
- `/support-knowledge/review`
- `/support-knowledge/fees`
- `/support-knowledge/publication`
- `/support-knowledge/accounts`
- `/support-knowledge/policies`
- optional `/support-knowledge/sitemap.xml`

Only `public` facts/providers may render here.

## 9. Staff API namespace

Staff operations, if implemented, use a visibly separate namespace and credential class, e.g. `/support-gateway/v1/staff/...`.

Candidate future staff reads:

- search submission/user under existing staff authorization;
- get editorial state;
- get outstanding review counts;
- get task/diagnostic evidence.

Candidate future staff writes (post-v2 baseline, explicit approval):

- send approved reminder;
- extend deadline;
- create approved discussion;
- payment status administrative action through OJS-supported path.

No staff write is included merely because OJS technically exposes a write route.

## 10. MCP design

MCP exposes the same domain functions using tool/resource definitions appropriate to the protocol version selected during implementation.

Suggested MCP read tools may be more granular than Captain:

- `journal.get_profile`
- `journal.get_submission_policy`
- `journal.get_fee_policy`
- `identity.get_support_identity`
- `identity.request_verification`
- `identity.confirm_verification`
- `submission.list_mine`
- `submission.get_support_status`
- `submission.get_required_actions`
- `submission.get_timeline`
- `payment.get_submission_status`
- `publication.get_status`
- `diagnostics.account`
- `diagnostics.submission`
- `capabilities.list_available`
- `support.escalate`

MCP staff tools live in a separate server configuration/tool namespace or require explicit staff client identity/scopes.

## 11. MCP resources

Safe public resources may include:

- `ojs://journal/{contextId}/support-profile`
- `ojs://journal/{contextId}/submission-guidelines`
- `ojs://journal/{contextId}/fee-policy`

Private resources should generally prefer tools so authorization is evaluated at read time. Do not make private manuscript data a cacheable, unauthenticated resource URI.

## 12. Authentication and Chatwoot metadata

Current Chatwoot Custom Tools can send:

- account ID;
- assistant ID;
- tool slug;
- conversation ID/display ID;
- contact ID/email/phone;
- contact-inbox ID;
- HMAC-verified flag.

These are valuable correlation inputs. The gateway must first authenticate the configured Chatwoot tool credential. It may then use Chatwoot IDs to retrieve/validate server-side support binding state.

The LLM must not be asked to provide `ojs_user_id` or a capability scope as an authoritative parameter.

## 13. Idempotency

Mutating operations (including verification issuance where resend semantics matter, escalation notes and future staff writes) should accept/generate idempotency keys tied to the caller/conversation/action.

Event delivery also uses stable event IDs derived from OJS event/resource/version where practical.

## 14. Rate limiting

Different buckets for:

- verification request;
- verification confirm;
- read tools;
- diagnostics;
- escalation;
- staff writes;
- MCP client.

Return `RATE_LIMITED` with safe retry metadata.

## 15. Capability discovery

`get_available_actions` / MCP equivalent is the canonical way for an agent to learn allowed actions.

The response is computed server-side and can vary by:

- journal;
- resource;
- user relationship;
- verification assurance;
- current workflow state;
- consumer plane;
- installed provider capability.

## 16. Provider errors

A provider failure must not cause another provider’s private data to be exposed. Use per-provider health and safe errors.

Example:

```json
{
  "status": "unknown",
  "reason": "PAYMENT_PROVIDER_UNAVAILABLE"
}
```

Never infer “unpaid” merely because a payment provider timed out.

## 17. OpenAPI / schema requirement

The REST adapter must ship machine-readable API documentation or a generated schema suitable for testing/provisioning Captain tools. JSON response schemas should be contract-tested.

## 18. Backward compatibility

v1 Chatwoot API client behavior remains supported during migration, but v2 endpoints and classes should be separated from the monolithic v1 plugin class. Existing saved widget settings should migrate/continue where safe.

Any v1 setting that becomes unsafe (notably secret export behavior) requires a migration note and secure default rather than silent preservation.