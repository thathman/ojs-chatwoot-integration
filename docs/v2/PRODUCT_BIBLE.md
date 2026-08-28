# Chatwoot Integration for OJS v2 — Product Bible

Status: **v2 inception / implementation specification**  
Development branch: `v2-dev`  
Plugin product identifier: `chatwootIntegration`  
Architecture name: **OJS Support Gateway**

## 1. Product thesis

Chatwoot Integration for OJS v2 turns the v1 widget bridge into a secure, journal-aware support and automation layer.

OJS remains the system of record for journals, users, roles, submissions, reviews, publications, payments and workflow. Chatwoot remains the support/conversation system. Captain may reason over approved journal knowledge and call support-safe HTTP tools. The plugin becomes the trust broker that decides what an assistant or human support agent is allowed to know or do.

The product is not “an AI with raw OJS access”. It is a governed support machine with explicit identity, relationship, capability, policy and audit boundaries.

## 2. What v1 already proved

v1 established the foundation that v2 evolves rather than discards:

- Chatwoot widget injection in OJS.
- OJS authenticated-user detection.
- Chatwoot HMAC identity binding.
- Journal, role and page context.
- ORCID and affiliation context.
- Article, DOI and section context.
- Active-submission context.
- Reviewer privacy masking.
- Role-based widget visibility.
- OJS editorial/submission/publication event forwarding.
- Chatwoot conversation notes.
- OJS email-template to Chatwoot canned-response sync.
- Chatwoot API health checks and retry queue.
- Per-journal settings with global defaults.

v2 keeps these capabilities where they remain safe and compatible, but moves security decisions out of browser attributes and into a server-side policy layer.

## 3. The three brains

### 3.1 Journal Brain — “What is this journal?”

The Journal Brain compiles authoritative journal knowledge from OJS and approved provider integrations. Examples:

- journal identity, contacts and publishing information;
- author and reviewer guidelines;
- submission requirements and checklists;
- sections and supported languages;
- peer-review model and public policy;
- open-access, copyright and licence information;
- APC/publication-fee configuration and currency;
- DOI/publication information;
- official journal pages and approved custom knowledge;
- plugin-provided support facts.

This is primarily public or semi-static knowledge suitable for generated support pages and Captain Documents.

### 3.2 User Brain — “Who am I helping?”

The User Brain resolves identity without trusting the LLM:

- authenticated OJS session;
- Chatwoot website-widget HMAC identity;
- email PIN or secure verification link for external channels;
- OJS user ID and current journal context;
- journal roles;
- verified relationships to submissions/reviews;
- short-lived support-session scopes.

### 3.3 Operations Brain — “What is happening right now?”

The Operations Brain exposes live, permission-filtered state and diagnostics:

- manuscript/support status;
- required author actions;
- review progress without reviewer identity leakage;
- revision state and deadlines;
- publication/issue/DOI state;
- publication-fee status;
- account-access diagnosis;
- submission-flow diagnosis;
- upload/configuration diagnosis;
- escalation context for a human agent.

Private live state is never written into public Captain knowledge documents.

## 4. Identity → Relationship → Capability

All protected requests follow three independent checks.

1. **Identity** — who has been verified?
2. **Relationship** — what relationship does that identity have to the requested OJS resource?
3. **Capability** — what may that identity do with that resource right now?

Being logged into OJS proves control of an OJS account. It does not grant access to every submission ID a user can name.

Example:

- Identity: OJS user 781 — verified.
- Relationship: corresponding author of submission 142 — verified from OJS.
- Capabilities: read author-visible status, read publication status, read author-visible files; cannot read reviewer identities, internal editorial notes or change editorial decisions.

## 5. Verification model

### Verification levels

- **V0 Anonymous** — public journal information only.
- **V1 Channel/email verified** — proves control of a verified external channel/address.
- **V2 OJS identity verified** — authenticated OJS account or equivalent verified binding.
- **V3 Resource relationship verified** — identity has an authorized relationship to the requested submission/review/resource.
- **V4 Staff/elevated** — privileged staff plane with separately granted scopes and stronger controls.

Levels are descriptive; authorization is always capability/scoped, not a single boolean.

### Verification methods

1. **Authenticated OJS session** — preferred for support initiated inside OJS. The server recognizes the logged-in user and establishes a short-lived support identity. No PIN should be required merely to prove the account already authenticated by OJS.
2. **Email PIN** — fallback for email, WhatsApp, Telegram and anonymous web sessions. The code is delivered through OJS mail infrastructure.
3. **Secure verification link** — preferred external alternative where practical so a secret code does not remain in a support transcript.

### OTP rules

- cryptographically random;
- store only a one-way keyed hash, never the plaintext code;
- short expiry (default 10 minutes, configurable within a safe range);
- single-use and purpose-bound;
- bound to journal, support conversation/challenge and claimed identity;
- request and guess throttling;
- invalidate previous active challenge on resend;
- generic response whether an account exists or not;
- audit issuance, success, failure, expiry and revocation without logging the code.

## 6. Support sessions

Verification creates a short-lived server-side support session rather than permanent trust.

A session is bound to relevant context such as:

- OJS context/journal;
- verified OJS user, where applicable;
- Chatwoot account/contact/conversation identifiers;
- verification method and assurance level;
- scopes/capabilities;
- issued, expiry and revocation timestamps.

Chatwoot custom attributes are display/cache metadata only. They are never an authorization source.

## 7. Two security planes

### Public Support Plane

Used by Captain and support agents assisting readers, authors and reviewers. Read-mostly, tightly scoped, relationship-aware and blind-review-safe.

### Staff Automation Plane

Used by explicitly authorized staff agents or MCP clients. Separate credentials, scopes and audit trail. Destructive/editorial write actions require explicit policy and, by default, human confirmation.

Public Captain must never inherit a broad OJS manager/site-admin API token.

## 8. Journal Knowledge Compiler

The plugin will compile authoritative OJS information into a normalized support model and optionally generated public support pages.

Suggested generated routes:

- `/support-knowledge/`
- `/support-knowledge/about`
- `/support-knowledge/submissions`
- `/support-knowledge/review`
- `/support-knowledge/fees`
- `/support-knowledge/publication`
- `/support-knowledge/accounts`
- `/support-knowledge/policies`

The root page must link directly to all generated category pages so Chatwoot’s simple URL crawler can reliably discover them. A sitemap may also be offered.

Knowledge is fingerprinted so changes can trigger or request a Captain document sync.

### Truth hierarchy

When sources disagree, use this precedence:

1. live authorized OJS tool response;
2. structured OJS configuration/repositories;
3. official journal content/pages;
4. staff-approved support knowledge/FAQ;
5. historical support conversations;
6. AI memory or inferred context.

Lower levels may never override higher levels automatically.

## 9. Knowledge vs live tools

Public fact:

> “The publication fee is USD 250.”

May come from the Journal Brain/Captain knowledge.

Private question:

> “Did I pay for submission 142?”

Must call an authenticated tool. The gateway verifies identity, relationship and capability, then queries OJS payment state.

This split applies to submissions, reviews, user accounts, files and all other personal/editorial state.

## 10. Support-safe API

The plugin exposes a support API built on OJS services/repositories rather than exposing raw OJS internals to Captain.

Representative operations:

- request/confirm verification;
- resolve support identity;
- list the verified user’s submissions;
- get a submission support summary;
- get required user actions;
- get publication/DOI status;
- get publication-fee status;
- diagnose account access;
- diagnose submission flow;
- escalate with structured context.

Responses use normalized support vocabulary and omit forbidden fields at serialization time.

## 11. Support State Engine

The gateway converts implementation-specific workflow state into stable support states such as:

- awaiting_editor_assignment;
- awaiting_reviewer_assignment;
- reviewers_invited;
- awaiting_reviewer_reports;
- editor_assessing_reviews;
- revision_requested;
- revision_received;
- copyediting_in_progress;
- production_in_progress;
- scheduled_for_publication;
- published.

The exact mapping is version-tested. Captain receives the normalized support state and safe explanatory facts, not a raw database dump.

## 12. Blind-review policy

Blind-review protection is enforced before data reaches Chatwoot or an LLM.

The public plane never returns reviewer names, reviewer email, reviewer identity metadata, hidden editorial discussions, internal notes, confidential review material or other fields forbidden to the requesting relationship.

v1’s role-wide masking is replaced by resource/relationship-aware policy. A person can be an author and reviewer in the same journal; policy must depend on the specific resource being discussed.

## 13. Context-aware support

When support starts inside OJS, the plugin may safely attach non-authoritative context such as:

- current journal;
- whether the user is authenticated;
- current OJS area/page/operation;
- current article/submission/resource when resolvable;
- contextual launcher intent (login, submission, review, payment, manuscript, article).

Examples:

- login page: “Need help signing in?”
- submission wizard: “Need help submitting?”
- review workflow: “Need help reviewing?”
- payment page: “Questions about payment?”
- manuscript workflow: “Need help with this manuscript?”

Protected resource access is still re-authorized server-side.

## 14. Diagnostics Engine

Diagnostics are structured evaluations, not LLM guesses.

Planned domains:

- account/login/registration/reset path;
- submission access and workflow;
- missing/invalid submission requirements where OJS exposes enough state;
- upload/server-limit conditions;
- reviewer access;
- publication fee/payment;
- publication/DOI;
- email configuration/send path.

Diagnostics return a public-safe explanation plus optional staff-only technical detail. A diagnostic may return `unknown` or `needs_human` rather than fabricate a cause.

## 15. Event Bridge and proactive support

The v1 event bridge will be formalized around an event model. Candidate events include:

- account-related events where safe;
- submission created/submitted/stage changed;
- revision requested/uploaded;
- review invited/accepted/overdue/completed;
- editorial decision recorded;
- payment required/completed/failed/waived;
- production scheduled/published;
- DOI assigned/registered/failed.

Events may update support context, add a private note, open/update a conversation or trigger an opt-in proactive workflow. Not every event becomes a customer message.

## 16. Human handoff

When Captain hands off, agents should receive a safe structured briefing, for example:

- verification status and method;
- journal and OJS user ID;
- verified relationship to current submission;
- normalized support state;
- required user action;
- non-sensitive recent events;
- diagnostic result;
- user’s question.

This reduces duplicate identity and manuscript discovery work without leaking protected editorial data.

## 17. Provider / Capability Registry

The Support Gateway will expose extension points so OJS plugins can register support providers.

Provider classes may expose:

- public knowledge facts;
- protected read capabilities;
- diagnostic checks;
- staff-only actions;
- event mappings.

Core providers are expected for journal/context, users, submissions, reviews, publications, payments and DOI. Third-party payment, ORCID, DOI or other plugins require explicit adapters or provider registration; the gateway does not assume semantic knowledge of arbitrary plugins.

## 18. REST + MCP

The Support Core is transport-independent.

- **REST adapter**: first-class path for Chatwoot Captain Custom Tools today.
- **MCP adapter**: plugin-owned MCP server/interface for OpenClaw and other MCP-capable clients.

Native Captain MCP support is not an implementation assumption. If Chatwoot adds it later, the MCP adapter can become another integration path without redesigning authorization.

## 19. Captain integration constraints

Current Chatwoot source imposes constraints that shape v2:

- Captain Custom Tools are an optional/enterprise feature.
- A Chatwoot account currently permits at most 15 custom tools.
- Custom HTTP tools currently support GET and POST plus none/bearer/basic/API-key auth.
- tool execution includes conversation/contact metadata headers.
- Captain Documents can crawl URLs/PDFs and can be synced when the relevant feature is available.
- Captain Scenarios can select tool subsets.
- Captain has FAQ suggestion infrastructure with human approval state.

Therefore v2 will keep the Captain-facing tool surface compact (target <=12 canonical tools) and expose richer granularity over MCP where the 15-tool constraint does not apply.

## 20. Data minimization and audit

Every meaningful protected operation records an audit event containing only what is needed:

- correlation ID;
- timestamp;
- consumer/plane;
- Chatwoot conversation/contact IDs where applicable;
- journal/context;
- verified user/resource IDs where applicable;
- capability/tool;
- allow/deny decision and reason code;
- result class, not sensitive response body by default.

Secrets, OTP plaintext, full manuscript text and confidential reviews are never written to ordinary logs.

## 21. Non-goals for v2 public support

- no raw database access for Captain;
- no broad OJS admin API token for an LLM;
- no reviewer-identity disclosure;
- no automatic editorial decisions;
- no automatic reviewer assignment;
- no unattended payment waiver/“mark paid” actions for public users;
- no automatic account merge;
- no self-modifying authoritative journal policy based on conversations;
- no claim that every third-party OJS plugin is understood without an adapter;
- no dependency on native Chatwoot MCP support.

## 22. Product modes

### Core Chatwoot Bridge

Works with the website widget/API features available in the installed Chatwoot edition: context, HMAC identity, human support and event bridge.

### Captain Intelligence

Optional. Requires a Chatwoot edition/plan exposing Captain knowledge/custom tools/scenarios. Adds Journal Brain knowledge and live support-safe tools.

### MCP / Agent Gateway

Optional. Exposes the same Support Core to explicitly authorized MCP clients independently of Captain.

## 23. Success criteria

v2 succeeds when:

- a logged-in author can ask a manuscript question without repeating identity details;
- an external author can securely verify with OJS-delivered verification;
- Captain can answer public journal questions from OJS-derived authoritative knowledge;
- protected questions fail closed until identity/relationship/capability checks pass;
- blind-review data cannot reach the public-plane LLM;
- common support failures can be diagnosed with evidence rather than guessed;
- human agents receive useful, safe OJS context on handoff;
- the plugin can be packaged, tested and reviewed under PKP Plugin Gallery requirements;
- all supported OJS versions are explicitly tested and declared rather than implied by “3.5+”.

## 24. Release identity

Keep the existing plugin product identifier and installation directory compatible with v1 unless a migration ADR explicitly changes it:

- product/directory: `chatwootIntegration`
- development line: v2 on `v2-dev`
- version format: four-part PKP versions, e.g. `2.0.0.0`
- working public name: **Chatwoot Integration for OJS**
- subsystem name: **OJS Support Gateway**

## 25. Governing documents

This Product Bible is implemented through the companion v2 documents:

- `ARCHITECTURE.md`
- `VERIFICATION_MATRIX.md`
- `SECURITY_PRIVACY.md`
- `API_MCP_SPEC.md`
- `KNOWLEDGE_DIAGNOSTICS.md`
- `BUILD_PLAN.md`
- `TASKLIST.md`
- `TEST_PLAN.md`
- `RELEASE_GALLERY.md`
- `LICENSING.md`
- `ADRS.md`

When an implementation choice conflicts with this kit, update the relevant ADR/spec first and record why.