<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

/**
 * Result of one `SupportProviderRegistry::resolveObligations()` call —
 * carries not just the successfully resolved obligations but which
 * providers genuinely failed (as opposed to legitimately not applying),
 * so a caller can distinguish "no fee here" from "this journal's real
 * fee producer is broken right now" (docs/v2/PAYMENT_PORTFOLIO.md: a
 * provider failure must generally become `unknown`, never incorrectly
 * `unpaid`).
 */
final class ObligationResolution
{
    /**
     * @param array<int,array{producer:string,feeKey:string,status:string,amount:?float,payableAmount:?float,currency:?string,payUrl:?string}> $obligations
     * @param string[] $failedProviderIds
     */
    public function __construct(
        private array $obligations,
        private array $failedProviderIds
    ) {
    }

    /** @return array<int,array{producer:string,feeKey:string,status:string,amount:?float,payableAmount:?float,currency:?string,payUrl:?string}> */
    public function obligations(): array
    {
        return $this->obligations;
    }

    /** @return string[] */
    public function failedProviderIds(): array
    {
        return $this->failedProviderIds;
    }

    public function hasFailures(): bool
    {
        return $this->failedProviderIds !== [];
    }
}
