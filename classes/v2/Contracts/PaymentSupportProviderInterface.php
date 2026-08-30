<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

/**
 * Optional first-party/third-party payment obligation provider
 * (docs/v2/AIRIX360_INTEGRATIONS.md §4.1, docs/v2/AIRIX360_TASKLIST.md AXP-003).
 *
 * A provider reports one normalized fee producer's state for a submission.
 * It never receives Chatwoot credentials and is never trusted to enforce
 * Support Core policy itself — the calling endpoint still independently
 * applies its own capability/relationship checks on top of whatever a
 * provider returns, exactly as it already does for the native OJS
 * publication fee.
 */
interface PaymentSupportProviderInterface
{
    /** Stable identifier, e.g. "airix.submission_fee". */
    public function providerId(): string;

    /**
     * One of ProviderHealth::* — must never throw; a provider that cannot
     * determine its own health returns ProviderHealth::UNKNOWN.
     */
    public function health($context): string;

    /**
     * Normalized obligation for this submission, or null when this
     * provider's fee producer does not apply (disabled, not configured, or
     * simply not the fee this submission is subject to).
     *
     * @return array{producer:string,feeKey:string,status:string,amount:?float,payableAmount:?float,currency:?string,payUrl:?string}|null
     */
    public function resolveObligation($context, $submission, int $userId): ?array;
}
