<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface;

/**
 * Airix Submission Fee provider (docs/v2/AIRIX360_INTEGRATIONS.md §5.2,
 * docs/v2/AIRIX360_TASKLIST.md APS-*). Verified against
 * Airix360/submissionFee-OJS 1.7.0.0 (SubmissionFeePlugin + PaymentHelper).
 *
 * Reads only PaymentHelper's public methods — never the plugin's settings
 * table directly, and never duplicates its waiver-percent math (APS-005:
 * use the producer's own payable amount, which itself defers to the
 * Request Waiver plugin's own `getWaiverDiscount()` integration point
 * rather than re-deriving waiver state).
 *
 * Constructed with the live plugin/helper instances duck-typed as
 * `object` rather than a hard class reference: this class must remain
 * loadable and unit-testable even when Airix360/submissionFee-OJS is not
 * installed. Ojs35CompatibilityAdapter::getAirixSubmissionFeeProvider()
 * is solely responsible for detecting the plugin, checking version
 * compatibility, and constructing this provider.
 */
final class AirixSubmissionFeeProvider implements PaymentSupportProviderInterface
{
    /** Only 1.x releases of submissionFee-OJS are verified against this adapter (APS-013). */
    private const SUPPORTED_MAJOR_PREFIX = '1.';

    private object $plugin;
    private object $helper;
    private string $pluginVersion;

    public function __construct(object $plugin, object $helper, string $pluginVersion)
    {
        $this->plugin = $plugin;
        $this->helper = $helper;
        $this->pluginVersion = $pluginVersion;
    }

    public function providerId(): string
    {
        return 'airix.submission_fee';
    }

    public function health($context): string
    {
        if (!method_exists($this->plugin, 'getEnabled') || !$this->plugin->getEnabled()) {
            return ProviderHealth::DISABLED;
        }
        if (strncmp($this->pluginVersion, self::SUPPORTED_MAJOR_PREFIX, strlen(self::SUPPORTED_MAJOR_PREFIX)) !== 0) {
            return ProviderHealth::INCOMPATIBLE_VERSION;
        }
        if (!method_exists($this->helper, 'feeEnabled')
            || !method_exists($this->helper, 'hasPaid')
            || !method_exists($this->helper, 'waiverDiscount')
            || !method_exists($this->helper, 'payableAmount')
        ) {
            return ProviderHealth::INCOMPATIBLE_VERSION;
        }
        return ProviderHealth::AVAILABLE;
    }

    public function resolveObligation($context, $submission, int $userId): ?array
    {
        if (!is_object($context) || !is_object($submission)) {
            return null;
        }
        if (!$this->helper->feeEnabled($context)) {
            return null;
        }

        $status = $this->status($submission, $context);

        return [
            'producer' => $this->providerId(),
            'feeKey' => 'submission_fee',
            'status' => $status,
            'amount' => $this->helper->amount($context),
            'payableAmount' => $this->helper->payableAmount($submission, $context),
            'currency' => $this->helper->currency($context),
            'payUrl' => in_array($status, ['unpaid', 'partially_waived'], true)
                ? $this->helper->payUrl($submission, $context)
                : null,
        ];
    }

    private function status($submission, $context): string
    {
        if ((bool) $submission->getData('submissionFeeRefunded')) {
            return 'refunded';
        }
        if ((bool) $submission->getData('submissionFeeNeedsRefundReview')
            || $this->helper->needsRefundReview($submission, $context)
        ) {
            return 'refund_review';
        }
        if ($this->helper->hasPaid($submission, $context)) {
            return 'paid';
        }
        $discount = $this->helper->waiverDiscount($submission);
        if ($discount !== null) {
            return $discount['type'] === 'full' ? 'waived' : 'partially_waived';
        }
        return 'unpaid';
    }
}
