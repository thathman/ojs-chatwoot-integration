# Airix360 Integration Verification Matrix

Verified: 2026-08-28  
Purpose: evidence for the first-party Airix360 provider requirements added to Chatwoot Integration v2.

This matrix is separate from upstream OJS/Chatwoot verification because sibling plugins have their own release/version lifecycle.

## Status vocabulary

- **VERIFIED CURRENT** — runtime capability observed in current source.
- **VERIFIED CONTRACT** — plugin exposes an intentional cross-plugin integration method/hook/contract.
- **ADAPTER REQUIRED** — source supports the feature, but Chatwoot Integration must build its own provider adapter.
- **FUTURE / PLANNING** — repository exists but current source/readme says runtime implementation is not production-ready or not implemented.
- **INSPECT BEFORE SUPPORT** — known repository, but not verified enough to make a release claim.

## Payment and fee plugins

| Repository | Snapshot observed | Status | Verified support-relevant capability | v2 provider implication |
|---|---|---|---|---|
| `Airix360/submissionFee-OJS` | `ef955e611fe965af9062f00f66b3ad89899755c8` | VERIFIED CURRENT + VERIFIED CONTRACT | native OJS submission payment type; completed-payment lookup; hard block/hold mode; payable amount; full/partial waiver integration; pay URL; refund/review flags; calls gateway `refundByCompletedPaymentId()` when present | `airix.submission_fee`; Payment Portfolio fee producer |
| `Airix360/ojs-request-waiver` | `4a189cc5ab3f7f53920ac73170f59877d610638f` | VERIFIED CONTRACT | official `getWaiverDiscount(submissionId)` returns full/partial/null; supports submission or publication fee context | `airix.request_waiver`; adjustment provider; never infer partial waiver from raw status alone |
| `Airix360/paymethodSupportOJS` | `f6b949518b0a5ffd5b69c08d523aa319336cb9c5` | VERIFIED CURRENT | unified per-submission native publication fee + submission fee + waiver status; relationship/role checks; staff paid/waived/unpaid operation; payment manager/history integrations | `airix.paymethod_support`; reuse/refactor semantics but do not proxy browser handler as Support API |
| `Airix360/PaystackOJS` | `d551cd3bad341c2ba0a3ebe8a511499eb48c2638` | VERIFIED CURRENT + VERIFIED CONTRACT | transaction/reconciliation tracking; refunds; disputes; stable in-process `refundByCompletedPaymentId()` | gateway/provider status adapter; staff refund capability only after Support Gateway authorization |
| `Airix360/FlutterwaveOJS` | `ca0955c1c67cb07c9193097e1513fcb97cb6c358` | VERIFIED CURRENT + VERIFIED CONTRACT | checkout/verification/reconciliation; subscription checkout bridging; refunds; stable in-process `refundByCompletedPaymentId()` | gateway/provider status adapter; staff refund capability later |
| `Airix360/BachsOJS` | `f353e1fa262578f07adb5ccc3df623afdacd2ae0` | VERIFIED CURRENT + VERIFIED CONTRACT | OJS queued-payment processing; transactions/disputes/refunds; stable internal refund method; payment-generator descriptor and custom-fulfilment hooks | gateway adapter + descriptor semantics for generalized fee producers |
| `Airix360/ojs-multipay` | `c57d986e0c2c6eddf25dc658e0119dcca7c2b6ef` | VERIFIED CURRENT + VERIFIED CONTRACT | multi-gateway orchestration; gateway registry/adapters; payment/refund/dispute/reconciliation; recurring profiles; stable `refundByCompletedPaymentId()` | collector/orchestrator provider, not necessarily fee producer |

## Specific verified payment claims

### Submission fee is not the same as publication/APC fee — VERIFIED

`submissionFee-OJS` uses OJS `PAYMENT_TYPE_SUBMISSION` and associates completed payment with the submission. Native OJS publication fees use the publication payment type. v2 must therefore represent them as separate obligations.

### Full and partial waivers — VERIFIED

`ojs-request-waiver` exposes a documented `getWaiverDiscount()` integration method. Partial approval retains an approved status but carries a percentage. Any consumer that treats `approved` as automatically zero owed would be wrong.

### Submission Fee already consumes the waiver contract — VERIFIED

Current `PaymentHelper` resolves `requestwaiverplugin` and calls `getWaiverDiscount()` when available, then uses the resulting partial discount in payable amount.

### Submission Fee can auto-refund through sibling gateways — VERIFIED WITH AUTHORIZATION CAVEAT

Current Submission Fee resolves the active paymethod plugin and calls `refundByCompletedPaymentId()` only when the method exists. The gateway methods are internal/non-HTTP conveniences and assume their caller already authorized the action. Chatwoot Integration must never call them from public Captain without staff-plane authorization.

### Common refund convention exists across Airix gateways — VERIFIED

Current Paystack, Flutterwave, Bachs and MultiPay expose the same broad method signature based on OJS completed-payment ID. v2 may normalize this behind a staff financial action adapter after explicit security implementation.

### Bachs payment-generator descriptor contract — VERIFIED

Bachs documents `Payment::describeQueuedPayment` with producer/payment key/label/description/completion mode/metadata/line items and a custom fulfilment hook. v2 may adopt compatible semantic vocabulary without depending on Bachs.

### MultiPay is an orchestrator, not necessarily the obligation owner — VERIFIED

MultiPay takes the journal's authoritative payment amount/currency and routes collection to gateways. v2 must separately model fee producer and collector/orchestrator.

## Contributor/account/submission-support plugins

| Repository | Snapshot observed | Status | Verified support-relevant capability | v2 provider implication |
|---|---|---|---|---|
| `Airix360/contributorUserSync` | `93e0968d3c6b6250631c49ca262fe22c5c520268` | VERIFIED CURRENT | contributor↔user linking; confirmation state; verified ORCID reuse; invitations; contributor-count submission gate; explicit confirmation safety | contributor provider and submission diagnostic; no cross-user lookup/automatic merge |
| `Airix360/ojs-magic-login` | `9f4b38b68eb33d2f25a493c5750c3edd36b04e63` | VERIFIED CURRENT | one-time passwordless link; anti-enumeration response; atomic consume; rate limiting | account-support provider; expose availability/request URL, not account existence/token state |
| `Airix360/ojs-required-submission-files-airix` | `0a26951cdf1fa6b34fc2c2e7838b2c3a6a69bbd6` | VERIFIED CURRENT | required file genres; server-side submit validation | knowledge + missing-requirement diagnostic/action provider |
| `Airix360/ojs-visibility-suite` | current main inspected | VERIFIED CURRENT | structured data, sitemap/robots/llms.txt, AI crawler controls, indexing/SEO configuration | optional public knowledge/indexing diagnostic provider |

## Known future/conditional Airix360 projects

| Repository | Current status observed | v2 treatment |
|---|---|---|
| `Airix360/OJS-MailGuard` | product planning/spec; no production implementation claimed | FUTURE / PLANNING; design provider slot for mail health/events later |
| `Airix360/OJS-Subscription-Suite` | planning/spec; no production release | FUTURE / PLANNING; Payment Portfolio designed to extend to subscriptions/orders later |
| `Airix360/OJS-Publication-Bridge` | planning repository | FUTURE / PLANNING |
| `Airix360/OJS-Upgrade-Manager` | planning baseline; no production code yet | FUTURE / PLANNING; future upgrade-readiness provider |
| `Airix360/OJS-Editorial-Workspace` | not deeply verified in this pass | INSPECT BEFORE SUPPORT |
| `Airix360/OJS-Custom-Editorial-Decisions` | not deeply verified in this pass | INSPECT BEFORE SUPPORT; high privacy/staff risk |
| `Airix360/newsletterSync` | not deeply verified in this pass | INSPECT BEFORE SUPPORT |
| `Airix360/conferenceSuite` | not deeply verified in this pass | INSPECT BEFORE SUPPORT |
| `Airix360/OJS-Advanced-Anouncements` | repository known; README not resolved in this pass | INSPECT BEFORE SUPPORT |
| `Airix360/ojs-schema-org` | known overlapping semantic area with Visibility Suite | INSPECT BEFORE SUPPORT; overlap/conflict detection required |
| `Airix360/ojs-sentinel` | not deeply verified in this pass | INSPECT BEFORE SUPPORT |
| `Airix360/pln` | not deeply verified in this pass | INSPECT BEFORE SUPPORT |

## Design conclusions frozen by this verification

1. **Payment support is a portfolio, not one APC status.**
2. **Airix360 sibling plugins are optional providers, not bundled dependencies.**
3. **Use documented cross-plugin contracts before reading internals.**
4. **OJS native payment ledger remains authoritative wherever sibling plugins intentionally fulfil into it.**
5. **Provider/gateway failure returns `unknown`; it must not be translated to `unpaid`.**
6. **Public Captain financial actions stay read-only/redirect-oriented.**
7. **Money-moving/status-changing actions belong to staff plane with fresh authorization and audit.**
8. **Future Airix360 plugins can register themselves through the Provider SDK rather than requiring the Chatwoot plugin to know them in advance.**
9. **No blanket “supports every Airix360 plugin” claim until each provider is implemented/tested.**
10. **First-party Airix provider versions are part of the compatibility matrix, not incidental dependencies.**

## Re-verification rule

Before a stable v2 release that advertises any Airix integration:

- resolve the sibling plugin's actual release/tag/version;
- test against that exact release, not only `main`;
- verify its public integration methods/signatures still match;
- record fixture/contract tests;
- downgrade adapter health to incompatible/unknown on unrecognized versions where semantic breakage is possible;
- update this matrix and release notes.