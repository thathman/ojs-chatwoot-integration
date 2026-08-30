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

### 7.2 `ojs_confirm_verification`

Inputs: challenge reference + user-provided code (or equivalent flow token).

Output: verification success/failure state; never returns stored secret.

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

### 7.5 `ojs_get_submission_support`

V3 resource relationship required.

**As actually implemented:** the V3 establishment step itself now exists as `POST /ojsSupportGateway/submissionVerify` (input: the conversation tuple + `submissionId`; see docs/v2/ADRS.md for why this stays deliberately narrow). It returns only relationship/capability state — `verified`, `resourceVerified`, `assurance`, `resource {type,id}`, `relationships[]`, `availableActions[]` — never submission content. `ojs_get_submission_support` below is a distinct, not-yet-built endpoint that would consume an already-V3-verified request to return the actual support DTO described here.

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

### 7.7 `ojs_get_payment_status`

Returns public fee facts plus verified submission-specific state when authorized:

- fee enabled;
- configured amount/currency if public;
- status `not_applicable|unpaid|paid|waived|unknown`;
- safe next action/payment URL where available and policy permits.

No provider secret, card/payment credential or unrelated transaction details.

### 7.8 `ojs_get_publication_status`

Returns safe publication, issue, DOI and public URL information for an authorized submission/article.

### 7.9 `ojs_diagnose_account`

Privacy-preserving account/login support diagnostic. Never becomes an arbitrary account lookup endpoint.

### 7.10 `ojs_diagnose_submission`

Runs deterministic submission-flow/support diagnostics for an authorized identity/resource or public pre-submission context.

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