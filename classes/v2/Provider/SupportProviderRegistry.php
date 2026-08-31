<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface;

/**
 * Minimal Support Provider registry (docs/v2/AIRIX360_TASKLIST.md
 * AXP-001/002/007/008/009).
 *
 * Scope is deliberately narrow: payment obligation providers only, since
 * that is the one provider family with a concretely verified sibling
 * plugin (Airix Submission Fee) today. Do not grow this into the full
 * Knowledge/Capability/Diagnostic/Event provider surface
 * (docs/v2/AIRIX360_INTEGRATIONS.md §4) speculatively — add each provider
 * family only when a real, verified provider needs it, the same way
 * `ojs_get_payment_status` (API-012) was built directly against OJS core
 * rather than through a registry until a second real producer existed.
 *
 * A provider throwing an exception must never break unrelated providers or
 * the calling OJS page (AXP-009): every provider call is isolated here.
 */
final class SupportProviderRegistry
{
    /** @var array<string,PaymentSupportProviderInterface> */
    private array $paymentProviders = [];

    public function registerPaymentProvider(PaymentSupportProviderInterface $provider): void
    {
        $this->paymentProviders[$provider->providerId()] = $provider;
    }

    /**
     * Fires the third-party registration hook (AXP-001) so a sibling
     * plugin can add itself without chatwootIntegration hard-coding it,
     * then returns every payment provider currently registered —
     * first-party providers the caller already registered directly, plus
     * any third-party registrations the hook collected.
     *
     * @return PaymentSupportProviderInterface[]
     */
    public function discoverPaymentProviders(): array
    {
        if (class_exists('\PKP\plugins\Hook')) {
            \PKP\plugins\Hook::call('ChatwootIntegration::SupportProviders', [$this]);
        }
        return array_values($this->paymentProviders);
    }

    /**
     * Health states that mean "this producer legitimately does not apply
     * here" — never a failure, never worth reporting `unknown` over.
     * Anything else (DEGRADED/UNAVAILABLE/UNKNOWN, or an exception) is a
     * genuine failure: the producer exists and should have an opinion but
     * couldn't produce one (docs/v2/PAYMENT_PORTFOLIO.md — a provider
     * failure must generally become `unknown`, never incorrectly
     * `unpaid`).
     */
    private const NOT_APPLICABLE_HEALTH_STATES = [
        ProviderHealth::DISABLED,
        ProviderHealth::NOT_INSTALLED,
        ProviderHealth::INCOMPATIBLE_VERSION,
    ];

    /**
     * Resolves every registered payment provider's obligation for this
     * submission. A provider that legitimately does not apply (disabled,
     * not installed, incompatible version) is silently skipped — that is
     * not a failure. A provider that throws, or reports a genuinely
     * broken health state (DEGRADED/UNAVAILABLE/UNKNOWN), is recorded as
     * a failure on the returned ObligationResolution so the caller can
     * report `unknown` instead of silently falling back to a different
     * producer's state.
     */
    public function resolveObligations($context, $submission, int $userId): ObligationResolution
    {
        $obligations = [];
        $failedProviderIds = [];

        foreach ($this->discoverPaymentProviders() as $provider) {
            try {
                $health = $provider->health($context);
                if (in_array($health, self::NOT_APPLICABLE_HEALTH_STATES, true)) {
                    continue;
                }
                if ($health !== ProviderHealth::AVAILABLE) {
                    $failedProviderIds[] = $provider->providerId();
                    error_log(sprintf(
                        '[ChatwootIntegration] Support provider "%s" reported health "%s"',
                        $provider->providerId(),
                        $health
                    ));
                    continue;
                }
                $obligation = $provider->resolveObligation($context, $submission, $userId);
            } catch (\Throwable $e) {
                $failedProviderIds[] = $provider->providerId();
                error_log(sprintf(
                    '[ChatwootIntegration] Support provider "%s" failed: %s',
                    $provider->providerId(),
                    $e->getMessage()
                ));
                continue;
            }
            if ($obligation !== null) {
                $obligations[] = $obligation;
            }
        }

        return new ObligationResolution($obligations, $failedProviderIds);
    }
}
