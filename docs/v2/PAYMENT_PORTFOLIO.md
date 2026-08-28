# v2 Payment Portfolio — Multi-Fee, Waiver, Gateway and Refund Model

Status: **accepted refinement of Product Bible payment model**  
This document refines `PRODUCT_BIBLE.md`, `ARCHITECTURE.md` and `API_MCP_SPEC.md` wherever they describe payment as a single publication-fee status.

## 1. Why the payment model is a portfolio

A manuscript/user relationship may have more than one financial obligation over its lifecycle:

- submission fee;
- publication/APC fee;
- subscription purchase or renewal;
- article/issue purchase;
- future plugin-defined scholarly service fees;
- future print/reprint/proof/fulfilment charges.

Each obligation may independently be:

- not required;
- unpaid;
- queued/pending;
- paid;
- fully waived;
- partially waived with a remaining balance;
- failed;
- refunded or awaiting refund;
- disputed;
- unknown because a provider is degraded.

Therefore v2 MUST NOT collapse all financial state into one `paid: true|false` value.

## 2. Separation of concerns

Every financial item has distinct roles:

### Producer

The system/plugin that creates the obligation and knows why it exists.

Examples:

- OJS core publication fee;
- Airix Submission Fee;
- OJS subscription purchase;
- future Subscription Suite order.

### Ledger/system of record

The authoritative durable record that establishes whether an obligation has been completed/waived.

For native-compatible OJS payments, this is normally OJS queued/completed payment state.

### Collector/orchestrator

The payment method/orchestrator responsible for collection.

Examples:

- Paystack;
- Flutterwave;
- Bachs;
- MultiPay;
- Manual Payment.

### Adjustment provider

A separate system may alter the amount owed.

Example: Request Waiver applies a full or partial waiver to a submission/publication fee.

The Support Gateway must keep these roles separate so an answer can correctly say, for example:

> A NGN 10,000 submission fee was configured by Submission Fee. A 50% waiver was approved, so NGN 5,000 remains due. Payment will be collected through MultiPay/Paystack.

## 3. Canonical identifiers

Each obligation has stable normalized identifiers:

- `producer` — e.g. `ojs_core`, `submissionfeeplugin`, future provider ID;
- `feeKey` — semantic key such as `publication_fee`, `submission_fee`, `subscription_renewal`;
- `resourceType` — `submission`, `subscription`, `issue`, `article`, `order`, etc.;
- `resourceId` — internal identifier, returned only where policy permits;
- `paymentType` — OJS/native/provider numeric type when relevant, treated as internal provenance rather than conversational vocabulary.

Recommended core fee keys:

- `publication_fee`
- `submission_fee`
- `subscription_purchase`
- `subscription_renewal`
- `article_purchase`
- `issue_purchase`
- `membership`
- provider-defined namespaced keys for future obligations.

## 4. Canonical fee states

The normalized state enum MUST be expressive enough to avoid lying:

- `not_applicable`
- `not_required`
- `unpaid`
- `pending`
- `paid`
- `waived`
- `partially_waived`
- `failed`
- `refund_pending`
- `refunded`
- `refund_review`
- `disputed`
- `unknown`

Provider adapters may hold richer internal states, but Captain/public API receives normalized state plus safe evidence/reason codes.

### State precedence

When multiple facts exist for one obligation, apply an explicit resolver rather than whichever provider responds last.

Example priorities may be:

1. active dispute/refund state if it materially changes support action;
2. completed/waived authoritative ledger state;
3. queued/pending state;
4. current waiver adjustment;
5. configured requirement;
6. `unknown` on conflicting/incomplete evidence.

The exact precedence table is implementation-tested and provider-aware.

## 5. Canonical waiver object

```json
{
  "status": "none|pending|approved|denied|unknown",
  "type": "none|full|partial|unknown",
  "percent": 50,
  "amount": null,
  "remainingAmount": 5000,
  "canRequest": true,
  "requestUrl": "..."
}
```

Rules:

- a pending request does not satisfy a fee gate;
- full approval may reduce payable amount to zero;
- partial approval reduces payable amount but leaves a balance;
- `waiverStatus=approved` alone is insufficient when the provider supports partial waivers — use the provider's official discount contract;
- reason/history/decision notes are not part of the default public object.

## 6. Canonical gateway object

```json
{
  "collector": "multipay",
  "gateway": "paystack",
  "displayName": "Paystack",
  "environment": "live|test|unknown",
  "transactionState": "pending|confirmed|failed|refunded|disputed|unknown",
  "reconciliationState": "not_applicable|pending|confirmed|needs_attention|unknown"
}
```

Do not expose:

- API keys/secrets;
- reusable authorization codes;
- raw webhook bodies;
- card PAN/BIN/last-four unless an explicit staff support use case is separately approved;
- provider payload dumps;
- internal signature material.

## 7. Canonical obligation object

Illustrative protected response:

```json
{
  "producer": "submissionfeeplugin",
  "feeKey": "submission_fee",
  "label": "Submission fee",
  "required": true,
  "configuredAmount": 10000,
  "payableAmount": 5000,
  "currency": "NGN",
  "status": "partially_waived",
  "waiver": {
    "status": "approved",
    "type": "partial",
    "percent": 50,
    "amount": null,
    "remainingAmount": 5000,
    "canRequest": false,
    "requestUrl": null
  },
  "gateway": {
    "collector": "multipay",
    "gateway": "paystack",
    "displayName": "Paystack",
    "transactionState": "pending"
  },
  "payUrl": "...",
  "actionRequired": true,
  "safeReasonCode": "PAYMENT_REQUIRED",
  "provenance": ["airix.submission_fee", "airix.request_waiver"]
}
```

A native publication fee is another item in the same array, not a field that overwrites this item.

## 8. Payment Portfolio response

`ojs_get_payment_status` remains the single canonical Captain tool to conserve Captain's custom-tool budget, but its response becomes a portfolio.

```json
{
  "submissionId": 142,
  "summary": {
    "financialState": "payment_required",
    "actionRequired": true,
    "outstandingCount": 1,
    "unknownCount": 0
  },
  "fees": [
    {
      "producer": "ojs_core",
      "feeKey": "publication_fee",
      "label": "Publication fee",
      "required": true,
      "configuredAmount": 250,
      "payableAmount": 250,
      "currency": "USD",
      "status": "unpaid"
    },
    {
      "producer": "submissionfeeplugin",
      "feeKey": "submission_fee",
      "label": "Submission fee",
      "required": true,
      "configuredAmount": 10000,
      "payableAmount": 5000,
      "currency": "NGN",
      "status": "partially_waived",
      "waiver": {
        "status": "approved",
        "type": "partial",
        "percent": 50,
        "remainingAmount": 5000
      }
    }
  ]
}
```

### Summary states

Suggested aggregate `financialState` values:

- `no_payment_required`
- `payment_required`
- `payment_pending`
- `all_satisfied`
- `attention_required`
- `partially_unknown`
- `unknown`

The aggregate must never hide an unknown provider behind an optimistic `all_satisfied` result.

## 9. Public knowledge vs protected portfolio

### Public knowledge

May include journal-configured policies such as:

- “Submission fee: NGN 10,000.”
- “Publication fee: USD 250.”
- waiver eligibility/instructions where intentionally public;
- accepted gateway names where intentionally public.

### Protected live state

Requires verified relationship to the resource:

- whether this submission paid;
- exact waiver status;
- current amount remaining after partial waiver;
- transaction/refund/dispute state;
- payer-specific history;
- personalized pay/retry URL.

## 10. Safe user actions

The public plane may return action descriptors, not execute privileged financial mutations.

Examples:

- `pay_fee`
- `retry_payment`
- `request_waiver`
- `view_payment_history`
- `contact_finance_office`
- `wait_for_payment_confirmation`
- `wait_for_refund`

Each action may carry an OJS-generated safe URL if the provider supports it and the verified user is allowed to use it.

The public plane MUST NOT expose:

- `mark_paid`
- `mark_waived`
- `approve_waiver`
- `deny_waiver`
- `refund_payment`
- `resolve_dispute`
- `reconcile_payment`

unless a future staff-only endpoint/credential explicitly authorizes those actions.

## 11. Staff financial action plane

Potential future staff capabilities are deliberately separate:

- `payment.staff_set_status`
- `payment.staff_approve_waiver`
- `payment.staff_deny_waiver`
- `payment.staff_partial_waiver`
- `payment.staff_refund`
- `payment.staff_reconcile`
- `payment.staff_resolve_dispute`

Requirements for every money-moving/status-changing action:

1. staff consumer credential;
2. current OJS staff identity/role authorization;
3. context/resource ownership validation;
4. capability check;
5. server-derived amount/gateway/resource data;
6. explicit confirmation for destructive/money-moving operations by default;
7. idempotency key;
8. complete audit event;
9. provider's supported method/API — never direct arbitrary DB mutation where a business contract exists.

For Airix gateways, the emerging `refundByCompletedPaymentId()` convention is a potential staff adapter target, but it performs no caller authorization itself. Support Gateway must authorize before invoking it.

## 12. Payment producer descriptors

The Payment Portfolio adopts a generalized descriptor inspired by the verified Bachs payment-generator contract:

```json
{
  "producer": "my_fee_plugin",
  "paymentKey": "handling_fee",
  "label": "Handling Fee",
  "description": "Editorial handling fee",
  "completionMode": "record_only",
  "metadata": {
    "submissionId": 142
  },
  "lineItems": [
    {
      "key": "handling_fee",
      "label": "Handling Fee",
      "amount": "5000.00",
      "currency": "NGN"
    }
  ]
}
```

The Support Gateway owns its normalized descriptor interface. Bachs is one adapter/source, not a required dependency.

A future Airix fee-generator plugin should ideally expose/implement enough metadata for the Support Gateway to identify:

- producer;
- semantic fee key;
- label/description;
- resource association;
- amount/currency;
- completion semantics;
- public/support-safe metadata.

## 13. Unknown/conflict rules

Financial answers are high-impact enough that uncertainty must be explicit.

Examples:

- gateway API unavailable but OJS completed payment exists → ledger may still safely establish `paid`; gateway detail can be `unknown`;
- no completed payment and gateway provider is unavailable → do not automatically say `unpaid` if a pending/reconciliation path could exist;
- waiver plugin version incompatible → do not assume an `approved` raw status means full waiver;
- conflicting amount/currency between producer and queued payment → `attention_required`/staff diagnostic, not a conversational guess.

## 14. Diagnostics

`payment` diagnostics may evaluate:

- fee configured but no gateway configured;
- payment queued but not completed;
- gateway callback/webhook pending;
- reconciliation pending/failed;
- amount/currency mismatch where deterministically detectable;
- waiver pending/partial/full;
- refund pending/review/error;
- open dispute;
- test-mode gateway active in a production journal, where detectable and appropriate;
- provider unavailable/incompatible.

Public diagnostics give safe explanations/actions. Staff diagnostics may include provider IDs, internal payment IDs and safe reason codes, but still no secrets/raw sensitive payloads.

## 15. Events

Normalized financial events include:

- `payment.obligation_created`
- `payment.required`
- `payment.queued`
- `payment.completed`
- `payment.failed`
- `payment.waived`
- `payment.partial_waiver`
- `payment.refund_requested`
- `payment.refunded`
- `payment.refund_needs_review`
- `payment.disputed`
- `payment.reconciled`
- `waiver.requested`
- `waiver.approved`
- `waiver.denied`

Events carry producer/fee key/resource identifiers and safe state, not gateway secrets.

## 16. MCP payment surface

MCP may expose more granular tools than Captain:

- `payment.list_obligations`
- `payment.get_obligation`
- `payment.get_waiver_status`
- `payment.get_gateway_status`
- `payment.get_safe_actions`
- `payment.diagnose`

Future staff namespace:

- `payment.staff.set_status`
- `payment.staff.decide_waiver`
- `payment.staff.refund`
- `payment.staff.reconcile`

All tools still use the same Policy Engine/providers.

## 17. Tests required

At minimum:

- native APC only;
- Airix submission fee only;
- APC + submission fee simultaneously;
- no waiver;
- pending waiver;
- full waiver;
- partial waiver and remaining amount;
- completed payment;
- queued payment;
- failed payment;
- refund success;
- refund pending/review;
- dispute;
- gateway/orchestrator absent;
- provider exception;
- incompatible sibling plugin;
- cross-journal isolation;
- author A cannot query author B financial portfolio;
- public Captain cannot invoke staff write/refund/waiver-decision actions.

## 18. Compatibility rule

Payment provider compatibility is versioned independently. A v2 release may support a specific set of Airix payment plugin versions, and unsupported versions must degrade to `incompatible_version`/`unknown` rather than being guessed compatible.

The product may add new fee producers and gateways without changing the Captain tool count because the portfolio is provider-driven behind one canonical payment-status tool.