# Airix360 OJS Integration Family — v2 First-Party Provider Specification

Status: **accepted v2 product requirement**  
Applies to: `chatwootIntegration` v2 / OJS Support Gateway  
Development branch: `v2-dev`  
Verified: 2026-08-28

## 1. Requirement

Chatwoot Integration for OJS v2 MUST treat the Airix360 OJS plugin ecosystem as a first-class optional integration family.

The Support Gateway must not be limited to vanilla OJS publication/APC payments. It must be able to understand and safely expose support-relevant state contributed by Airix360 plugins, including payment generators, payment gateways/orchestrators, fee waivers, contributor-account linking, submission requirements, account-access helpers and future OJS extensions.

This does **not** mean `chatwootIntegration` may hard-depend on or bundle every Airix360 plugin. The core plugin remains independently installable. Integrations are capability-detected at runtime and fail closed/degrade gracefully when a sibling plugin is absent or incompatible.

## 2. Architectural rule: adapters, not table scraping

Preferred integration order for an Airix360 sibling plugin:

1. **Published plugin contract/interface/method/hook** — use the plugin's supported integration point when one exists.
2. **Native OJS system-of-record state** — use OJS repositories/payment ledgers when the sibling plugin intentionally records its outcome there.
3. **Versioned first-party adapter** — read stable plugin settings/state through a narrowly scoped adapter when no public contract exists.
4. **Direct plugin table access only as a last resort** — allowed only inside a version-scoped adapter with explicit compatibility tests and safe `unknown` fallback.

Never duplicate gateway secrets, payment credentials, waiver business rules or contributor-matching rules inside Chatwoot Integration.

## 3. Runtime discovery

The Provider Registry must discover optional sibling plugins through OJS/PKP plugin facilities such as `PluginRegistry`, plugin enabled state and explicit provider registration hooks.

A provider advertises at minimum:

- provider ID;
- provider semantic/version contract;
- owning plugin name/category;
- supported OJS adapter versions;
- journal/context applicability;
- capabilities supplied;
- knowledge classifications supplied;
- diagnostic checks supplied;
- event types supplied;
- health/compatibility state.

Provider health states:

- `available`
- `disabled`
- `not_installed`
- `incompatible_version`
- `degraded`
- `unavailable`
- `unknown`

Absence of a provider must not break OJS page rendering, core Chatwoot widget support, or unrelated providers.

## 4. Provider SDK additions

The v2 Provider SDK shall support the existing conceptual provider contracts plus specialized optional contracts:

```php
interface KnowledgeProviderInterface {}
interface CapabilityProviderInterface {}
interface DiagnosticProviderInterface {}
interface EventProviderInterface {}

interface PaymentSupportProviderInterface {}
interface AccountSupportProviderInterface {}
interface SubmissionRequirementProviderInterface {}
interface ContributorSupportProviderInterface {}
```

A plugin does not need to implement every interface. One plugin may register multiple provider facets.

### 4.1 PaymentSupportProviderInterface

Conceptual responsibilities:

- enumerate fee/payment obligations applicable to a journal/resource;
- provide public fee policy facts;
- resolve authorized user-specific payment state;
- expose safe next actions/URLs;
- expose waiver/refund/dispute/reconciliation state where supported;
- expose staff-only mutations separately, never implicitly through the public plane;
- identify provenance and authoritative source.

The canonical normalized model is defined in `PAYMENT_PORTFOLIO.md`.

### 4.2 AccountSupportProviderInterface

May provide safe account-help capabilities such as:

- feature availability;
- support-safe recovery action URLs;
- deterministic diagnostic facts;
- rate-limit/expiry state only when doing so does not enable enumeration or abuse.

### 4.3 SubmissionRequirementProviderInterface

May provide:

- public submission requirements;
- authorized per-submission missing requirement diagnostics;
- normalized `action_required` entries;
- server-side enforcement provenance.

### 4.4 ContributorSupportProviderInterface

May provide relationship-safe contributor facts such as:

- whether the requesting user is linked to their contributor record;
- whether contributor confirmation is pending;
- whether a contributor-count requirement is satisfied;
- safe ORCID connection state.

It must not become a cross-user identity lookup or automatic account-merge interface.

## 5. Verified Airix360 payment family

### 5.1 Native OJS publication/APC provider

Owner: OJS core.

Purpose:

- configured publication fee and currency;
- completed publication payment by submission;
- paid/waived/unpaid state;
- queued/pending state where safely resolvable.

This remains one payment producer inside the broader Payment Portfolio; it is no longer treated as the only payment model.

Provider ID: `ojs.core.publication_payment`.

### 5.2 Airix Submission Fee

Repository: `Airix360/submissionFee-OJS`  
Observed main snapshot: `ef955e611fe965af9062f00f66b3ad89899755c8`

Verified behavior:

- uses OJS `PAYMENT_TYPE_SUBMISSION` and OJS completed-payment records;
- supports `hardBlock` and `holdUntilPaid` enforcement modes;
- exposes configured amount/currency;
- supports full and partial waivers through the Request Waiver plugin;
- calculates a per-submission payable amount after partial waiver;
- exposes author pay URL;
- tracks outstanding, email-failure, refund-review, refunded, refund-reference and refund-error state on the submission;
- can call an active gateway's stable in-process `refundByCompletedPaymentId()` when supported.

Provider ID: `airix.submission_fee`.

Support Gateway policy:

- public knowledge may state the journal's configured submission-fee policy;
- an authorized author may read their own required/payable amount, fee status, waiver effect, pay URL and safe refund state;
- public Captain may not alter the fee, mark it paid, approve a waiver or issue a refund;
- provider failure returns `unknown`, not `unpaid`.

Preferred implementation: instantiate/use the sibling plugin's supported service/helper behavior where safe, or reproduce only the normalized read through OJS system-of-record payment state + plugin contract. Do not copy its waiver calculation logic into Chatwoot Integration.

### 5.3 Airix Request Waiver

Repository: `Airix360/ojs-request-waiver`  
Observed main snapshot: `4a189cc5ab3f7f53920ac73170f59877d610638f`

Verified official integration point:

```php
RequestWaiverPlugin::getWaiverDiscount(int $submissionId): ?array
```

Normalized outcomes:

- no approved waiver: `null`;
- full waiver: `type=full`;
- partial percentage waiver: `type=partial`, with percent.

The plugin determines whether the active fee is the Airix submission fee or native OJS publication fee.

Provider ID: `airix.request_waiver`.

Support Gateway policy:

- use the official method when available instead of interpreting `waiverStatus` alone;
- expose pending/approved/denied and full/partial effect only to an authorized relationship;
- waiver reason/history may contain sensitive author/editor text and is not automatically public-plane output;
- public plane may expose `request_waiver` as an available action/link when journal policy/provider permits;
- approval/denial/partial approval is staff-plane only.

### 5.4 Airix Payment Method Support

Repository: `Airix360/paymethodSupportOJS`  
Observed main snapshot: `f6b949518b0a5ffd5b69c08d523aa319336cb9c5`

Verified behavior:

- supports Paystack, Flutterwave, Bachs and MultiPay integration in its gateway registry;
- already builds a unified per-submission view containing native publication payment, Airix submission fee and waiver state;
- author/editor access is relationship/role checked;
- editor-only `setFeeStatus` supports publication/submission paid/waived/unpaid semantics with CSRF protection;
- provides payment history/manager UI integration.

Provider ID: `airix.paymethod_support`.

v2 rule: do not make the Support Gateway's public API a transparent proxy to the existing browser handler. Reuse its semantics/contracts or refactor shared read services later. The Support Gateway must apply its own service authentication, support-session relationship policy and field serializers.

### 5.5 Paystack OJS

Repository: `Airix360/PaystackOJS`  
Observed main snapshot: `d551cd3bad341c2ba0a3ebe8a511499eb48c2638`

Verified capabilities relevant to support:

- OJS paymethod integration;
- test/live configuration;
- transaction tracking and scheduled reconciliation;
- refunds with local records and cumulative-refund guard;
- dispute/chargeback capture;
- payer/admin mailables;
- stable internal `refundByCompletedPaymentId()` entry point.

Provider ID: `airix.paystack`.

Public-plane exposure is read-only and sanitized: gateway label, safe status, reconciliation state where useful, refund/dispute state where relationship permits. Never expose keys, authorization codes, card metadata, raw webhooks or provider payloads.

### 5.6 Flutterwave OJS

Repository: `Airix360/FlutterwaveOJS`  
Observed main snapshot: `ca0955c1c67cb07c9193097e1513fcb97cb6c358`

Verified capabilities relevant to support include hosted checkout, payment verification, reconciliation, subscription checkout bridging, refunds and an internal `refundByCompletedPaymentId()` entry point.

Provider ID: `airix.flutterwave`.

Public-plane restrictions match Paystack: expose normalized state, not provider secrets/raw payloads.

### 5.7 Bachs OJS

Repository: `Airix360/BachsOJS`  
Observed main snapshot: `f353e1fa262578f07adb5ccc3df623afdacd2ae0`

Verified capabilities:

- processes valid OJS `QueuedPayment` objects;
- transaction/dispute/refund history;
- payer payment history;
- internal `refundByCompletedPaymentId()`;
- explicit payment-generator compatibility contract.

Bachs documents an optional descriptor hook:

`Payment::describeQueuedPayment`

with useful semantics such as:

- `producer`
- `paymentKey`
- `label`
- `description`
- `completionMode`
- `metadata`
- `lineItems`

It also documents `Payment::fulfillCustomQueuedPayment` for plugin-owned custom payment types.

Provider ID: `airix.bachs`.

The v2 Payment Portfolio adopts the **concept** of producer/payment key/line-item provenance because it is a clean cross-plugin vocabulary. Chatwoot Integration must not require Bachs to be installed for that model to exist.

### 5.8 Airix MultiPay

Repository: `Airix360/ojs-multipay`  
Observed main snapshot: `c57d986e0c2c6eddf25dc658e0119dcca7c2b6ef`

Verified behavior:

- orchestrates multiple gateway choices while charging the authoritative OJS amount/currency;
- delegates to/works with Paystack, Flutterwave, Bachs, PayPal/manual and installed paymethods as applicable;
- has payment/refund/dispute/reconciliation state;
- supports recurring profiles with real auto-charge currently limited by gateway/token capability;
- exposes stable `refundByCompletedPaymentId()`;
- has a GatewayAdapter interface for supported gateways.

Provider ID: `airix.multipay`.

Support Gateway should distinguish:

- **fee producer** — what the user owes and why;
- **orchestrator/gateway** — how the obligation was/will be collected.

MultiPay is normally a collector/orchestrator, not the authoritative producer of an APC/submission fee.

## 6. Cross-plugin payment contract recognized by v2

Airix payment plugins already converge on several useful conventions:

1. OJS `QueuedPayment` / completed-payment ledger remains the compatibility backbone.
2. fee producers identify the OJS payment type/resource association.
3. waiver logic has a callable public integration method.
4. multiple gateways expose the same internal refund signature:

```php
refundByCompletedPaymentId(
    int $contextId,
    int $completedPaymentId,
    ?float $amount = null
): array
```

with a result shaped around `success`, `reference`, `error`.
5. Bachs provides a producer descriptor/fulfilment hook vocabulary suitable for future custom fee generators.

v2 will normalize these conventions behind Support Core providers rather than exposing plugin implementation names directly to Captain prompts.

## 7. Non-payment Airix360 first-party adapters

### 7.1 Contributor User Sync

Repository: `Airix360/contributorUserSync`  
Observed main snapshot: `93e0968d3c6b6250631c49ca262fe22c5c520268`

Verified support-relevant state:

- contributor ↔ OJS user linkage;
- confirmation/pending match state;
- verified ORCID reuse state;
- contributor-count requirement;
- invitation/confirmation workflows.

Provider ID: `airix.contributor_user_sync`.

Potential safe capabilities:

- `submission.read_own_contributor_status`
- `submission.read_own_contributor_requirements`
- `submission.diagnose_contributor_count`

Do not expose another contributor's account linkage or allow Captain to merge/link accounts automatically.

### 7.2 OJS Magic Login

Repository: `Airix360/ojs-magic-login`  
Observed main snapshot: `9f4b38b68eb33d2f25a493c5750c3edd36b04e63`

Verified support-relevant behavior:

- one-time passwordless sign-in links;
- selector/verifier secret design;
- atomic single-use consumption;
- anti-enumeration neutral send response;
- rate limiting and short expiry.

Provider ID: `airix.magic_login`.

Safe support capabilities:

- advertise that passwordless sign-in is available for the journal;
- provide the public magic-login request URL;
- account diagnostic may recommend that flow;
- never reveal whether a supplied email exists, active tokens, token values or account-specific activity to an unverified caller.

### 7.3 Required Submission Files

Repository: `Airix360/ojs-required-submission-files-airix`  
Observed main snapshot: `0a26951cdf1fa6b34fc2c2e7838b2c3a6a69bbd6`

Verified behavior:

- journal can configure required file genres;
- server-side `Submission::validateSubmit` blocks completion when missing;
- client UI is only a hint; server-side validation is authoritative.

Provider ID: `airix.required_submission_files`.

Safe capabilities:

- public knowledge: configured required file categories where journal treats them as submission guidance;
- protected diagnostic: list which required genres are missing from the verified user's submission;
- required-actions integration.

### 7.4 Visibility & Indexing Suite

Repository: `Airix360/ojs-visibility-suite`.

Verified current scope includes SEO metadata, structured data, sitemap/robots/llms.txt and AI-crawler visibility controls.

Provider ID: `airix.visibility_suite`.

Potential support contributions:

- indexing/readiness public facts;
- sitemap/robots/llms.txt route/enablement status;
- safe deterministic SEO/indexing diagnostics;
- no claim that crawler configuration guarantees indexing or AI citation.

## 8. Airix360 repositories recorded as future/conditional providers

The following known Airix360 OJS projects may become providers when runtime contracts are implemented and verified. Their existence alone is not a v2 release claim:

- `OJS-MailGuard` — currently planning/spec; future mail delivery/control-plane diagnostics/events.
- `OJS-Subscription-Suite` — currently planning/spec; future subscription entitlement/billing/order/fulfilment portfolio.
- `OJS-Publication-Bridge` — currently planning; future syndication/migration state.
- `OJS-Upgrade-Manager` — currently planning; future safe upgrade health/readiness state.
- `OJS-Editorial-Workspace` — adapter only after runtime contract inspection.
- `OJS-Custom-Editorial-Decisions` — adapter only after runtime contract inspection; staff/editorial privacy rules apply.
- `newsletterSync` — adapter only after runtime contract inspection.
- `conferenceSuite` — adapter only after runtime contract inspection.
- `OJS-Advanced-Anouncements` — adapter only after runtime contract inspection.
- `ojs-schema-org` — potential overlap with Visibility Suite must be detected; never emit duplicate knowledge claims blindly.
- `ojs-sentinel` — adapter only after runtime contract inspection.
- `pln` — adapter only after runtime contract inspection.

The Provider SDK is intentionally designed so adding these later does not require redesigning Captain tools or the Support API.

## 9. Airix360 integration hook for future plugins

v2 should expose a stable registration hook/API so a sibling plugin can register its provider without `chatwootIntegration` adding a hard-coded adapter.

Conceptual example:

```php
Hook::add('ChatwootIntegration::SupportProviders', function ($hook, $args) {
    $registry = $args[0];
    $registry->register(new MyPluginSupportProvider());
    return Hook::CONTINUE;
});
```

The exact hook signature is implementation work and must be versioned/documented before release.

A provider must not receive Chatwoot credentials or bypass Support Core policy. It returns domain facts/actions to Support Core, which applies identity/relationship/capability checks and serialization.

## 10. Knowledge Compiler integration

Airix providers can contribute only explicitly classified knowledge.

Examples:

- submission fee amount/policy → public if journal config treats it as public;
- waiver policy/instructions → public;
- required file genres → public submission guidance;
- Magic Login availability → public account guidance;
- Visibility Suite indexing policy → public where appropriate.

Never publish:

- a user's payment history;
- waiver reason/history;
- contributor-account link details;
- refund/dispute provider payloads;
- gateway secrets;
- private subscription/customer records;
- internal diagnostics/audit trails.

## 11. Event integration

Airix providers may emit normalized Support Events, for example:

- `payment.required`
- `payment.completed`
- `payment.failed`
- `payment.refunded`
- `payment.disputed`
- `waiver.requested`
- `waiver.approved`
- `waiver.denied`
- `contributor.confirmation_required`
- `contributor.confirmed`
- `submission.requirement_missing`
- `account.magic_login_requested` (only if safe/necessary; never include token)

Events pass through the same delivery policy and privacy filter as core OJS events.

## 12. Compatibility policy

Airix provider compatibility is explicit and versioned separately from OJS compatibility.

A Chatwoot Integration release may say, for example:

- tested with `submissionFee-OJS 1.7.0.0`;
- tested with `ojs-request-waiver 1.3.0.0`;
- tested with `paymethodSupportOJS 1.8.0.0`;
- tested with specific Paystack/Flutterwave/Bachs/MultiPay releases.

Do not publish a blanket statement that every current/future Airix360 plugin is supported.

Each adapter must:

- detect absent/incompatible sibling version;
- return safe `FEATURE_UNAVAILABLE` / `PROVIDER_UNAVAILABLE` or `unknown` state;
- never fatal the parent OJS request;
- have contract fixtures/tests for supported sibling versions.

## 13. Release/packaging boundary

`chatwootIntegration` must not vendor Airix360 sibling plugin source into its PKP Gallery archive merely to enable integration.

The Gallery package remains one independent `chatwootIntegration` plugin. Optional siblings are installed separately.

Documentation may list enhanced integrations, but core widget/support functionality must operate when no Airix sibling plugin is installed.

## 14. Product promise

The user-facing product claim may eventually be:

> Chatwoot Integration understands native OJS support state and can extend that understanding through installed, compatible Support Providers, including first-party Airix360 payment, waiver, contributor and submission-workflow plugins.

It must **not** claim:

> Chatwoot automatically understands every plugin in the Airix360 organization.

Support exists only where a provider/adapter is implemented, version-tested and enabled.