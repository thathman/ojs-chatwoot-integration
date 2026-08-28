# v2 Architecture

## 1. Architecture objective

Build one **Support Core** inside the OJS plugin and expose it through multiple adapters. Authorization, privacy filtering and business rules must live in the Support Core, never separately in Captain prompts, MCP instructions or browser JavaScript.

```text
OJS
├─ Context / Journal data
├─ Users / Roles
├─ Submissions / Reviews
├─ Publications / DOI
├─ Payments
├─ Mailables
├─ Hooks / Events
└─ Plugin Providers
        │
        ▼
┌────────────────────────────────────────────────────────┐
│                  OJS SUPPORT GATEWAY                   │
│                                                        │
│ Context Engine                                         │
│ Identity + Verification                                │
│ Relationship Resolver                                  │
│ Capability / Policy Engine                             │
│ Journal Knowledge Compiler                            │
│ Support State Engine                                   │
│ Diagnostics Engine                                     │
│ Event Bridge                                           │
│ Audit Service                                          │
│ Provider Registry                                      │
└───────────────┬───────────────────┬────────────────────┘
                │                   │
          REST Adapter          MCP Adapter
                │                   │
          Chatwoot Captain       OpenClaw / agents
                │
          Chatwoot / humans
```

## 2. Architectural invariants

1. OJS is authoritative for OJS identity, permissions and workflow data.
2. Chatwoot is authoritative for Chatwoot conversations/contact IDs, not OJS authorization.
3. The LLM is never the security boundary.
4. All protected reads and writes pass through the same Policy Engine.
5. Forbidden fields are removed before serialization to an external consumer.
6. Public/semi-static knowledge and private/live data use different delivery paths.
7. Public support and staff automation use separate credentials and capability namespaces.
8. Every state-changing operation is idempotent where practical and auditable.
9. Every data cache/session is journal-context-scoped.
10. No core OJS files are modified by the plugin.

## 3. Major components

### 3.1 Context Engine

Resolves the current OJS support context:

- journal/context ID and path;
- authenticated OJS user if present;
- roles in the current journal;
- requested page/operation;
- current resource where safely resolvable (article, submission, review, payment area);
- locale;
- contextual support intent.

Browser context improves UX but is treated as a hint. Protected resource identity is reloaded and re-authorized on the server.

### 3.2 Identity & Verification Service

Responsibilities:

- establish authenticated-OJS support identity;
- create and verify email PIN challenges;
- create and consume secure verification links;
- create/revoke/expire support sessions;
- bind external Chatwoot conversation/contact context to a verified identity;
- expose assurance/verification metadata to Policy Engine;
- rate limit verification operations.

No identity claim supplied by an LLM is accepted as authority.

### 3.3 Relationship Resolver

Given an identity and resource, resolve a normalized relationship:

- author / submitter / corresponding author where determinable;
- reviewer for a specific review assignment;
- editor/sub-editor/assistant/manager/site admin;
- reader/subscriber where relevant;
- no relationship.

Relationship resolution uses OJS repositories/assignments/policies and must be version-adapted rather than inferred from Chatwoot attributes.

### 3.4 Capability / Policy Engine

Computes allowed capabilities from:

`consumer plane + verification assurance + identity + journal role + resource relationship + resource state + journal policy + feature flags`.

Examples:

- `journal.read_public_info`
- `account.read_own_support_state`
- `submission.read_own_support_status`
- `submission.read_own_required_actions`
- `submission.read_own_publication_status`
- `submission.read_own_payment_status`
- `submission.read_author_visible_files`
- `review.read_own_assignment`
- `support.escalate`
- staff-only `payment.mark_paid`, `review.send_reminder`, etc.

The public plane starts deny-by-default.

### 3.5 Response Policy / Field Filter

Serializers are purpose-built for support use. They do not expose raw OJS objects.

Example author support response:

```json
{
  "submissionId": 142,
  "supportState": "awaiting_reviewer_reports",
  "authorActionRequired": false,
  "nextExpectedAction": "reviewer_reports",
  "publicationStatus": "not_published"
}
```

No reviewer identity is loaded into the response object.

### 3.6 Support State Engine

Version-aware adapters map OJS workflow values into stable support states. Each mapping includes:

- normalized state code;
- safe explanation key;
- user action required boolean;
- optional due date;
- evidence/reason codes for staff diagnostics;
- confidence (`deterministic`, `partial`, `unknown`).

### 3.7 Journal Knowledge Compiler

Aggregates `KnowledgeProvider` output into public/semi-static support documents.

Core providers:

- Context/Journal Provider
- Submission Guidelines Provider
- Review Policy Provider
- Sections Provider
- Publication/Open Access Provider
- Payment/APC Provider
- DOI Provider
- Contact Provider
- Navigation/Official Pages Provider
- Email Communication Provider (approved use only)

Third-party plugins can register providers through v2 extension hooks.

Outputs:

- structured internal knowledge model;
- generated HTML support knowledge pages;
- optional sitemap/feed;
- content fingerprints/version;
- provenance per fact.

### 3.8 Diagnostics Engine

A diagnostic is a deterministic/rule-based service with evidence, not free-form LLM reasoning.

Contract:

```json
{
  "status": "problem_found|no_problem_found|unknown|needs_human",
  "code": "EMAIL_NOT_VALIDATED",
  "publicExplanation": "...",
  "recommendedAction": "resend_validation",
  "staffEvidence": {},
  "confidence": "deterministic"
}
```

Rules may compose multiple providers and OJS configuration checks.

### 3.9 Event Bridge

Normalize OJS hooks into internal support events, then let destinations decide how to consume them.

```text
OJS Hook -> SupportEvent -> policy/filter -> queued delivery -> Chatwoot
```

Delivery modes:

- update context/attributes;
- private note;
- open/update conversation;
- opt-in customer message;
- audit only.

Events use stable idempotency keys to avoid duplicate delivery.

### 3.10 Provider Registry

Providers implement narrow contracts instead of the core plugin hard-coding every integration.

Conceptual interfaces:

```php
interface KnowledgeProviderInterface
interface CapabilityProviderInterface
interface DiagnosticProviderInterface
interface EventProviderInterface
```

Providers declare:

- provider ID/version;
- journal applicability;
- public knowledge keys;
- protected capabilities;
- required upstream feature/plugin;
- health state.

The registry itself is part of v2; compatibility with third-party plugins is not automatic.

### 3.11 Chatwoot Connector

Responsibilities:

- widget boot/configuration;
- secure `setUser` HMAC integration;
- custom attributes for agent UX;
- Chatwoot API client;
- contact/conversation resolution;
- private notes/conversation creation;
- optional provisioning of Captain documents/custom tools/scenarios;
- queued retries and idempotency;
- Chatwoot feature/edition capability detection where possible.

The Chatwoot API token is server-side only.

### 3.12 REST Adapter

Purpose-built for Captain Custom Tools and external service integration.

Requirements:

- HTTPS only in production;
- bearer/API-key style service authentication;
- rate limits;
- correlation/request IDs;
- Chatwoot metadata headers accepted only after service authentication;
- no browser CORS exposure unless a specific endpoint requires it;
- JSON schema documented and versioned;
- GET/POST-compatible Captain endpoints.

### 3.13 MCP Adapter

Exposes selected Support Core functions as MCP tools/resources for authorized MCP clients.

Rules:

- same Policy Engine and serializers as REST;
- separate credentials/client registration;
- no assumption that Chatwoot itself is the MCP client;
- richer tool granularity is allowed because Captain’s 15-tool limit does not apply;
- staff write tools are segregated from public/read tools.

## 4. Adaptive verification flows

### 4.1 Logged-in OJS user

```text
OJS page request
 -> server resolves authenticated user + journal
 -> plugin establishes short-lived support identity/session binding
 -> widget uses HMAC identity
 -> Captain tool request reaches gateway with authenticated Chatwoot service call
 -> gateway resolves server-side support binding
 -> relationship/capability rechecked
 -> safe response
```

Implementation rule: the final binding mechanism must not rely on the LLM passing `ojs_user_id` correctly. A prototype task must choose the safest way to correlate the HMAC-verified Chatwoot contact/conversation to the short-lived OJS support session (for example server-side mapping plus Chatwoot API lookup of a signed/opaque binding).

### 4.2 External channel

```text
User asks protected question
 -> no valid support identity
 -> request verification
 -> generic response
 -> OJS sends PIN or secure link
 -> user confirms
 -> gateway validates challenge
 -> support session bound to Chatwoot conversation/contact
 -> protected tool retries
```

## 5. Suggested persistence model

Exact migrations are implementation work, but the domain requires at least the equivalent of:

### `support_gateway_verification_challenges`

- id/UUID
- context_id
- channel/conversation binding
- claimed identity reference/hash
- resolved user_id nullable until safely resolved
- purpose
- code hash or link-token hash
- attempt count / maximum
- created_at / expires_at / consumed_at / revoked_at

### `support_gateway_sessions`

- id/opaque token reference
- context_id
- user_id
- Chatwoot account/contact/conversation IDs where applicable
- verification method/level
- scopes/capability snapshot or policy references
- created_at / last_used_at / expires_at / revoked_at

### `support_gateway_audit_events`

- id
- correlation ID
- actor/consumer plane
- context/user/resource references
- capability/tool
- decision/reason
- result class
- created_at

### `support_gateway_knowledge_state`

- context_id/provider
- fingerprint
- generated/synced timestamp
- last error/health

Avoid storing copied private submission data when it can be loaded from OJS on demand.

## 6. Captain tool budget

Chatwoot currently caps Custom Tools at 15/account. v2 target is <=12 canonical tools, leaving room for administrators.

Recommended initial Captain surface:

1. `ojs_request_verification`
2. `ojs_confirm_verification`
3. `ojs_get_support_identity`
4. `ojs_list_my_submissions`
5. `ojs_get_submission_support`
6. `ojs_get_required_actions`
7. `ojs_get_payment_status`
8. `ojs_get_publication_status`
9. `ojs_diagnose_account`
10. `ojs_diagnose_submission`
11. `ojs_get_available_actions`
12. `ojs_escalate_support`

Public journal facts should normally come from knowledge documents rather than consuming a custom-tool slot.

## 7. Knowledge sync architecture

```text
OJS setting/page/provider changes
 -> provider fingerprint changes
 -> Knowledge Compiler rebuilds generated support pages
 -> mark knowledge state stale
 -> if Chatwoot provisioning/sync API available and configured: request Captain sync
 -> otherwise surface “sync required” in plugin health UI
```

Never push protected/private knowledge into a crawlable URL.

## 8. Compatibility architecture

Use adapter boundaries around OJS integration points likely to vary between 3.5 and 3.6:

- context/user access;
- submission collectors and relationship resolution;
- workflow/status mapping;
- review assignments;
- payment repository/DAO access;
- Mailable construction;
- backend UI hooks;
- event hooks.

The plugin declares compatibility only after tests pass against an exact OJS release.

## 9. Failure posture

- Widget failure must never break OJS page rendering.
- Chatwoot outage must not block OJS editorial workflow.
- Event delivery is asynchronous/retryable.
- Knowledge sync failure leaves OJS authoritative and reports degraded Chatwoot knowledge status.
- Verification store failure denies protected access.
- Policy ambiguity denies sensitive access or escalates.
- Diagnostic ambiguity returns `unknown`/`needs_human`.
- MCP/REST consumer failure cannot mutate OJS unless an authorized, explicit staff action was accepted.

## 10. Observability

Health view should eventually report:

- OJS compatibility adapter/version;
- Chatwoot SDK reachability;
- Chatwoot API credential validity;
- widget HMAC configuration;
- Captain feature availability/configuration where detectable;
- provisioned tool/document state;
- knowledge fingerprint/sync status;
- event queue depth/dead letters;
- verification service health;
- provider health;
- MCP enabled/disabled;
- recent safe audit/error counters.

No secrets should be rendered or logged.