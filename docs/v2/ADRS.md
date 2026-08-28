# v2 Architecture Decision Records

This file records inception decisions. Later ADRs may supersede them, but implementation must not silently contradict them.

## ADR-001 — Evolve v1; preserve plugin identity

**Status:** Accepted

Keep the OJS plugin product/install identifier `chatwootIntegration` and evolve the v1 repository rather than creating an unrelated new plugin.

**Why:** v1 already proves widget/context/event integration and may have installed users/settings. Preserving product identity improves upgradeability and PKP Gallery packaging continuity.

**Consequence:** breaking folder/product rename requires a future migration ADR.

---

## ADR-002 — OJS is the system of record

**Status:** Accepted

OJS is authoritative for journal configuration, OJS identity, roles, resource relationships, editorial workflow, publication and payment state.

Chatwoot attributes, Captain memory and historical conversations are never authoritative OJS state.

---

## ADR-003 — Build a Support Gateway, not a raw OJS proxy

**Status:** Accepted

Captain/MCP receive purpose-built support DTOs/capabilities rather than raw OJS API/database objects.

**Why:** reduces accidental data leakage, stabilizes support semantics across OJS versions and makes blind-review policy enforceable before model access.

---

## ADR-004 — Identity, Relationship and Capability are separate

**Status:** Accepted

A verified account does not imply access to a resource. Every protected resource request resolves relationship and capability independently.

---

## ADR-005 — Adaptive verification

**Status:** Accepted

- Logged-in OJS users use a server-side authenticated-session support binding without redundant PIN.
- External users can verify through OJS-delivered PIN or secure link.
- Verification creates a short-lived support session, not permanent trust.

**Open implementation detail:** exact secure correlation between fresh OJS session and Chatwoot conversation/contact. Prototype before Phase 2; no LLM-supplied user ID may become authority.

---

## ADR-006 — Field-level privacy, not prompt instructions

**Status:** Accepted

Blind-review/private fields are absent from public support serializers. We do not send sensitive data to Captain and then ask it not to reveal it.

---

## ADR-007 — Separate public and staff planes

**Status:** Accepted

Public Captain support and privileged staff automation have separate credentials, capabilities and endpoints/tool namespaces.

High-impact staff writes are off by default and require explicit policy/confirmation.

---

## ADR-008 — Public knowledge vs private live data

**Status:** Accepted

Static/semi-static public journal facts are compiled into generated support knowledge suitable for Captain Documents.

User/submission/review/payment private state is returned only through live authorized tools.

---

## ADR-009 — REST for Captain; MCP is our separate adapter

**Status:** Accepted

Captain integrates through its current HTTPS Custom Tools. The plugin exposes MCP independently for MCP-capable clients.

Native Chatwoot MCP is not a v2 dependency because it is not confirmed in current Chatwoot source.

---

## ADR-010 — One Support Core shared by REST and MCP

**Status:** Accepted

Relationship, policy, diagnostics, state mapping and serializers are transport-independent services. REST and MCP do not duplicate security logic.

---

## ADR-011 — Compact Captain tool set

**Status:** Accepted

Current Chatwoot source caps custom tools at 15/account. v2 targets at most 12 provisioned canonical tools and uses Captain knowledge/scenarios to avoid tool explosion.

MCP may expose more granular tools.

---

## ADR-012 — Explicit Knowledge Providers

**Status:** Accepted

The plugin does not claim to semantically understand every OJS page or third-party plugin automatically. Core and third-party integrations contribute knowledge/capabilities through providers/adapters.

Generated public support pages make the knowledge set explicit, crawlable and auditable.

---

## ADR-013 — Conversation learning requires human approval before becoming truth

**Status:** Accepted

Captain FAQ suggestions and support analytics may propose new knowledge, but cannot automatically overwrite OJS policy/authoritative facts.

---

## ADR-014 — Diagnostics must admit uncertainty

**Status:** Accepted

Diagnostics return evidence/confidence and may return `unknown` or `needs_human`. The LLM may explain a diagnostic result but may not invent a root cause absent evidence.

---

## ADR-015 — OJS plugin route/API without core modifications

**Status:** Accepted

Use supported plugin handlers/hooks/services to expose Support Gateway routes. Never require administrators to edit OJS core API files.

---

## ADR-016 — Keep remote failures non-blocking

**Status:** Accepted

Chatwoot/Captain/MCP outages must not prevent normal OJS page rendering or editorial operations. Events are queued/retryable; optional integrations degrade visibly but safely.

---

## ADR-017 — Payment reads public/private; writes staff-only

**Status:** Accepted

Configured APC amount/currency can be public journal knowledge. Submission payment state is protected. Administrative payment-state writes are never public Captain actions.

---

## ADR-018 — GPL v3-compatible release

**Status:** Accepted

The plugin release is explicitly GPL-compatible, with full GNU GPL v3 licence text in the repository/package before public release.

Chatwoot enterprise source is not vendored; Captain features remain optional and require an appropriately licensed/entitled Chatwoot deployment.

---

## ADR-019 — Exact OJS compatibility, not “3.5+”

**Status:** Accepted

Each release lists only exact OJS versions/ranges backed by CI tests. OJS 3.5 is the initial preservation target; 3.6 is separately verified.

---

## ADR-020 — Immutable Plugin Gallery artifacts

**Status:** Accepted

Once a release package/checksum is published to the PKP Plugin Gallery, it is never replaced or retargeted. Fixes receive a new four-part version and immutable archive.

---

## ADR-021 — Secrets are not ordinary export data

**Status:** Accepted

v2 will not export Chatwoot API/HMAC/support-gateway secrets in normal settings export. Secret backup/migration, if later implemented, requires a separately protected mechanism.

This intentionally changes unsafe v1 behavior.

---

## ADR-022 — Third-party adapters are capability claims, not assumptions

**Status:** Accepted

Crossref, DataCite, ORCID, Paystack/other payment plugins and future integrations are verified and versioned individually before their features are advertised. The Provider Registry is the extension mechanism.