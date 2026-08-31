# Airix360 Provider & Payment Portfolio Tasklist

Status: **required v2 backlog supplement**  
These task IDs supplement `TASKLIST.md`. A task is complete only with acceptance tests and exact sibling-plugin compatibility evidence.

## PTF — Payment Portfolio Foundation

- [ ] **PTF-001** Define canonical financial obligation DTO (`producer`, `feeKey`, resource, configured/payable amount, currency, state, provenance).
- [ ] **PTF-002** Define normalized fee states: `not_applicable|not_required|unpaid|pending|paid|waived|partially_waived|failed|refund_pending|refunded|refund_review|disputed|unknown`.
- [ ] **PTF-003** Define aggregate portfolio summary states and precedence rules.
- [ ] **PTF-004** Define canonical waiver DTO and partial-waiver remaining-balance calculation contract.
- [ ] **PTF-005** Define canonical collector/gateway DTO separated from fee producer.
- [ ] **PTF-006** Define producer descriptor contract inspired by Bachs semantics but owned by Support Gateway.
- [ ] **PTF-007** Implement `PaymentPortfolioService` provider aggregation.
- [ ] **PTF-008** Ensure conflicting provider evidence resolves to `attention_required`/`unknown`, never guessed state.
- [ ] **PTF-009** Update Captain `ojs_get_payment_status` to return the portfolio while remaining one custom tool.
- [ ] **PTF-010** Add MCP granular payment read tools.
- [ ] **PTF-011** Add safe public financial action descriptors (`pay`, `retry`, `request_waiver`, `view_history`, `wait`, `contact_support`).
- [ ] **PTF-012** Keep all money/status mutations absent from public plane.
- [ ] **PTF-013** Define staff financial capability namespace for future explicit implementation.
- [ ] **PTF-014** Add financial audit reason/result codes.
- [ ] **PTF-015** Add portfolio OpenAPI/JSON schemas.

## AXP — Airix Provider SDK

- [x] **AXP-001** Add stable Support Provider registration hook/API. — `SupportProviderRegistry::discoverPaymentProviders()` fires `Hook::call('ChatwootIntegration::SupportProviders', [$registry])`; a fake third-party provider registers through it in `tests/v2/provider-registry.php`.
- [x] **AXP-002** Define provider ID/version/applicability/health metadata contract. — `providerId()` + `ProviderHealth::*`; no separate supported-adapter-versions/journal-applicability metadata object yet (not needed by the one real provider).
- [x] **AXP-003** Define `PaymentSupportProviderInterface`.
- [ ] **AXP-004** Define `AccountSupportProviderInterface`.
- [ ] **AXP-005** Define `SubmissionRequirementProviderInterface`.
- [ ] **AXP-006** Define `ContributorSupportProviderInterface`.
- [x] **AXP-007** Implement provider discovery through PKP/OJS plugin registry and explicit registration. — first-party: `Ojs35CompatibilityAdapter::getAirixSubmissionFeeProvider()` via `PluginRegistry::getPlugin('generic', 'submissionfeeplugin')`; third-party: the `ChatwootIntegration::SupportProviders` hook.
- [x] **AXP-008** Implement provider health states and incompatible-version behavior. — `ProviderHealth`; `AirixSubmissionFeeProvider::health()` reports `DISABLED`/`INCOMPATIBLE_VERSION` (only the verified `1.x` line is trusted) alongside `AVAILABLE`.
- [x] **AXP-009** Guarantee provider exceptions are isolated and never break OJS page rendering. — `SupportProviderRegistry::resolveObligations()` wraps every provider call in try/catch; covered in `tests/v2/provider-registry.php`.
- [ ] **AXP-010** Add provider capability/provenance to health UI.
- [ ] **AXP-011** Document third-party provider SDK with a minimal reference plugin. — The hook is tested but not yet documented for external plugin authors.
- [x] **AXP-012** Ensure providers cannot receive Chatwoot credentials or bypass Policy Engine. — A provider returns plain obligation facts only; the calling endpoint independently evaluates `submission.read_own_payment_status` exactly as it already did for the native producer, so a provider can never grant more access than that capability check allows.
- [ ] **AXP-013** Define optional version constraints for known first-party providers. — Only a single hard-coded `1.x` major-version check exists on the one shipped provider; no general constraint mechanism.
- [ ] **AXP-014** Add overlap/conflict declaration support for providers covering the same semantic domain.

## APS — Airix Submission Fee Provider

Target repo: `Airix360/submissionFee-OJS`.

- [x] **APS-001** Detect plugin installed/enabled/version per journal.
- [ ] **APS-002** Read configured submission-fee policy through supported plugin/settings contract. — Only the resolved `amount`/`currency`/`feeEnabled` are read (via `PaymentHelper`); no separate public "policy" fact set (mode, hardBlock vs. holdUntilPaid) is exposed yet.
- [x] **APS-003** Resolve native OJS completed submission payment by authorized submission. — via `PaymentHelper::hasPaid()`, never re-queried directly.
- [ ] **APS-004** Resolve queued/pending submission-fee state safely. — Not implemented; an unpaid submission with a queued payment currently still reports `unpaid`, not `pending`.
- [x] **APS-005** Use provider payable amount after partial waiver; do not duplicate waiver math. — `AirixSubmissionFeeProvider` calls `PaymentHelper::payableAmount()`/`waiverDiscount()` and never recomputes a percentage itself.
- [x] **APS-006** Expose safe author pay URL. — Only for `unpaid`/`partially_waived` obligations; never for `paid`/`waived`/`refunded`/`refund_review`.
- [ ] **APS-007** Normalize `submissionFeeOutstanding` into support state where available.
- [x] **APS-008** Normalize refunded/refund-review/refund-error state without leaking internal error details to public plane. — `refunded`/`refund_review` statuses only; the underlying `submissionFeeRefundError` text is never read or exposed.
- [ ] **APS-009** Add hardBlock/holdUntilPaid support diagnostics.
- [ ] **APS-010** Add submission action-required integration. — Not yet wired into `RequiredActionMapper`/`get_required_actions`.
- [ ] **APS-011** Add public knowledge provider for configured submission-fee policy. — No Knowledge Compiler exists yet (see roadmap ordering: this phase precedes it).
- [ ] **APS-012** Add payment required/completed/refunded event adapters where stable.
- [x] **APS-013** Contract-test exact supported Submission Fee releases. — `AirixSubmissionFeeProvider::health()` only reports `AVAILABLE` for the verified `1.x` line (built and cross-checked against a real local checkout of `Airix360/submissionFee-OJS` @ `80d6a51061b720b35cabcab46841b2decf132f6f`, release `1.7.0.0`); `tests/v2/provider-registry.php` asserts a `2.0.0.0` plugin reports `INCOMPATIBLE_VERSION`. Not run against a live OJS install with the real plugin installed — that remains manual/release-time verification.

## AWA — Airix Waiver Provider

Target repo: `Airix360/ojs-request-waiver`.

Note: AWA-008 (public waiver *policy* knowledge) is implemented — see below.
AWA-001..007/009 concern the *obligation*-side integration (a specific
submission's waiver decision, feeding into the Payment Portfolio) and
remain unbuilt; that is a different trust contract from the knowledge
slice below (see the `PaymentSupportProviderInterface` vs
`KnowledgeProviderInterface` freeze note in `TASKLIST.md`'s KNO section)
and has no real second producer/use case forcing it yet.

- [ ] **AWA-001** Detect plugin installed/enabled/version. — partial: `Ojs35CompatibilityAdapter::getAirixRequestWaiverPolicy()` detects installed/enabled (via `PluginRegistry`), but does not check a version constraint the way `getAirixSubmissionFeeProvider()` does for `submissionFee-OJS`.
- [ ] **AWA-002** Call documented `getWaiverDiscount(submissionId)` integration method when present. — Not built; this is obligation-side (AWA-002..007/009), not the policy-only knowledge slice.
- [ ] **AWA-003** Normalize none/pending/approved/denied waiver states.
- [ ] **AWA-004** Normalize full vs partial approval and percentage.
- [ ] **AWA-005** Calculate/display remaining payable amount only through fee producer + waiver contract.
- [ ] **AWA-006** Expose safe `request_waiver` action/link when allowed.
- [ ] **AWA-007** Exclude waiver reason/history/decision notes from default public serializer.
- [x] **AWA-008** Add public knowledge provider for waiver policy/instructions where configured public. — `CorePaymentKnowledgeProvider::addAirixWaiverPolicy()` reads the plugin's own configured `boxTitle`/`boxBody` settings (verified against a real local checkout of `Airix360/ojs-request-waiver`'s `SettingsForm.php`) and publishes `fee.waiverPolicy` only when a fee is actually active (`RequestWaiverPlugin::activeFeeType()` non-null) and the body text is non-empty. Never reads `waiverStatus`/`waiverPercent`/`getWaiverDiscount()` — those remain submission-specific and out of scope for this fact.
- [ ] **AWA-009** Add waiver requested/approved/denied/partial event adapters where stable.
- [ ] **AWA-010** Define staff-plane waiver-decision capabilities but do not implement in public Captain.
- [ ] **AWA-011** Contract-test exact supported Request Waiver releases.

## APM — Airix Paymethod Support Provider

Target repo: `Airix360/paymethodSupportOJS`.

- [ ] **APM-001** Detect plugin installed/enabled/version.
- [ ] **APM-002** Extract/refactor reusable server-side payment-summary semantics instead of proxying browser handler.
- [ ] **APM-003** Ensure Support Gateway author relationship policy remains independently enforced.
- [ ] **APM-004** Integrate safe payment-history URL/action when available.
- [ ] **APM-005** Detect active payment manager/gateway display information.
- [ ] **APM-006** Define future staff adapter for paid/waived/unpaid operations with fresh Support Gateway auth + audit.
- [ ] **APM-007** Never call existing browser CSRF action as a public service API.
- [ ] **APM-008** Contract-test exact supported Paymethod Support releases.

## AGW — Airix Gateway/Orchestrator Providers

Targets: PaystackOJS, FlutterwaveOJS, BachsOJS, ojs-multipay.

- [ ] **AGW-001** Define normalized gateway capability metadata (`checkout`, `history`, `refund`, `dispute`, `reconciliation`, `recurring`).
- [ ] **AGW-002** Detect active journal paymethod/orchestrator.
- [ ] **AGW-003** Implement Paystack provider safe status adapter.
- [ ] **AGW-004** Implement Flutterwave provider safe status adapter.
- [ ] **AGW-005** Implement Bachs provider safe status adapter.
- [ ] **AGW-006** Implement MultiPay orchestrator provider safe status adapter.
- [ ] **AGW-007** Normalize reconciliation state.
- [ ] **AGW-008** Normalize refund state.
- [ ] **AGW-009** Normalize dispute state.
- [ ] **AGW-010** Detect safe test/live environment state where provider contract supports it.
- [ ] **AGW-011** Strip credentials, reusable authorization tokens, card metadata and raw provider payloads.
- [ ] **AGW-012** Model MultiPay collector/orchestrator separately from obligation producer.
- [ ] **AGW-013** Add exact-version fixtures for all supported gateways.

## AGR — Common Airix Refund Convention

- [ ] **AGR-001** Document/refine adapter around `refundByCompletedPaymentId(contextId, completedPaymentId, amount?)`.
- [ ] **AGR-002** Verify method signature/result contract for each supported Paystack release.
- [ ] **AGR-003** Verify method signature/result contract for each supported Flutterwave release.
- [ ] **AGR-004** Verify method signature/result contract for each supported Bachs release.
- [ ] **AGR-005** Verify method signature/result contract for each supported MultiPay release.
- [ ] **AGR-006** Keep refund capability staff-only.
- [ ] **AGR-007** Require Support Gateway authorization before invoking gateway internal refund method.
- [ ] **AGR-008** Derive context/payment/amount server-side; never trust LLM/client-supplied gateway/reference.
- [ ] **AGR-009** Require idempotency/confirmation/audit around staff refund operation.
- [ ] **AGR-010** Return provider failure safely without leaking provider exception/secrets.

## BGC — Bachs Generator Compatibility

- [ ] **BGC-001** Document mapping from Bachs `Payment::describeQueuedPayment` descriptor into Payment Portfolio producer descriptor.
- [ ] **BGC-002** Detect/use descriptor hook output when available without making Bachs mandatory.
- [ ] **BGC-003** Validate line-item sum vs authoritative queued-payment total when descriptor is consumed.
- [ ] **BGC-004** Support `record_only` semantics in support explanation.
- [ ] **BGC-005** Represent custom completion mode as provider-owned semantics without allowing Captain to invoke fulfilment.
- [ ] **BGC-006** Add reference adapter documentation for future custom Airix fee producers.

## ACU — Contributor User Sync Provider

Target repo: `Airix360/contributorUserSync`.

- [ ] **ACU-001** Detect installed/enabled/version.
- [ ] **ACU-002** Resolve current user's own contributor-link status for an authorized submission.
- [ ] **ACU-003** Resolve pending confirmation safely.
- [ ] **ACU-004** Expose verified ORCID connection state only where relationship policy permits.
- [ ] **ACU-005** Integrate contributor-count requirement with required-actions engine.
- [ ] **ACU-006** Diagnose expected vs actual contributor count.
- [ ] **ACU-007** Never expose another contributor's linked user ID/account data to public requester.
- [ ] **ACU-008** Never auto-link/merge/unlink accounts through public Captain.
- [ ] **ACU-009** Add safe contributor confirmation/invitation action descriptors where appropriate.
- [ ] **ACU-010** Contract-test exact supported Contributor User Sync releases.

## AML — Magic Login Provider

Target repo: `Airix360/ojs-magic-login`.

- [ ] **AML-001** Detect installed/enabled/version per journal.
- [ ] **AML-002** Expose public magic-login availability/request URL.
- [ ] **AML-003** Integrate magic-login suggestion into account diagnostic.
- [ ] **AML-004** Preserve anti-enumeration behavior; never report whether an email has an account.
- [ ] **AML-005** Never expose magic tokens/verifiers/activity entries to Captain.
- [ ] **AML-006** Keep Chatwoot verification session separate from OJS Magic Login session/token semantics.
- [ ] **AML-007** Contract-test exact supported Magic Login releases.

## ARF — Required Submission Files Provider

Target repo: `Airix360/ojs-required-submission-files-airix`.

- [ ] **ARF-001** Detect installed/enabled/version.
- [ ] **ARF-002** Read configured required genre IDs/names through adapter.
- [ ] **ARF-003** Publish required file genres as submission guidance where appropriate.
- [ ] **ARF-004** For authorized submission, determine missing required genres.
- [ ] **ARF-005** Add missing file genres to `get_required_actions`.
- [ ] **ARF-006** Add deterministic submission diagnostic reason code.
- [ ] **ARF-007** Compose with OJS core genre `required` mechanism without duplicate/conflicting explanation.
- [ ] **ARF-008** Contract-test exact supported Required Submission Files releases.

## AVS — Visibility Suite Provider

Target repo: `Airix360/ojs-visibility-suite`.

- [ ] **AVS-001** Detect installed/enabled/version.
- [ ] **AVS-002** Expose safe sitemap/robots/llms.txt availability facts.
- [ ] **AVS-003** Expose indexing/AI crawler configuration as descriptive facts only; do not claim indexing/citation guarantees.
- [ ] **AVS-004** Add optional deterministic indexing-readiness diagnostics for implemented suite modules.
- [ ] **AVS-005** Detect overlap with standalone schema.org provider and avoid duplicate knowledge assertions.
- [ ] **AVS-006** Contract-test exact supported Visibility Suite releases.

## AFP — Future Airix Provider Families

No runtime claim until each repository is implemented and separately verified.

- [ ] **AFP-001** Re-inspect MailGuard when production implementation exists; design mail health/events provider.
- [ ] **AFP-002** Re-inspect Subscription Suite when production implementation exists; extend Payment Portfolio/entitlement/order model.
- [ ] **AFP-003** Re-inspect Publication Bridge when runtime contract exists.
- [ ] **AFP-004** Re-inspect Upgrade Manager when runtime contract exists; expose readiness/status, not destructive upgrade via public Captain.
- [ ] **AFP-005** Inspect Editorial Workspace provider opportunities.
- [ ] **AFP-006** Inspect Custom Editorial Decisions with staff/privacy-first policy.
- [ ] **AFP-007** Inspect Newsletter Sync provider opportunities.
- [ ] **AFP-008** Inspect Conference Suite provider opportunities.
- [ ] **AFP-009** Inspect Advanced Announcements provider opportunities.
- [ ] **AFP-010** Inspect Sentinel/PLN/Schema.org and other Airix OJS repos as requested/implemented.

## AXT — Airix Integration Test Matrix

- [x] **AXT-001** No Airix sibling plugins installed: Chatwoot Integration core still passes. — every pre-existing test suite (payment-status, diagnostics, etc.) still passes unmodified; `getAirixSubmissionFeeProvider()` returns `null` and the endpoint falls back to the native OJS producer exactly as before this phase.
- [x] **AXT-002** One Airix provider installed: provider discovered and isolated. — `tests/v2/provider-registry.php` Part 3.
- [x] **AXT-003** Disabled provider: no capability exposed. — same test: a disabled sibling plugin resolves to `null`, both from the provider's own `health()` and from the adapter's detection.
- [x] **AXT-004** Incompatible provider version: safe degraded/unknown result, no fatal. — `INCOMPATIBLE_VERSION` case in `tests/v2/provider-registry.php`.
- [x] **AXT-005** Provider throws exception: unrelated providers still return. — `ThrowingProvider` case in `tests/v2/provider-registry.php`.
- [ ] **AXT-006** Native APC only portfolio.
- [ ] **AXT-007** Submission fee only portfolio.
- [ ] **AXT-008** Native APC + submission fee simultaneous portfolio.
- [ ] **AXT-009** Full waiver.
- [ ] **AXT-010** Partial waiver.
- [ ] **AXT-011** Pending waiver does not satisfy fee.
- [ ] **AXT-012** Paystack collection/refund/dispute fixture.
- [ ] **AXT-013** Flutterwave collection/refund/reconciliation fixture.
- [ ] **AXT-014** Bachs collection/refund/dispute fixture.
- [ ] **AXT-015** MultiPay orchestration fixture with delegated gateway.
- [ ] **AXT-016** Payment provider down + ledger evidence present.
- [ ] **AXT-017** Provider down + no conclusive ledger evidence → unknown, not unpaid.
- [ ] **AXT-018** Cross-journal payment provider isolation.
- [ ] **AXT-019** Author A cannot access author B financial portfolio.
- [ ] **AXT-020** Public Captain cannot call staff refund/waiver/status actions.
- [ ] **AXT-021** Contributor sync relationship/privacy fixtures.
- [ ] **AXT-022** Required-files missing genre fixtures.
- [ ] **AXT-023** Magic Login anti-enumeration integration fixtures.
- [ ] **AXT-024** Provider-generated public knowledge leak scan.
- [ ] **AXT-025** Exact sibling release/version matrix recorded for release evidence.

## Phase placement

These tasks integrate into the main Build Plan as follows:

- **Phase 0:** AXP contracts/version inventory/test fixtures.
- **Phase 3:** provider capability/policy plumbing, non-payment relationship-safe adapters.
- **Phase 4:** Airix public Knowledge Providers.
- **Phase 5:** Payment Portfolio + Airix payment/waiver/gateway providers + diagnostics.
- **Phase 6:** Airix events/handoff context.
- **Phase 7:** provider SDK documentation + MCP granular tools.
- **Phase 8:** exact Airix compatibility matrix and optional-integration packaging tests.

Airix integrations do not change the rule that `main` remains stable and all v2 implementation work occurs on `v2-dev`/feature branches until release review.