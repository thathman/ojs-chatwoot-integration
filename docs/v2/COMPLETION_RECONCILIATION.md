# v2 Product Bible Completion Reconciliation

This is the authoritative map between the Product Bible/governing
specifications, the main and Airix360 tasklists, the real source tree,
and real acceptance evidence — per the owner's "return to product
completion" directive. It supersedes treating a `[x]` checkbox as a
completion claim on its own; the tasklists' own header already says this
("Checkboxes are not release claims; they become complete only with
acceptance tests"), and this document is where that promise is actually
kept.

Classification taxonomy (exactly as specified):

- **COMPLETE** — implemented, adequately tested, and (when applicable)
  real acceptance evidence exists.
- **BUILD NOW** — a real, intended part of the product with a real
  upstream/provider contract to build against.
- **VERIFY NOW** — implementation appears present, but real
  integration/acceptance evidence is missing.
- **NOT APPLICABLE** — no supported upstream mechanism exists; building
  it would mean guessing/hooking unstable internals.
- **OWNER-SCOPED DEFERRED** — a real product idea, intentionally outside
  the currently prepared product, reconciled against the Bible (not
  merely inherited from an earlier session's label).
- **BLOCKED** — a real external dependency makes the work impossible and
  no independent work remains.
- **SPEC / IMPLEMENTATION CONTRADICTION** — the Bible/spec says one thing,
  the runtime does materially another; needs an ADR/spec correction or an
  implementation fix.

This document is a living ledger — items move between categories as work
lands. First pass below inventories every unchecked item from
`TASKLIST.md`/`AIRIX360_TASKLIST.md`, plus the specific known
false-complete items the owner named, with real evidence for each
classification. It is not yet exhaustive line-by-line coverage of the
~15,000 words of already-`[x]` items in both tasklists (those get
spot-checked as work touches them, not re-audited wholesale in one pass)
— the focus here is the actual backlog: everything not yet done.

## 1. Main `TASKLIST.md` — unchecked items

| ID | Item | Classification | Evidence / reasoning |
| -- | ---- | -------------- | --------------------- |
| POL-004 | Staff consumer plane | **BUILD NOW** | Real, named Bible requirement (§7 "Staff Automation Plane"). Directive mandates a read-only foundation first (§12 of the directive). |
| POL-007 | Staff read policy | **BUILD NOW** | Depends on POL-004; same real requirement. |
| POL-011 | Resource/relationship-aware reviewer masking (replace v1 role-wide masking) | **BUILD NOW** | Bible §12 is explicit that v1's role-wide masking must be replaced. Real gap: `addChatwootWidget()`'s `is_masked` logic still keys off the journal-wide Reviewer role, not the current resource. Named directly by the owner as a real product gap. |
| STA-003 | OJS 3.6 state mapping | **OWNER-SCOPED DEFERRED** | Directive §54: OJS 3.6 stays deferred until a dedicated compatibility cycle; do not build "merely to empty an unchecked task." |
| KNO-011 | Approved-FAQ knowledge provider | **BUILD NOW** | Real Chatwoot Captain `Captain::AssistantResponse` API verified (routes.rb, real enum). Owner explicitly reinstates this (directive §8) with a required local-sync architecture (never live Chatwoot HTTP on an anonymous page load). |
| KNO-020 | Knowledge health UI (per-provider/conflict detail) | **BUILD NOW** | Aggregate state already ships (ADM-002); owner explicitly wants the fuller drill-down (directive §9). |
| DIA-012 | Public vs. staff diagnostic serializers | **BUILD NOW** | Depends on POL-004/007's staff plane; same deliberate scope boundary, now being lifted. |
| EVT-008 | Payment event adapters | **NOT APPLICABLE for native OJS core** / **BUILD NOW for Airix sibling providers** | Verified: no `Hook::call`/`Hook::run` anywhere in pkp-lib's `classes/payment/`. Directive §15 explicitly asks to check one layer outward — the *installed* Airix payment plugins (Bachs, Paystack, Request Waiver, real on dell) may expose their own stable hooks; that's the real, buildable half. Split into two rows below (AWA-009/AGW-family cover the sibling side). |
| EVT-009 | DOI event adapters | **NOT APPLICABLE** | Verified: no `Hook::call`/`Hook::run` anywhere in pkp-lib's `classes/doi/`. No sibling DOI plugin was found installed on dell to check one layer outward for. Re-verify if a DOI-registration plugin is ever installed. |
| PRV-004 | `AccountSupportProviderInterface`/`SubmissionRequirementProviderInterface`/`ContributorSupportProviderInterface` | **BUILD NOW** | Directive §18 names real, already-verified-installed sibling plugins (Magic Login, Required Submission Files, Contributor User Sync) as the first real implementations — this is no longer speculative, it has real siblings to build against. |
| AUD-005 | Staff mutation audit | **NOT APPLICABLE UNTIL A REAL STAFF MUTATION EXISTS** | Exactly as the directive states (§13) — building a fake mutation-audit subsystem with no real mutation to audit would be premature. Revisit the moment any staff mutation is approved and built. |
| AUD-008 | Health dashboard for components/providers/queues | **BUILD NOW** | Directive §10; ADM-002's aggregate status is real but not the full observability model Architecture §10 describes. |
| TST-001 | Dedicated `SupportStateMapper` unit suite | **BUILD NOW** | Concrete, scoped, no external dependency — pure gap-filling. |
| TST-005 | OJS 3.6 exact-version matrix | **OWNER-SCOPED DEFERRED** | Tied to STA-003; same deferral. |
| TST-006 | MariaDB matrix | **VERIFY NOW** | Real upstream support exists (OJS itself supports MariaDB); this plugin has not run its own migrations/atomic-consume/queue tests against it. Directive §45 asks for an isolated Dell environment, not disruption of the shared demo. |
| TST-007 | PostgreSQL matrix | **VERIFY NOW** | Same reasoning as TST-006. |
| TST-013 | Full OpenAPI contract coverage (all 14 endpoints, every branch) | **BUILD NOW** | Currently only 2 of 14 endpoints get full bidirectional contract coverage; the rest only get a path-existence check. Concrete, no external dependency. |
| RELS-001/002/009/013/014/015/016 | Public release actions | **COMPLETE (already executed; see §7 below), except RELS-014 which is intentionally not currently open** | See the dedicated Release Status section — do not re-run these; the directive forbids any further publication action this pass. |
| (unnamed, "Deferred / explicit post-baseline ideas") staff write actions (review reminders/deadline changes) | | **OWNER-SCOPED DEFERRED, revisit under staff plane** | Directive §46: needs a real supported OJS operation, target derivation, confirmation, idempotency, audit — build only once POL-004's staff plane exists and a real safe OJS operation is confirmed. |
| secure author file download links | | **VERIFY NOW (research required)** | Directive §46: research OJS's real file-authorization mechanism before building; do not expose filesystem paths or permanent unrestricted URLs. |
| duplicate-account staff resolution workflow | | **OWNER-SCOPED DEFERRED** | Directive §46: never auto-merge; a safe staff diagnostic may be buildable, actual merge needs an official OJS path. |
| Crossref/DataCite-specific deep diagnostics | | **BLOCKED (pending real installed/verified DOI provider)** | No real DOI/provider integration verified installed on dell yet — directive §46 explicitly says only build after that verification. |
| payment-provider-specific remediation actions | | **OWNER-SCOPED DEFERRED (staff-only, sandboxed)** | Covered by the AGR-* (refund) rows below; safe read-only support can be built now, remediation mutations stay staff-only/sandboxed. |
| automatic support analytics/FAQ approval workflow | | **NOT APPLICABLE** | Directive §39/§46: only the human-approved FAQ loop (KNO-011) is in scope; *automatic* promotion of conversations/summaries is explicitly excluded. |
| proactive WhatsApp/Telegram messaging beyond Chatwoot's configured channels | | **NOT APPLICABLE** | Directive §46: use Chatwoot's existing channel abstraction; do not build separate channel transports. |
| native Chatwoot MCP integration | | **NOT APPLICABLE** | MCP-009 already verified no such feature exists upstream; directive §46 confirms staying N/A until upstream changes. |

## 2. MCP gaps (ADR-023 vs. real runtime — directive §11)

| Item | Classification | Evidence |
| ---- | -------------- | -------- |
| `server/discover` handler | **BUILD NOW** | Confirmed real gap: `McpProtocol::METHOD_DISCOVER` is defined but `McpDispatcher` has no registered handler for it — a real client call returns `METHOD_NOT_FOUND`. ADR-023 lists it in the initial scope. |
| `identity.request_verification` MCP tool | **BUILD NOW** | REST already implements the full verification-request flow (`SupportApiRequestResolver`/`VerificationChallengeService`); MCP has no equivalent tool yet. Must reuse the same challenge engine, same anti-enumeration/rate-limit rules, and use the distinct `mcpServiceToken` credential — never grant identity from the MCP credential alone. |
| `identity.confirm_verification` MCP tool | **BUILD NOW** | Same reasoning; reuse `VerificationChallengeService::confirmLinkToken()`/PIN consumption verbatim, prove REST/MCP semantic equivalence the same way `tests/v2/mcp-security.php` already does for `submission.get_required_actions`. |
| `submission.get_timeline` | **NOT APPLICABLE for now — needs an evidence-based ADR before any build** | Directive §11 explicitly forbids inventing a timeline model. STA-006 already found (verified against real pkp-lib schemas) that OJS core has no queryable revision-deadline field — the same honesty applies here. Requires a dedicated research pass (real OJS event/timestamp inventory) before deciding BUILD NOW vs. a permanent ADR-recorded N/A; not resolved in this pass. |

## 3. Airix360 supplement (`AIRIX360_TASKLIST.md`) — unchecked items

This file is explicitly a "required v2 backlog supplement," not an idea
dump — reconciled here at the family level (individual items inside a
family share one real evidence basis; called out individually only where
the classification differs from the family default).

### Payment Portfolio foundation (PTF-001 through PTF-015)

**BUILD NOW** — `docs/v2/PAYMENT_PORTFOLIO.md` freezes the real
architectural conclusion ("payment support is a portfolio, not one APC
status") and the real canonical pipeline (fee producer → obligation →
adjustment/waiver → payable balance → collector/gateway → ledger →
refund/dispute). Real, installed sibling gateways exist on dell (Bachs,
Paystack, Flutterwave, Multi-Gateway/MultiPay — confirmed via the real
plugin list on `ojs-demo.airixmedia.com`) to build the DTOs and service
against. PTF-013 (staff financial capability namespace) is
**BUILD NOW, definitions only** — no implementation until POL-004's
staff plane exists. PTF-012 (keep public plane free of money mutations)
is a standing rule to enforce continuously, not a one-time deliverable.

### Provider SDK expansion (AXP-004/005/006/010/011/013/014)

**BUILD NOW**, per directive §18 — real, verified, already-installed
first implementations exist for each interface:
`AccountSupportProviderInterface` → Magic Login (real, installed, seen
enabled on dell); `SubmissionRequirementProviderInterface` → Required
Submission Files (real, installed); `ContributorSupportProviderInterface`
→ Contributor User Sync (real, installed, already has a real adapter for
knowledge facts — `PRV-004`'s own note shows the pattern to extend).
AXP-013 (version-constraint mechanism) and AXP-014 (overlap/conflict
declaration) are **BUILD NOW, small and mechanical** — generalize the
existing single hard-coded `1.x` check pattern. AXP-011 (reference-plugin
docs) is **BUILD NOW, docs-only**, once the above land (documenting an
SDK that doesn't fully exist yet would be premature).

### Airix Submission Fee (APS-002/004/007/009/010/011/012)

**BUILD NOW** — real, installed sibling (`Submission Fee` plugin
confirmed on dell's real plugin list), already has a real first adapter
(`getAirixSubmissionFeeProvider()`). APS-011 (public Knowledge provider
for fee policy) is **BLOCKED on KNO-011's provider-registration pattern
existing first** — order matters, no independent work remains until that
lands; not a standalone gap.

### Request Waiver (AWA-001 through AWA-011)

**BUILD NOW** — real, installed sibling (`Request Waiver` plugin
confirmed on dell), with the real, already-documented public contract
`RequestWaiverPlugin::getWaiverDiscount(int $submissionId)`. AWA-010
(staff-plane waiver-decision capabilities) is **BUILD NOW, definitions
only**, gated the same way as PTF-013 — no implementation before the
staff plane exists. AWA-011 (contract tests for exact supported
releases) is **BUILD NOW** once AWA-001's version-constraint check
(mirroring `getAirixSubmissionFeeProvider()`'s pattern) is added.

### Paymethod Support (APM-001 through APM-008)

**BUILD NOW** — real, installed sibling confirmed (`Payment Method
Support` plugin on dell's real list). APM-002 explicitly forbids proxying
the existing browser/CSRF handler — must extract/refactor genuinely
server-side, reusable semantics instead. APM-006/APM-007 (future staff
adapter, never call the browser CSRF handler as a public API) are
standing rules to build correctly from day one, not deferred.

### Payment Gateway adapters (AGW-001 through AGW-013)

**BUILD NOW** for the gateways actually confirmed installed and enabled
on dell (Paystack, Bachs, Multi-Gateway/MultiPay — all three seen live in
the real plugin list). **BLOCKED** for Flutterwave specifically: also
seen installed on dell's plugin list, but not yet confirmed *configured*
(a real live-key/test-key state check is still needed) — re-classify to
BUILD NOW once that's confirmed, likely trivial. AGW-011 (strip
credentials/tokens/card metadata/raw payloads) is a standing security
rule enforced from the first line of code, not a separate deliverable.

### Refund adapter (AGR-001 through AGR-010)

**OWNER-SCOPED DEFERRED (design/contract documentation now; no real
invocation without a sandbox)** — the real shared `refundByCompletedPaymentId(...)`
contract exists across Airix gateways (per the Bible research already
done), but directive §25 is explicit: **no real production refund during
acceptance testing**, sandbox/test data only, staff-only, and this is
"not required just to prepare public support." AGR-001 through AGR-005
(documenting/verifying the exact method signature per installed gateway
release) are **BUILD NOW, read-only research** — safe to do without
invoking anything. AGR-006 through AGR-010 (staff-only enforcement,
authorization, server-derived params, idempotency/audit, safe failure
handling) are **BUILD NOW as design constraints enforced in code**, but
actual invocation testing stays gated on a real sandbox becoming
available — currently **BLOCKED** for the live-invocation test rows
specifically (no confirmed sandbox transaction capability yet on dell).

### Bachs generic-completion contract (BGC-001 through BGC-006)

**BUILD NOW** — real, installed, real documented contract
(`Payment::describeQueuedPayment`). No external blocker.

### Contributor User Sync (ACU-001 through ACU-010)

**BUILD NOW** — real, installed sibling, already has a real first
knowledge-fact adapter to extend (per PRV-004's note). ACU-007/008
(never expose another contributor's data, never auto-merge) are standing
privacy rules, not separate deliverables.

### Magic Login (AML-001, AML-003, AML-006, AML-007)

**BUILD NOW** — real, installed, enabled sibling confirmed on dell.
AML-006 (keep Chatwoot verification separate from Magic Login
session/token semantics) stays **NOT APPLICABLE until a Magic Login
*action* — not just availability — is ever exposed as a tool**, exactly
as its own note says; re-classify only if that scope actually grows.

### Required Submission Files (ARF-001, ARF-004 through ARF-008)

**BUILD NOW** — real, installed sibling, already has a real
journal-level knowledge-fact adapter to extend to the
per-submission-diagnosis level.

### Visibility Suite (AVS-001 through AVS-006)

**BUILD NOW** — directive §29 confirms this is a verified, real
opportunity; a real installed sibling was seen on dell's plugin list
("OJS Visibility & Indexing Suite"). AVS-003 (never claim guaranteed
indexing/citation) and AVS-005 (detect/avoid duplicate knowledge with any
standalone schema.org provider) are standing correctness rules to build
in from the start.

### Future Airix providers (AFP-001 through AFP-010)

**NOT APPLICABLE / BLOCKED — pending each named sibling reaching a real,
inspectable production implementation.** Every one of these items is
explicitly worded "re-inspect when production implementation exists" —
directive §30 confirms: inspect, do not invent. Each remains N/A/blocked
until its named sibling repository is actually runnable and inspectable;
re-run this specific inspection pass (not a rebuild) the moment any one
of them ships. Do not treat this row as permanently closed — it is a
recurring check, not a one-time verdict.

### Payment Portfolio acceptance fixtures (AXT-006 through AXT-025)

**BUILD NOW**, contingent on the corresponding provider work above
landing first (a fixture cannot exist before its subject does). Real
sandbox/test-mode data only for anything gateway-related (directive
§25/§49) — confirmed real TEST MODE banner already observed live on
dell's OJS instance for the active payment gateway, so sandbox testing is
genuinely available without real-money risk.

## 4. Release status (do not re-run; informational only)

The following `RELS-*` items were already executed as real, deliberate,
owner-approved actions in the prior release-authorization phase of this
project, before this reconciliation directive was issued. They are
recorded here as fact, not re-attempted:

- **RELS-001/002**: `thathman/ojs-chatwoot-integration` on GitHub is
  public.
- **RELS-009**: Immutable release `v2.0.0.0` exists, tag resolves to
  commit `5cc04bc86f7e19e0df9d282f96f3d60d9e82b796`, asset re-downloaded
  and re-hashed to confirm byte-for-byte integrity
  (`752d100032f333ba9d142c485bbfd8ac` / `af16b0657585af6676c86cd0cdd08ec25323cc941616ead97b6117f5107fb727`).
  **This tag/artifact is not modified by this reconciliation pass or any
  work that follows it.**
- **RELS-013**: The real, live asset URL was validated (reachable,
  correct MD5) before it was ever cited anywhere.
- **RELS-014**: A PR to `pkp/plugin-gallery` (#534) was opened, then
  explicitly closed at the owner's direct request ("dont open pkp pr
  yet. delete it") before this reconciliation directive was issued. It
  remains closed. **This pass does not reopen, update, or otherwise
  advance it** — the directive is explicit that no Gallery submission
  work happens as part of this completion effort.
- **RELS-015/016**: Not applicable while RELS-014 stays closed.

Real acceptance testing *after* that release (this session, continuing)
already found and fixed five real defects the release process itself
never caught (TST-017 through TST-021 — Apache header handling, the
nonexistent `\PKP\user\Repo` class, the missing verification challenge
reference, the component-router widget-hook crash, and the scheduled-task
constructor bug) — all corrective fixes on `v2-dev`, none touching the
immutable `2.0.0.0` artifact. Collected for the next four-part version
once this completion pass concludes.

## 5. Settings/Mail reconciliation (directive's additional mandate)

Not started in this pass — tracked here as the next dedicated ledger to
build: `docs/v2/SETTINGS_RECONCILIATION.md` (every setting: key, scope,
security classification, current UI presence, functional verification)
and a mail-wiring ledger covering `SupportVerificationMailable`/
`SupportMailTestMailable`/EmailTemplate promotion. Real evidence already
in hand from this session's acceptance pass that must feed directly into
that ledger:

- `launcherBottomOffset` is confirmed dead (V1_INVENTORY.md, FND-004) —
  needs a deliberate wire-up-or-remove decision, not further silence.
- The live admin settings page (`ojs-demo.airixmedia.com`) was, until
  this session's TST-020/TST-021 fixes, silently missing its entire
  Health Dashboard, Captain sync/repair, mail-test, and MCP configuration
  sections due to a real theme-template-override drift (documented in
  `docs/v2/ACCEPTANCE_TEST_MATRIX.md`) — now synced and confirmed
  rendering.
- Verification mail is currently fixed, code-driven content
  (`VerificationEmailContentBuilder`), not a real OJS-administrable
  EmailTemplate — IDN-007's own note already flags this as a scope
  decision, and the directive (§MAIL-004) now makes promoting it
  BUILD NOW.

## 6. Running summary

```
BIBLE RECONCILIATION (first pass — see per-item tables above)

MAIN TASKLIST unchecked items classified: 24 (+ 8 "deferred ideas" rows)
AIRIX360 SUPPLEMENT unchecked items classified: ~150, by family (17 families)
MCP gaps classified: 4

BUILD NOW (this pass's real backlog): the large majority — Payment
  Portfolio foundation, full Provider SDK expansion, all confirmed-
  installed sibling adapters (Magic Login, Required Submission Files,
  Contributor User Sync, Submission Fee, Request Waiver, Paymethod
  Support, Paystack/Bachs/MultiPay gateways, Visibility Suite), staff
  read-plane foundation, MCP server/discover + identity verification
  tools, KNO-011/KNO-020/AUD-008, POL-011 reviewer masking, TST-001/013,
  MariaDB/PostgreSQL verification.
OWNER-SCOPED DEFERRED: OJS 3.6 (STA-003/TST-005), automatic
  FAQ-approval/analytics, cross-channel messaging beyond Chatwoot,
  native Chatwoot MCP, duplicate-account auto-resolution, most
  staff-mutation write actions (pending the staff plane's own build).
NOT APPLICABLE (verified, evidence-backed): EVT-008/009 for native OJS
  core (no stable hook exists), AFP-* family (siblings not yet real),
  submission.get_timeline (pending a dedicated evidence-based ADR).
BLOCKED: Flutterwave adapter (installed but unconfirmed configuration
  state), Crossref/DataCite diagnostics (no verified installed DOI
  provider), live refund invocation testing (no confirmed sandbox
  transaction capability yet).
SPEC CONTRADICTIONS found and already resolved this pass: none new
  beyond the acceptance-testing defects already tracked in
  ACCEPTANCE_TEST_MATRIX.md (TST-017 through TST-021).
```

This document will be updated as each `BUILD NOW` item lands, each
`VERIFY NOW` item gets real acceptance evidence, and each `BLOCKED` item
either resolves or gets a documented owner decision.
