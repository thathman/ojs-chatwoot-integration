<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

/**
 * The normalized obligation-status vocabulary every payment producer
 * (native OJS, Airix Submission Fee, any future producer) must map onto
 * — docs/v2/PAYMENT_PORTFOLIO.md. `UNKNOWN` is not a catch-all default;
 * it is the deliberate result when a producer that should have an
 * opinion fails to produce one (an exception, a genuinely broken
 * provider) — never confused with `UNPAID`, which asserts a fact.
 */
final class PaymentObligationStatus
{
    public const NOT_APPLICABLE = 'not_applicable';
    public const UNPAID = 'unpaid';
    public const PAID = 'paid';
    public const WAIVED = 'waived';
    public const PARTIALLY_WAIVED = 'partially_waived';
    public const REFUND_REVIEW = 'refund_review';
    public const REFUNDED = 'refunded';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}
