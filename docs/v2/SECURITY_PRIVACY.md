# v2 Security & Privacy Specification

## 1. Security objective

The plugin handles identities, unpublished manuscripts, peer-review information, payment state and editorial workflow. Security is a product feature, not a deployment note.

The governing rule is:

> **Never give an AI data merely because the AI promises not to reveal it. Do not return forbidden data at all.**

## 2. Trust boundaries

### Trusted for authority

- OJS authenticated session and server-side authorization state.
- OJS repositories/services/policies within supported adapters.
- Support Gateway verification/session store.
- Support Gateway Policy Engine.
- Authenticated Chatwoot server-to-server API calls after validating configured credentials.

### Context only; never sufficient authority

- Chatwoot custom attributes.
- LLM-extracted parameters.
- browser URL/page context.
- contact email or phone from an unauthenticated channel.
- Chatwoot metadata headers without authenticating the custom-tool request.
- Captain memory or historical conversation content.

## 3. Threat model

Must explicitly defend against:

- author guessing another submission ID;
- reviewer identity leakage in blind/double-anonymous workflows;
- account enumeration through verification endpoints;
- PIN brute force/replay/resend abuse;
- stale Chatwoot identity remaining trusted after OJS logout/session expiry;
- forged requests to Support API with copied Chatwoot headers;
- prompt injection attempting to obtain internal fields or invoke staff actions;
- malicious/accidental use of a broad OJS API token;
- cross-journal data leakage in multi-context installs;
- cross-conversation verification-session reuse;
- insecure direct object references for files/submissions/users;
- webhook/event replay and duplicate delivery;
- sensitive log/exception leakage;
- SSRF or unsafe URLs in external integrations;
- leakage of secrets through browser JavaScript or debug mode;
- knowledge compiler accidentally publishing private content;
- privilege escalation from public support plane to staff plane;
- malicious third-party provider returning data outside its declared policy classification.

## 4. Authentication architecture

### 4.1 Chatwoot service authentication

Every Captain Custom Tool call to protected/support endpoints must use configured server-to-server authentication (prefer bearer or API key over HTTPS).

`X-Chatwoot-*` metadata headers are useful context only after service authentication succeeds. An internet client can otherwise forge such headers.

### 4.2 Authenticated OJS identity

A live OJS session can establish V2 identity, but v2 must create a server-side short-lived support binding. The browser/LLM does not declare which OJS user is authoritative.

The handshake implementation must satisfy:

- identity originates from `$request->getUser()` server-side;
- journal originates from server-side context resolution;
- binding is short-lived;
- binding cannot be reused for a different Chatwoot conversation/contact without an explicit policy;
- the gateway can revoke it;
- a stale Chatwoot contact attribute is insufficient to recreate it.

### 4.3 External verification

For non-OJS sessions, the gateway may challenge a claimed OJS account through its registered email using OJS mail infrastructure.

Response to verification request is always generic:

> “If the information matches an account, a verification message has been sent.”

Never reveal whether arbitrary addresses are registered.

## 5. Verification challenge controls

Defaults (configurable only within safe ranges):

- numeric code length: 6 digits minimum;
- expiry: 10 minutes;
- maximum verification attempts: 5;
- resend cooldown: 60 seconds;
- maximum sends per identity/channel/IP time window;
- only one active challenge per conversation/purpose/identity;
- new challenge revokes previous challenge;
- successful challenge is immediately consumed;
- challenge secret stored as keyed cryptographic hash only;
- constant-time comparison where applicable;
- all attempts audited with reason code but without plaintext code.

Rate-limit dimensions should include context, source IP where available, Chatwoot contact/conversation, and claimed identity hash.

## 6. Secure verification links

A secure link token:

- is random/unguessable;
- stored hashed server-side;
- is single-use and short-lived;
- is journal/challenge-bound;
- contains no sensitive plaintext if copied;
- shows a safe success/failure page;
- never redirects to an attacker-controlled URL;
- does not mark a different Chatwoot conversation verified merely because a logged-in browser clicked it unless the intended binding is explicitly verified.

## 7. Support sessions

Support sessions are opaque server-side state, not self-authorizing browser claims.

Recommended controls:

- short default lifetime (for example 30–60 minutes depending on assurance);
- idle and absolute expiry;
- conversation/context binding;
- revocation on suspicious verification activity;
- policy recalculation for sensitive requests rather than assuming cached roles forever;
- no session token logged;
- rotate/reissue on assurance escalation.

## 8. Authorization model

Authorization is capability based.

Pseudo-flow:

```text
authenticate consumer
 -> resolve support session
 -> resolve OJS identity
 -> load requested resource
 -> confirm same journal context
 -> resolve relationship
 -> evaluate capability policy
 -> serialize only allowed fields
 -> audit decision
```

A missing/ambiguous step means deny or escalate.

## 9. Blind-review safety

### Public author-facing API must never return

- reviewer name;
- reviewer email;
- reviewer account ID;
- ORCID/affiliation that identifies reviewer;
- reviewer-only files unless explicitly released to author by OJS workflow;
- private/internal review notes;
- staff discussion not visible to author;
- hidden recommendation/decision metadata before OJS exposes it.

### Reviewer-facing API must never return

- identities hidden by journal review policy;
- other reviewers’ confidential identities or material unless OJS explicitly exposes it to that reviewer;
- author identity where the active review policy hides it.

Implement field allowlists per support serializer. Do not build a full object and “remove a few sensitive fields” afterwards.

## 10. Multi-role users

A user may be author, reviewer and editor in one journal. Never apply a global “reviewer mask” based only on role membership.

Policy input must include:

- current resource;
- requested capability;
- relationship to that resource;
- consumer plane;
- current role assignments.

## 11. Public plane vs staff plane

### Public plane

- read-mostly;
- no broad administrative token;
- no payment-status mutation;
- no editor/reviewer assignment;
- no decision creation;
- no confidential discussion access;
- escalation instead of privileged write.

### Staff plane

- separate credential and endpoint/tool namespace;
- explicit staff identity/role validation;
- least privilege scopes;
- destructive/editorial writes off by default;
- human confirmation for high-impact actions;
- idempotency key required for mutating external calls;
- complete audit trail.

## 12. Prompt-injection resistance

Treat all user/manuscript/page text as untrusted content.

- Tool permissions are determined by Policy Engine, not prompt wording.
- Tool arguments cannot expand scopes.
- Unknown fields are rejected.
- Public serializers omit internal data even when requested.
- Generated journal knowledge pages do not embed executable instructions for the agent.
- Third-party provider output is escaped/sanitized before HTML knowledge rendering.
- The model cannot choose a staff credential.

## 13. File access

A future secure-file endpoint must:

- resolve file from an authorized OJS resource server-side;
- check resource relationship and file stage/visibility;
- issue a short-lived/single-use signed or opaque download token;
- never accept arbitrary filesystem paths;
- never expose storage paths;
- set safe content/disposition headers;
- audit file access;
- prevent a token issued for one file/resource/context from accessing another.

## 14. Payments

- Public Journal Brain may expose configured fee/currency if journal policy treats them as public.
- Submission-specific payment state requires verified relationship/capability.
- Full transaction/payment-provider secrets are never returned to Captain.
- “mark paid”, waive or reverse are staff-plane actions only.
- Payment actions must use OJS payment abstractions/authorized codepaths, not direct DB edits.

## 15. Knowledge publication rules

Every Knowledge Provider must classify each fact/page as:

- `public` — safe to publish/crawl;
- `staff` — never placed in Captain public documents;
- `protected` — available only through live authorized tools;
- `secret` — never exposed to support consumers.

Knowledge compilation fails closed if classification is missing.

No draft manuscript metadata, private user data, reviewer information, API tokens, private email templates, internal notes or private plugin settings may appear in public support knowledge.

## 16. Secrets management

Secrets include:

- Chatwoot API token;
- Chatwoot widget HMAC secret;
- Support API/MCP client credentials;
- signing/HMAC keys;
- provider API credentials.

Requirements:

- stored server-side using OJS/plugin settings mechanisms appropriate for secrets;
- never included in settings export by default once v2 secret handling is implemented;
- masked in UI;
- never rendered into browser/debug logs;
- rotation supported;
- least-privilege Chatwoot token where platform permits;
- production requires HTTPS.

v1’s settings-export behavior must be reviewed because blindly exporting API/HMAC secrets is not acceptable as a v2 default.

## 17. Logging and audit

### Ordinary logs

May contain:

- correlation ID;
- component/error code;
- provider ID;
- context ID where useful;
- retry count.

Must not contain:

- plaintext OTP;
- support session/token;
- Chatwoot/OJS API secrets;
- full user email unless explicitly needed and protected;
- manuscript/review text;
- confidential peer-review material.

### Security audit

Audit allow/deny for protected tools, verification lifecycle, staff mutations, secure downloads and configuration changes.

Retention period must be configurable/documented. Audit data itself is access-controlled.

## 18. Data retention/minimization

- load live OJS state on demand rather than copy it into gateway tables;
- verification challenges are purged after a short retention window;
- expired sessions are purged;
- audit retains IDs/reason codes, not response payloads by default;
- knowledge state stores fingerprint/provenance, not private duplicate data;
- provide uninstall/data-cleanup strategy consistent with PKP plugin expectations.

## 19. Security testing gates

Stable release requires tests for at least:

- horizontal submission IDOR attempt;
- cross-journal resource attempt;
- cross-conversation session reuse;
- forged Chatwoot metadata headers;
- invalid/expired/replayed PIN;
- resend throttling and guess lockout;
- account enumeration equivalence;
- multi-role author/reviewer behavior;
- reviewer identity redaction;
- staff capability denied on public credential;
- knowledge compiler private-data exclusion;
- secure file token misuse when feature exists;
- secrets absent from logs/settings exports;
- webhook/event replay/idempotency;
- disabled/revoked support session.

## 20. Vulnerability response

Security vulnerabilities must be reported privately to repository maintainers using GitHub’s private security-advisory mechanism when enabled, not disclosed first in a public issue. A root `SECURITY.md` should document the current reporting route before public release.