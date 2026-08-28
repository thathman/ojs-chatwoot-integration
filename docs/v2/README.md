# Chatwoot Integration for OJS — v2 Product Spec Kit

This directory is the implementation authority for v2 development on `v2-dev`.

## Product model

**Distribution/plugin:** Chatwoot Integration for OJS  
**OJS product identifier:** `chatwootIntegration`  
**Architecture:** OJS Support Gateway  
**Development branch:** `v2-dev`

v2 evolves the existing v1 widget/context/event bridge into a secure journal-aware support gateway with three complementary capabilities:

1. **Journal Brain** — authoritative public/semi-static journal knowledge compiled from OJS.
2. **User Brain** — verified OJS identity, resource relationships and short-lived support sessions.
3. **Operations Brain** — live support state, payment/publication information and evidence-based diagnostics.

## Documents

| Document | Purpose |
|---|---|
| `PRODUCT_BIBLE.md` | full product vision, principles, brainstorm consolidation, scope and non-goals |
| `VERIFICATION_MATRIX.md` | upstream verification of brainstorm claims against current OJS/Chatwoot source |
| `ARCHITECTURE.md` | component architecture, data flows, provider model and persistence concepts |
| `SECURITY_PRIVACY.md` | threat model, verification, blind-review protection, secrets and audit requirements |
| `API_MCP_SPEC.md` | Captain REST tools, response contracts, MCP adapter and capability discovery |
| `KNOWLEDGE_DIAGNOSTICS.md` | Journal Knowledge Compiler, Captain knowledge and diagnostics framework |
| `BUILD_PLAN.md` | implementation phases and phase exit criteria |
| `TASKLIST.md` | detailed engineering backlog with stable task IDs |
| `TEST_PLAN.md` | compatibility, security, contract, E2E and packaging tests |
| `RELEASE_GALLERY.md` | PKP Plugin Gallery compliance and immutable release procedure |
| `LICENSING.md` | GPL decision and Chatwoot/third-party licensing boundary |
| `ADRS.md` | accepted architecture decisions and intentional corrections to brainstorm assumptions |

## Upstream verification snapshot

Verification was performed 2026-08-28 against:

- `pkp/ojs` main, observed snapshot `bc7c98fa252bbb3e6cf5fee751474ddf0b718eba`;
- OJS main version metadata: `3.6.0.0`;
- `chatwoot/chatwoot` develop, observed snapshot `a4a9508854510e36100dff10b11524957246c835`;
- PKP current Plugin Guide release chapter in `pkp/pkp-docs`;
- local v1 baseline `2a0459971cb83950d3561580033684282bef56ec` (`1.0.0.2`).

These are inception snapshots, not permanent claims. Refresh `VERIFICATION_MATRIX.md` before stable release.

## Verified high-level conclusions

- OJS can identify logged-in users and current journal/context.
- Chatwoot’s website widget supports HMAC identity verification.
- Captain Custom Tools can call authenticated HTTP endpoints, but are feature/edition dependent, currently GET/POST and capped at 15 per account.
- Captain Documents support URL/PDF knowledge and sync workflows when the corresponding features are available.
- Captain Scenarios can select tool subsets.
- Captain has FAQ suggestion infrastructure with human approval states.
- OJS has native publication-fee/currency and submission-specific completed payment concepts.
- OJS/PKP mail infrastructure can support plugin verification mail.
- PKP plugins can register custom PageHandlers/routes without modifying core files.
- OJS current hooks/APIs support the foundations for event/context integration.

## Important qualified/rejected assumptions

- **Captain is not assumed to be a native MCP client.** v2 owns the MCP adapter; Captain uses REST Custom Tools.
- **The plugin does not automatically “understand every page/plugin”.** v2 builds explicit Knowledge Providers/generated support pages.
- **Chatwoot custom attributes/HMAC status do not alone authorize OJS resources.** identity → relationship → capability is re-evaluated server-side.
- **The LLM never receives forbidden reviewer/editorial data.** privacy is enforced in serializers/policy.
- **Diagnostics may return unknown.** the system must not manufacture a root cause.
- **Payment/admin writes are not public Captain actions.** privileged writes belong to a separate staff plane.

## PKP Plugin Gallery status

At inception the project is **not yet Gallery ready**. Known release gates include:

- repository/release package must be publicly downloadable before Gallery submission;
- explicit GPL-compatible root/package licence;
- automated tests;
- exact OJS compatibility evidence;
- immutable `.tar.gz` with one top-level `chatwootIntegration` directory;
- four-part release version;
- final MD5 and Gallery XML;
- PKP automated checks and code review.

The repository is currently private; keep it private during development if desired. Public visibility is an explicit owner release decision, not something v2 development should change automatically.

## Implementation rule

Before coding a feature:

1. find its task ID in `TASKLIST.md`;
2. confirm architecture/security requirements;
3. confirm upstream capability in `VERIFICATION_MATRIX.md` or add a new verification record;
4. implement with tests;
5. update an ADR if implementation changes a governing decision.

No stable product documentation should claim a feature merely because it exists in the Product Bible; only implemented/tested release features belong in the user-facing README/release notes.