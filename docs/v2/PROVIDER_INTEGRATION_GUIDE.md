# Third-Party Support Provider Integration Guide (PRV-007)

How a sibling OJS plugin registers a **payment obligation provider** with
`chatwootIntegration` v2, without this plugin ever hard-coding a reference to
it. This is the only provider family implemented today — see
[Scope](#scope-payment-obligations-only) below before assuming any other
family (account/submission-requirement/contributor) exists.

## 1. The hook

`chatwootIntegration` fires a real Laravel/pkp-lib hook,
`ChatwootIntegration::SupportProviders`, every time it needs the current list
of payment providers (`SupportProviderRegistry::discoverPaymentProviders()`).
Register your provider from your own plugin's `register()`:

```php
use PKP\plugins\Hook;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\SupportProviderRegistry;

Hook::add('ChatwootIntegration::SupportProviders', function (string $hookName, array $args): int {
    /** @var SupportProviderRegistry $registry */
    $registry = $args[0];
    $registry->registerPaymentProvider(new MyPluginPaymentProvider());
    return Hook::CONTINUE;
});
```

`$args[0]` is the live `SupportProviderRegistry` instance for this request —
call `registerPaymentProvider()` on it directly. There is no separate
"provider SDK" package to depend on: your plugin only needs to implement the
one interface below, duck-typed against
`APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface`.
Guard the `use` import (or reference the interface by string) so your plugin
still loads correctly when `chatwootIntegration` is absent/disabled.

## 2. The interface

```php
interface PaymentSupportProviderInterface
{
    public function providerId(): string;
    public function health($context): string;
    public function resolveObligation($context, $submission, int $userId): ?array;
}
```

- **`providerId()`** — a stable, namespaced identifier, e.g.
  `"myplugin.some_fee"`. Used as the array key in the registry, so it must be
  unique across every registered provider.
- **`health($context)`** — one of `ProviderHealth::*`
  (`AVAILABLE`/`DISABLED`/`NOT_INSTALLED`/`INCOMPATIBLE_VERSION`/`DEGRADED`/`UNAVAILABLE`/`UNKNOWN`).
  **Must never throw.** `DISABLED`/`NOT_INSTALLED`/`INCOMPATIBLE_VERSION` mean
  "this producer legitimately doesn't apply here" and are silently skipped —
  not an error. Anything else that isn't `AVAILABLE` is treated as a genuine
  failure and logged; `resolveObligation()` is only ever called when health
  is exactly `AVAILABLE`.
- **`resolveObligation($context, $submission, int $userId)`** — returns
  `null` when this fee doesn't apply to this submission (disabled, not
  configured, wrong fee type), or:

  ```php
  [
      'producer' => $this->providerId(),
      'feeKey' => 'my_fee_key',
      'status' => 'unpaid', // paid|unpaid|waived|partially_waived|refunded|refund_review|not_applicable
      'amount' => 25.00,
      'payableAmount' => 25.00,
      'currency' => 'USD',
      'payUrl' => 'https://...', // only when status is unpaid/partially_waived
  ]
  ```

## 3. What the registry does with it

`SupportProviderRegistry::resolveObligations($context, $submission, $userId)`
calls every registered provider's `health()`, skips the not-applicable ones,
logs and skips any that throw or report a broken state, and calls
`resolveObligation()` only on the rest. When any provider reports an
obligation, it — not OJS's own native publication fee — becomes the
authoritative `status`/`amount`/`currency` the Support API's
`ojs_get_payment_status` endpoint returns for that submission (see
`AIRIX360_INTEGRATIONS.md` §5.8, producer vs. collector). A provider failure
never falls back to a different provider's obligation and never gets
silently reported as `unpaid` — the endpoint reports `unknown` instead.

**Isolation guarantee**: one provider throwing, or reporting a bad health
state, never prevents an unrelated provider's obligation from resolving, and
never breaks the calling OJS page. You do not need to add your own top-level
try/catch around your `health()`/`resolveObligation()` logic for the
registry's sake (though you should still fail safely internally) — the
registry already isolates every provider call.

## 4. What a provider must never do

- Never receive Chatwoot API credentials, tokens, or conversation identity —
  a provider only ever sees `$context`/`$submission`/`$userId`, the same
  inputs the native OJS producer gets.
- Never bypass or reimplement Support Core's identity/relationship/
  capability checks — the calling endpoint independently re-applies its own
  checks on top of whatever a provider returns, exactly as it does for the
  native fee.
- Never read another plugin's settings table directly. Read only that
  plugin's own public methods (see `AirixSubmissionFeeProvider` below) —
  this is what keeps a provider adapter resilient to the sibling plugin's
  internal schema changing between releases.

## 5. Reference implementation

`classes/v2/Provider/AirixSubmissionFeeProvider.php` is the one real,
verified provider in this codebase (verified against
`Airix360/submissionFee-OJS` 1.7.0.0). It:

- checks `getEnabled()` and a supported version prefix in `health()`, and
  confirms the specific `PaymentHelper` methods it needs actually exist
  before ever claiming `AVAILABLE` (protects against a future incompatible
  release silently miscomputing a fee);
- reads only `PaymentHelper`'s public methods (`feeEnabled()`, `hasPaid()`,
  `waiverDiscount()`, `payableAmount()`, `amount()`, `currency()`,
  `payUrl()`, `needsRefundReview()`), never the plugin's settings table;
- is constructed with the live plugin/helper instances duck-typed as
  `object`, not a hard class reference, so it stays loadable and unit-
  testable even when the sibling plugin isn't installed.

Use it as the template for a new provider rather than starting from the bare
interface.

## Scope: payment obligations only

This registry is deliberately narrow. `AIRIX360_INTEGRATIONS.md` §4 describes
a broader four-family provider SDK (payment, account, submission-requirement,
contributor), but only the payment family has a concretely verified sibling
plugin today (PRV-004; see `docs/v2/TASKLIST.md`). The other three
interfaces are intentionally **not defined yet** — adding them speculatively,
with no real provider to verify them against, would risk locking in a
contract nobody has actually exercised. When a real, verified provider needs
one of those families, add that one interface then, following the same
pattern this guide documents.
