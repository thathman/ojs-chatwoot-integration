# v2 Upstream Verification Matrix

Verified: 2026-08-28

This document separates product ambition from capabilities confirmed in current upstream source. “Confirmed” means the underlying platform capability exists; it does not mean the v2 implementation is already written.

## Verification snapshots

### OJS / PKP

- Repository: `pkp/ojs`
- Branch inspected: `main`
- Snapshot observed: `bc7c98fa252bbb3e6cf5fee751474ddf0b718eba`
- OJS main `dbscripts/xml/version.xml`: `3.6.0.0`
- PKP plugin guide source: `pkp/pkp-docs/dev/plugin-guide/en/release.md` (3.5 guide)

### Chatwoot

- Repository: `chatwoot/chatwoot`
- Branch inspected: `develop`
- Snapshot observed: `a4a9508854510e36100dff10b11524957246c835`

### Existing plugin

- Repository: `thathman/ojs-chatwoot-integration`
- v1 baseline commit: `2a0459971cb83950d3561580033684282bef56ec`
- v1 release: `1.0.0.2`

## Status vocabulary

- **CONFIRMED** — directly supported by current upstream implementation.
- **CONFIRMED WITH CONSTRAINTS** — supported, but feature/edition/security/version constraints matter.
- **FEASIBLE / BUILD REQUIRED** — upstream exposes enough primitives, but the feature is ours to implement.
- **NOT NATIVE** — do not claim upstream provides this capability.
- **REJECT AS CLAIM** — wording is unsafe or materially misleading and must not appear as a product promise.

## Claim matrix

| Claim / brainstorm item | Status | Upstream evidence | v2 implication |
|---|---|---|---|
| Plugin can detect the currently logged-in OJS user | CONFIRMED | OJS request handlers use `$request->getUser()`; v1 already uses it | Make authenticated OJS session the preferred identity path |
| Plugin can detect current journal/context | CONFIRMED | OJS handlers use `$request->getContext()` / `getJournal()`; v1 resolves context | Bind every support session and capability to context |
| OJS user can be securely identified to Chatwoot web widget with HMAC | CONFIRMED | `app/controllers/api/v1/widget/contacts_controller.rb` verifies SHA-256 HMAC and sets `hmac_verified` | Preserve HMAC identity, but do not treat Chatwoot attributes alone as authorization |
| Chatwoot accepts user/custom attributes from widget | CONFIRMED | widget contacts controller permits identifier/email/name/custom attributes | Continue rich context for agent UX, never as source of truth |
| v1 page/article/DOI/section context is possible | CONFIRMED | v1 code plus OJS article/context objects | Refactor into version-aware Context Provider |
| OJS decisions/submissions/publications expose hooks | CONFIRMED | generated OJS `docs/dev/guide/hooks.rst` includes `Decision::add`, submission/publication hooks | Keep event adapters covered by compatibility tests |
| Plugin can add its own OJS route/page/handler | CONFIRMED | PKP Plugin Guide `examples-custom-page.md` documents `LoadHandler` + plugin PageHandler | Support API/knowledge endpoints can live in plugin without core edits |
| Plugin can “extend OJS API” safely | CONFIRMED WITH CONSTRAINTS | OJS has API controllers/hooks and plugin custom handlers | Define a plugin-owned Support API; do not patch core API files |
| OJS has publication/APC fee configuration | CONFIRMED | `classes/payment/ojs/OJSPaymentManager.php`: `publicationFee`, currency, `publicationEnabled()` | Public Journal Brain can expose configured fee/currency |
| OJS tracks submission-specific publication payment state | CONFIRMED | `_submissions/BackendSubmissionsController.php` queries `OJSCompletedPaymentDAO` by submission and publication payment type | Protected tool can report paid/waived/unpaid after authorization |
| Public Captain should mark payment paid/waived | REJECT AS CLAIM | OJS has staff-protected write route, not public-author authority | Writes are staff plane only and require explicit policy/confirmation |
| OJS supports Mailables for verification email | CONFIRMED | current OJS code uses app/PKP mailables and mail sending | Implement plugin Mailable(s) for PIN/link and test per supported OJS version |
| Captain can call custom HTTPS tools | CONFIRMED WITH CONSTRAINTS | `enterprise/app/models/captain/custom_tool.rb`, `assistant.rb`, `toolable.rb` | REST is the supported Captain adapter today |
| Captain Custom Tools support unlimited tools | REJECT AS CLAIM | `MAX_PER_ACCOUNT = 15` | Keep Captain-facing canonical tool set <=12 to leave headroom |
| Captain Custom Tools support arbitrary HTTP methods | REJECT AS CLAIM | current enum is GET/POST | Design REST endpoints around GET/POST for Captain; MCP need not share this limitation |
| Captain Custom Tools support service authentication | CONFIRMED | none, bearer, basic, api_key auth supported | Require authenticated gateway; never trust metadata headers alone |
| Captain sends useful conversation/contact metadata to tools | CONFIRMED | Toolable emits Chatwoot account/assistant/tool/conversation/contact/contact-inbox headers | Use metadata as request context after authenticating the tool call |
| Captain tool call can tell whether widget contact was HMAC verified | CONFIRMED WITH CONSTRAINTS | `X-Chatwoot-Contact-Inbox-Verified` is based on `hmac_verified` | Useful signal, but not sufficient for fresh OJS session authorization by itself |
| Captain supports scenarios with selected tools | CONFIRMED | `enterprise/app/models/captain/scenario.rb` resolves selected enabled tools | Use scenarios to narrow support domains/tool exposure |
| Captain can learn journal material from URL documents | CONFIRMED WITH CONSTRAINTS | Captain Document/CrawlJob/SimplePageCrawlService | Generate crawl-safe support knowledge root/pages; do not expose private data |
| Captain URL crawl recursively understands an entire site automatically | REJECT AS CLAIM | simple crawl extracts links from supplied page/sitemap; deep behavior depends on Firecrawl availability | Provide direct root links and sitemap; explicit Knowledge Compiler is required |
| Captain Documents can be re-synced | CONFIRMED WITH CONSTRAINTS | DocumentsController sync action; scheduled sync exists behind feature/plan | Plugin may provision/request sync if Chatwoot credentials/features permit |
| Plugin can programmatically provision Captain documents/tools | CONFIRMED WITH CONSTRAINTS | Captain document/custom-tool API controllers exist and are authorized/feature-gated | Add optional provisioning wizard; manual configuration remains fallback |
| Captain can surface recurring FAQ suggestions | CONFIRMED | `Captain::FaqSuggestion` has source counts and open/approved/dismissed states | Use as feedback signal; authoritative OJS knowledge changes require human approval |
| Captain memory can be treated as journal truth | REJECT AS CLAIM | assistant has memory feature flag, but it is not an authoritative source | Memory is lowest-priority context only |
| Captain natively supports MCP today | NOT NATIVE | no relevant MCP implementation found in current Chatwoot source | Build plugin-owned MCP adapter; Captain uses REST custom tools |
| OJS natively provides an MCP server | NOT NATIVE | no such native OJS facility is assumed | MCP is our transport adapter over Support Core |
| Plugin can automatically know every journal page | FEASIBLE / BUILD REQUIRED | OJS exposes context/data/pages; plugin can render/crawl, but semantics are not automatic | Implement Knowledge Providers + generated normalized knowledge pages |
| Plugin automatically understands every third-party OJS plugin | REJECT AS CLAIM | no universal semantic provider contract exists | Create v2 Provider Registry; third parties need adapters/registration |
| Logged-in user should skip PIN | FEASIBLE / BUILD REQUIRED | OJS session user detection + Chatwoot HMAC primitives exist | Build short-lived server-side support binding; never accept LLM-supplied user ID as authority |
| External WhatsApp/Telegram/email user can verify with OJS email PIN | FEASIBLE / BUILD REQUIRED | OJS mail stack + plugin endpoint primitives exist | Build challenge/session store, throttling, generic anti-enumeration responses |
| Secure verification link is possible | FEASIBLE / BUILD REQUIRED | custom plugin routes + OJS session/token primitives are available | Prefer where practical so secret is not left in chat transcript |
| Plugin can determine author/submission relationship | CONFIRMED WITH CONSTRAINTS | OJS submissions, stage assignments and authorization policies exist | Implement dedicated Relationship Resolver; do not use “active submission count” as authorization |
| Plugin can safely answer “where is my manuscript?” | FEASIBLE / BUILD REQUIRED | OJS submission/workflow state exists | Build Support State Engine + field policy |
| Plugin can answer “how much is publication?” from OJS | CONFIRMED | OJS publication fee/currency are structured context settings | Journal Brain provider; no verification needed if journal treats fee as public |
| Plugin can answer “have I paid?” | CONFIRMED WITH CONSTRAINTS | OJS completed payment lookup by submission exists | V3 relationship + payment-read capability required |
| Plugin can troubleshoot login/account problems | FEASIBLE / BUILD REQUIRED | OJS user/auth/reset flows exist (some implementation in pkp-lib) | Build privacy-preserving account diagnostic; avoid account enumeration |
| Plugin can diagnose exact submission failure automatically | FEASIBLE / BUILD REQUIRED | workflow/submission state exists, but exact causes require rules/instrumentation | Diagnostic may return unknown/needs_human; never let LLM invent cause |
| Plugin can diagnose upload limits | FEASIBLE / BUILD REQUIRED | PHP/OJS configuration can be inspected | Correlating a specific failed upload requires instrumentation/error context |
| Plugin can diagnose downstream email delivery with certainty | REJECT AS CLAIM | app can inspect/send/configure, but external delivery is not guaranteed | Claim “mail configuration/send-path diagnosis”, not final mailbox delivery proof |
| Plugin can create short-lived secure file links | FEASIBLE / BUILD REQUIRED | plugin handler/auth/file primitives exist | Build explicit file-policy/signed download endpoint; no arbitrary file ID access |
| Plugin can detect possible duplicate OJS accounts | FEASIBLE / BUILD REQUIRED | user/submission data can be compared by authorized staff logic | Support clue only; never auto-merge |
| OJS events can update/open Chatwoot support context | CONFIRMED WITH CONSTRAINTS | v1 already calls Chatwoot API; OJS hooks exist | Make event delivery queued/idempotent and configurable |
| Proactive customer messages are always safe | REJECT AS CLAIM | technically possible, but consent/policy/notification duplication matter | Default to notes/context; proactive outbound is opt-in per event/journal |
| One OJS install can serve multiple journals | CONFIRMED | OJS contexts; v1 already has local/global settings | Every cache/session/provider result is context-scoped |
| Support Provider Registry can allow other OJS plugins to contribute | FEASIBLE / BUILD REQUIRED | PKP hook/plugin architecture is extensible | Define stable v2 provider contracts and capability registration |
| REST and MCP can share the same authorization/service layer | FEASIBLE / BUILD REQUIRED | transport is ours | Mandatory architecture: no duplicated policy logic |
| Human handoff can include OJS support summary | CONFIRMED WITH CONSTRAINTS | Chatwoot conversations/notes + gateway context | Only safe, authorized summary; no confidential review data |

## Important corrections to the original brainstorm language

### “It lives inside OJS so it sees and knows everything.”

Corrected claim: **The plugin can access OJS-authorized application context and structured journal data and can build explicit providers for pages/plugins. It does not automatically understand every page, theme or third-party plugin.**

### “Captain can connect to the MCP.”

Corrected claim: **v2 will expose MCP for MCP-capable clients, while Captain integrates through its current HTTPS Custom Tools. Native Captain MCP must not be a release dependency unless upstream later adds and we re-verify it.**

### “Chatwoot verification means the user is authorized.”

Corrected claim: **Chatwoot HMAC verifies a widget identity binding. The Support Gateway still establishes freshness, maps to an OJS identity, checks the relationship to the requested resource and computes capabilities.**

### “Captain can see OJS raw data and decide what is private.”

Corrected claim: **Forbidden data is removed before the model receives a response. The LLM is not the privacy boundary.**

## Chatwoot edition/licence condition

Captain implementation inspected above resides under Chatwoot’s `enterprise/` tree. Chatwoot’s root licence explicitly delegates that tree to `enterprise/LICENSE`, whose production use requires the applicable Chatwoot Enterprise subscription/licence terms. Therefore:

- the OJS plugin itself remains independently GPL-compatible;
- Core widget/API integration must not require copied Chatwoot enterprise code;
- Captain Intelligence features are optional and documented as requiring a Chatwoot edition/plan that exposes those APIs/features;
- we integrate through supported network APIs and do not vendor Chatwoot enterprise source.

## Compatibility rule

OJS `main` currently reports `3.6.0.0`; v1 claims 3.5+. v2 will **not** publish a blanket “3.5+” compatibility promise. Each Gallery release must list only OJS versions that have passed the compatibility/test matrix. OJS 3.5.x is the initial compatibility baseline to preserve v1 intent; OJS 3.6.x is a separate target gated by tests.

## PHP support matrix (TST-008)

Verified against a real local checkout of `pkp/pkp-lib` at branch `stable-3_5_0` (commit `6d0d04f41a89cba4daabd1e9b9b63eb677a29949`, dated 2026-07-21) — the exact branch behind the OJS 3.5 release this plugin targets, not `main` (which has already moved to 3.6.0.0 per the snapshot above):

- `composer.json`: `"php": "^8.2"` — i.e. PHP >= 8.2.0, < 9.0.0.
- `classes/core/PKPApplication.php`: `public const PHP_REQUIRED_VERSION = '8.2.0';` — the installer itself refuses to run below this.
- Real upstream precedent for the exact CI matrix to test against: `pkp/ojs`'s own `.github/workflows/stable-3_5_0.yml` tests PHP `8.2` and `8.3` — never `8.1`.

**Conclusion: PHP 8.1 is not a supported OJS 3.5 target at all** — this plugin's CI matrix previously ran `8.1`/`8.2`, which tested a PHP version OJS 3.5 itself cannot run on. `.forgejo/workflows/ci.yml` and `.github/workflows/ci.yml` are corrected to `8.2`/`8.3`, matching OJS 3.5's own real, verified CI matrix. No PHP version beyond what OJS 3.5's own composer constraint and CI already cover is added.

## Re-verification policy

Before any stable v2 release:

1. refresh upstream Chatwoot and OJS snapshot SHAs;
2. re-run this matrix for changed integration surfaces;
3. run the declared OJS/DB/PHP compatibility matrix;
4. update any changed claim from Confirmed to Conditional/Build Required as necessary;
5. never preserve a product claim merely because it was true at v2 inception.

## Compatibility-update process for a future OJS release (RELS-017)

When a new OJS release appears (a new 3.5.x patch, or eventually 3.6),
repeat exactly the real, verified process this release actually used —
do not assume compatibility carries forward silently:

1. **Re-run the hook check** (`docs/v2/V1_INVENTORY.md` "Registered hooks
   in v1") against a real local checkout of the new target branch —
   confirm each of the 7 real hook names this plugin registers still
   exists at the same real call site, the same way `Publication::publish`
   vs. `Publication::publish::before` was disambiguated for 3.5. A hook
   rename or removal is a real compatibility break, not a cosmetic one.
2. **Re-run the PHP support matrix check**
   (`docs/v2/VERIFICATION_MATRIX.md` "PHP support matrix") against the new
   target's own `composer.json`/`PKPApplication::PHP_REQUIRED_VERSION` and
   its own real upstream CI matrix — never assume the previous PHP range
   still applies; update `.forgejo/workflows/ci.yml` (and, for
   consistency, `.github/workflows/ci.yml`) only to what's actually
   verified.
3. **Re-run the real install/upgrade proof** (`docs/v2/TASKLIST.md`
   TST-004/RUN-001) against a real instance of the new OJS version —
   `lib/pkp/tools/installPluginVersion.php`, verifying the real `versions`
   table row and all 5 real Support Gateway tables, plus a real HTTP
   smoke test of the new v2 routes (the exact class of bug TST-014 found
   and fixed was only discoverable this way, never by code review alone).
4. **Re-run the real Chatwoot API verification** in
   `docs/v2/VERIFICATION_MATRIX.md`'s claim matrix for anything Captain/
   Custom-Tool/Scenario-related against a current `chatwoot/chatwoot`
   checkout — Chatwoot's own Captain API has changed shape before (see
   the real `enterprise/` findings cited throughout `docs/v2/
   KNOWLEDGE_DIAGNOSTICS.md`).
5. Only after 1–4 pass for real does the version get added to this
   plugin's declared compatibility range — never widened speculatively.