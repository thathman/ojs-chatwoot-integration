# Airix360 Integration ADR Supplement

These decisions extend `ADRS.md` and are accepted for v2 unless superseded by a later recorded decision.

## ADR-A001 — Airix360 is a first-party optional provider family

**Decision:** The OJS Support Gateway ships explicit adapters/provider compatibility for selected Airix360 OJS plugins while remaining fully operational without them.

**Why:** These plugins extend OJS in domains that materially affect author/reviewer support. Treating them as opaque would make the support assistant confidently incomplete on installations that use the Airix stack.

**Consequence:** sibling plugin versions become part of the compatibility/test matrix.

## ADR-A002 — Payment is a portfolio, not a single APC record

**Decision:** Financial state is modeled as a collection of obligations identified by producer + semantic fee key + resource.

**Why:** a submission can simultaneously have a submission fee and publication fee, with separate waiver/payment/refund states.

**Consequence:** `ojs_get_payment_status` returns a portfolio while remaining one Captain tool.

## ADR-A003 — Separate fee producer from collector/orchestrator

**Decision:** The system that creates an obligation is represented separately from the gateway/orchestrator collecting it.

**Why:** MultiPay, Paystack, Flutterwave or Bachs may collect a fee whose policy is owned by OJS core or Submission Fee. Collapsing those roles causes incorrect support explanations and authorization decisions.

## ADR-A004 — Prefer sibling public contracts to copied business logic

**Decision:** Use official callable methods/hooks/interfaces from Airix sibling plugins before interpreting raw settings/tables or copying algorithms.

**Examples:** Request Waiver `getWaiverDiscount()` and gateway `refundByCompletedPaymentId()`.

**Consequence:** Chatwoot Integration stays aligned as sibling plugins evolve and avoids divergent waiver/refund logic.

## ADR-A005 — OJS ledger remains authoritative where sibling plugins fulfil into it

**Decision:** When a sibling fee/gateway uses OJS queued/completed payment records as its durable fulfillment record, Support Gateway treats that native ledger as primary evidence for fulfillment and adds provider detail rather than replacing it.

**Consequence:** a temporary gateway diagnostics outage does not erase a conclusively completed OJS payment.

## ADR-A006 — Provider failure never means unpaid

**Decision:** Missing/incompatible/failed provider evidence resolves to `unknown` or partial knowledge unless another authoritative source conclusively determines the state.

**Why:** translating an exception into `unpaid` can cause duplicate payment attempts.

## ADR-A007 — Partial waiver semantics require the waiver contract

**Decision:** An approved raw waiver status is not enough to conclude that the full fee is zero when the active waiver provider supports partial discounts.

**Consequence:** use the documented discount method/provider DTO and report the remaining payable amount.

## ADR-A008 — Public Captain never performs money-moving or financial status writes

**Decision:** public Captain may read authorized payment/waiver/refund/dispute state and return safe action URLs, but cannot mark paid/waived, decide a waiver, issue a refund, reconcile, or resolve disputes.

**Consequence:** any such operation belongs to a future staff plane with separate credential, current OJS staff authorization, confirmation, idempotency and audit.

## ADR-A009 — Common Airix refund method is a staff adapter primitive, not authorization

**Decision:** `refundByCompletedPaymentId()` may be used by a future staff financial adapter after Support Gateway authorization.

**Why:** current sibling implementations intentionally assume the caller has already authorized the refund.

## ADR-A010 — Bachs descriptor vocabulary informs but does not own Payment Portfolio

**Decision:** producer/payment key/label/metadata/line-item semantics from Bachs's payment-generator contract are adopted conceptually into the Support Gateway's own provider model.

**Consequence:** custom fee producers can be described consistently even when Bachs is not installed.

## ADR-A011 — Airix sibling plugins are not bundled in the Chatwoot Gallery package

**Decision:** `chatwootIntegration` remains an independent PKP plugin archive. Airix sibling plugins are separately installed optional dependencies/integrations.

**Why:** avoids coupling release/licensing/package identity and preserves core functionality.

## ADR-A012 — Provider SDK is the long-term integration path

**Decision:** hard-coded Airix adapters bootstrap first-party support, but future Airix plugins should be able to register providers through a documented Support Provider hook/API.

**Consequence:** “many more Airix plugins” can be added without expanding Captain's canonical tool count or rewriting Support Core.

## ADR-A013 — Planning repositories are not runtime claims

**Decision:** MailGuard, Subscription Suite, Publication Bridge, Upgrade Manager and any other planning-only Airix project are recorded as future provider families, not advertised as implemented support.

**Consequence:** release docs distinguish architecture readiness from runtime integration.

## ADR-A014 — Airix provider knowledge follows the same classification boundary

**Decision:** an Airix provider may contribute crawlable Captain knowledge only when each fact is explicitly classified public.

**Examples:** fee policy and required file genres may be public; a user's waiver reason, transaction history, contributor-account link and dispute payload are protected.

## ADR-A015 — Exact sibling versions are release evidence

**Decision:** a stable Chatwoot Integration release lists/tests exact compatible Airix plugin versions where enhanced integration is advertised.

**Consequence:** no blanket claim such as “supports all Airix360 plugins” or “supports every version” is allowed.