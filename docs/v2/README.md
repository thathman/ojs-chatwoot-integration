# Chatwoot Integration for OJS — v2 Product Spec Kit

This directory is the implementation authority for v2 development on `v2-dev`.

## Product model

**Distribution/plugin:** Chatwoot Integration for OJS  
**OJS product identifier:** `chatwootIntegration`  
**Architecture:** OJS Support Gateway  
**Development branch:** `v2-dev`

v2 evolves the existing v1 widget/context/event bridge into a secure journal-aware support gateway with three complementary capabilities:

1. **Journal Brain** — authoritative public/semi-static journal knowledge compiled from OJS and approved providers.
2. **User Brain** — verified OJS identity, resource relationships and short-lived support sessions.
3. **Operations Brain** — live support state, multi-fee payment/publication information and evidence-based diagnostics.

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
| `AIRIX360_INTEGRATIONS.md` | first-party optional Support Provider architecture for Airix360 OJS plugins |
| `PAYMENT_PORTFOLIO.md` | authoritative multi-fee/waiver/gateway/refund model refining the original APC-only wording |
| `AIRIX360_VERIFICATION.md` | source-verified Airix360 plugin capabilities and planning/runtime classification |
| `AIRIX360_TASKLIST.md` | implementation backlog for Airix adapters, payment portfolio and provider SDK |
| `ADRS_AIRIX360.md` | accepted decisions governing Airix360 integrations and financial support behavior |

### Specification precedence

The original v2 Product Bible remains the overall product authority. The Airix360/payment supplements are accepted refinements of it:

- `PAYMENT_PORTFOLIO.md` supersedes any earlier wording that treats payment as one publication/APC status.
- `AIRIX360_INTEGRATIONS.md` refines the Provider Registry for first-party Airix360 integrations.
- `ADRS_AIRIX360.md` is part of the accepted ADR set.
- `AIRIX360_VERIFICATION.md` must be refreshed with exact sibling release versions before any stable release advertises those integrations.

## Upstream verification snapshot

Core platform verification was performed 2026-08-28 against:

- `pkp/ojs` main, observed snapshot `bc7c98fa252bbb3e6cf5fee751474ddf0b718eba`;
- OJS main version metadata: `3.6.0.0`;
- `chatwoot/chatwoot` develop, observed snapshot `a4a9508854510e36100dff10b11524957246c835`;
- PKP current Plugin Guide release chapter in `pkp/pkp-docs`;
- local v1 baseline `2a0459971cb83950d3561580033684282bef56ec` (`1.0.0.2`).

Airix360 sibling-plugin verification is recorded separately in `AIRIX360_VERIFICATION.md` and includes observed snapshots for Submission Fee, Request Waiver, Paymethod Support, Paystack, Flutterwave, Bachs, MultiPay, Contributor User Sync, Magic Login and Required Submission Files.

These are inception snapshots, not permanent claims. Refresh both verification matrices before stable release.

## Verified high-level conclusions

- OJS can identify logged-in users and current journal/context.
- Chatwoot’s website widget supports HMAC identity verification.
- Captain Custom Tools can call authenticated HTTP endpoints, but are feature/edition dependent, currently GET/POST and capped at 15 per account.
- Captain Documents support URL/PDF knowledge and sync workflows when the corresponding features are available.
- Captain Scenarios can select tool subsets.
- Captain has FAQ suggestion infrastructure with human approval states.
- OJS has native publication-fee/currency and submission-specific completed payment concepts.
- Airix Submission Fee adds a separate native-compatible submission-fee obligation.
- Airix Request Waiver has an explicit full/partial waiver integration method.
- Airix Paymethod Support already composes publication fee, submission fee and waiver state for OJS users/staff.
- Paystack, Flutterwave, Bachs and MultiPay expose an emerging shared internal refund convention based on completed-payment ID.
- Bachs documents a useful cross-plugin payment-generator descriptor/fulfilment contract.
- Airix Contributor User Sync, Magic Login and Required Submission Files expose support-relevant state suitable for dedicated providers.
- OJS/PKP mail infrastructure can support plugin verification mail.
- PKP plugins can register custom PageHandlers/routes without modifying core files.
- OJS current hooks/APIs support the foundations for event/context integration.

## Payment model

The v2 financial model is a **Payment Portfolio**, not a single APC boolean.

A submission may simultaneously have, for example:

- a submission fee produced by `submissionFee-OJS`;
- a publication/APC fee produced by OJS core;
- a full or partial waiver from `ojs-request-waiver`;
- collection through Paystack, Flutterwave, Bachs or MultiPay;
- pending reconciliation, refund or dispute state.

The Support Gateway therefore models **producer**, **obligation**, **ledger**, **adjustment/waiver provider** and **collector/orchestrator** separately. Captain still uses one canonical `ojs_get_payment_status` tool, but it returns the authorized portfolio rather than overwriting one fee with another.

## Airix360 first-party provider family

Selected Airix360 OJS plugins are first-class **optional** integrations. They are discovered/version-checked at runtime and are never bundled into the Chatwoot Integration Gallery archive.

Initial verified provider targets:

- `submissionFee-OJS`
- `ojs-request-waiver`
- `paymethodSupportOJS`
- `PaystackOJS`
- `FlutterwaveOJS`
- `BachsOJS`
- `ojs-multipay`
- `contributorUserSync`
- `ojs-magic-login`
- `ojs-required-submission-files-airix`
- `ojs-visibility-suite`

Other Airix360 OJS repositories are designed to plug in through the Provider SDK when their runtime contracts are implemented and verified. Planning-only projects are not advertised as currently supported.

## Important qualified/rejected assumptions

- **Captain is not assumed to be a native MCP client.** v2 owns the MCP adapter; Captain uses REST Custom Tools.
- **The plugin does not automatically “understand every page/plugin”.** v2 builds explicit Knowledge/Support Providers and generated knowledge pages.
- **“Airix360 integration” does not mean every current/future Airix repo is automatically supported.** Each adapter/provider requires a verified contract/version.
- **Chatwoot custom attributes/HMAC status do not alone authorize OJS resources.** identity → relationship → capability is re-evaluated server-side.
- **The LLM never receives forbidden reviewer/editorial data.** privacy is enforced in serializers/policy.
- **Diagnostics may return unknown.** the system must not manufacture a root cause.
- **Provider failure does not mean unpaid.** payment state may become `unknown` unless an authoritative ledger conclusively determines it.
- **Payment/admin writes are not public Captain actions.** privileged writes belong to a separate staff plane.
- **A partial waiver is not a full waiver.** the supported waiver provider contract determines the remaining payable amount.

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

Optional Airix360 sibling integrations add a second compatibility dimension: the release documentation must list only sibling plugin versions whose provider contracts have been tested. Those sibling plugins remain separately installed/distributed.

The repository may stay private during development. Public visibility is an explicit owner release decision, not something v2 development should change automatically.

## Implementation rule

Before coding a feature:

1. find its task ID in `TASKLIST.md` or `AIRIX360_TASKLIST.md`;
2. confirm architecture/security requirements;
3. confirm core upstream capability in `VERIFICATION_MATRIX.md` and sibling capability in `AIRIX360_VERIFICATION.md` when applicable;
4. prefer a sibling plugin’s documented integration method/hook over copied business logic;
5. implement with tests;
6. update an ADR if implementation changes a governing decision.

No stable product documentation should claim a feature merely because it exists in the Product Bible or an Airix repository. Only implemented, version-tested release features belong in the user-facing README/release notes.