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

---

## ADR-023 — MCP transport, SDK boundary, and scope (MCP-001)

**Status:** Accepted

**Protocol/transport:** target MCP revision `2026-07-28`, using stateless Streamable HTTP as the v2 remote transport — no deprecated HTTP+SSE transport, no STDIO in the OJS plugin runtime. OJS is an HTTP application; the MCP endpoint is a normal journal-scoped remote endpoint. This revision is intentionally stateless (no `initialize`/`initialized` handshake, no `Mcp-Session-Id`; each HTTP request is self-describing), which fits the existing stateless Support Gateway request model far better than introducing a second transport-session system alongside `SupportSession`.

**SDK boundary:** `mcp/sdk` (the official PHP SDK) is not a required plugin runtime dependency for v2 — it remains pre-1.0/experimental, and this plugin currently ships with no runtime Composer dependency tree of its own. The wire/protocol implementation is custom but strictly isolated under `classes/v2/Mcp/`, containing protocol adaptation only:

```
OJS MCP PageHandler / HTTP adapter
        |
small MCP protocol layer
        |
MCP tool/resource registry
        |
existing Support Core/domain services
```

This keeps the door open to swap in the official SDK later without touching identity, relationships, capabilities, serializers, diagnostics, payment providers, the Knowledge Compiler, handoff, or the Support State Engine. The official SDK, MCP Inspector, and/or official conformance fixtures are used as development/test oracles where practical — never a second, divergent business/security implementation.

**Scope:** initial server capability is `server/discover`, `tools/list`, `tools/call`, `resources/list`, `resources/read` only. No sampling, roots, legacy SSE, unnecessary prompts, Tasks, MCP Apps, or server-initiated capabilities unless a real product requirement later justifies them.

**Authentication:** MCP transport authentication and end-user authorization are separate concepts. A valid MCP client credential proves only that the calling application may talk to the MCP server — never that a specific user owns a specific resource. MCP reuses the same authoritative chain every other adapter already uses: MCP client auth → Support identity → Relationship → Capability → allowlist serializer → safe response. MCP never trusts a client-supplied email/OJS user id/role/relationship/Chatwoot attribute/capability name as authoritative, and never lets possession of an MCP credential create V2/V3 assurance by itself.

**Credential namespace:** a distinct MCP credential/config namespace — never a silent reuse of the Chatwoot API token, Captain API token, or Support API service token merely because all are bearer-shaped secrets. Public MCP and any future staff MCP plane use separate credentials/scopes. v2 builds only the public/read support MCP plane; per MCP-005, the staff MCP namespace stays reserved-but-unimplemented if no staff consumer plane exists yet in v2 — this is satisfied by making the public namespace structurally incapable of staff capabilities and testing that a public MCP credential cannot reach staff authority, never by inventing staff features to close a checkbox.

**Tools/resources:** expose already-agreed Support Core concepts (`journal.get_profile`, `identity.get_support_identity`, `submission.get_support_status`, `support.escalate`, etc. — reconciled against the real Support Core at implementation time), never a mechanical one-tool-per-PHP-method wrapper and never provider internals. MCP resources surface only Knowledge Compiler public facts under an `ojs://journal/...` hierarchy; submission/user-specific live state stays behind authenticated tools, never a public static resource.

**Sequencing:** MCP ships in slices (ADR/transport foundation → client auth/public consumer model → tool registry/first tools → full public tool set → Knowledge Compiler resources → REST equivalence + public/staff denial security tests → OpenClaw integration → docs/tasklist reconciliation), each independently mergeable, never one giant PR. After MCP: API-017 (OpenAPI schema) and API-018 (contract tests) reconcile the REST/MCP/Captain-Custom-Tools surfaces against the same domain/security model — shared schema definitions are extracted only when this work actually discovers real duplication, never as a speculative refactor. After that: the real OJS/DB integration runtime harness (TST-002/003), which is also where EVT-015's remaining real-database race proof completes. User-facing docs (DOC-001/002/...) follow once the externally visible MCP/OpenAPI/runtime surfaces have stabilized, not before.