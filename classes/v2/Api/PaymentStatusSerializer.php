<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/paymentStatus
 * (ojs_get_payment_status in docs/v2/API_MCP_SPEC.md §7.7).
 *
 * Unlike the other submission-scoped serializers, `feeEnabled`/`amount`/
 * `currency` are always safe to return regardless of verification — they
 * are public journal-level facts, not submission-specific state. Only
 * `status` (paid/unpaid/not_applicable) requires the resource to be
 * verified, since it reveals something about a specific user's submission.
 *
 * Deliberately does not accept or expose ResourceRelationship::evidence(),
 * a raw Submission/Context/CompletedPayment object, or any provider secret
 * or payment credential — only the explicit fields below ever reach the
 * response.
 *
 * `obligations` (docs/v2/AIRIX360_INTEGRATIONS.md §4.1,
 * docs/v2/AIRIX360_TASKLIST.md PTF/APS) is an additive field: each entry
 * is one SupportProviderRegistry::resolveObligations() result (producer,
 * feeKey, status, amount, payableAmount, currency, payUrl). It is always
 * an array, empty when no optional payment provider (e.g. Airix
 * Submission Fee) is installed/enabled — existing callers that only read
 * `status`/`amount`/`currency` see no behavior change.
 */
final class PaymentStatusSerializer
{
    /**
     * @param array{enabled:bool,amount:?float,currency:?string} $feeInfo
     * @param array<int,array<string,mixed>> $obligations
     * @return array<string,mixed>
     */
    public static function verified(
        ResourceRelationship $relationship,
        array $feeInfo,
        string $status,
        array $availableActions,
        array $obligations = []
    ): array {
        return [
            'verified' => true,
            'resourceVerified' => true,
            'assurance' => 'v3',
            'resource' => [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
            ],
            'relationships' => $relationship->types(),
            'feeEnabled' => $feeInfo['enabled'],
            'amount' => $feeInfo['amount'],
            'currency' => $feeInfo['currency'],
            'status' => $status,
            'availableActions' => $availableActions,
            'obligations' => $obligations,
        ];
    }

    /**
     * The generic shape for every reason the submission-specific status
     * could not be verified — same anti-enumeration rule as the other
     * submission-scoped serializers. Public fee facts are still included:
     * they carry no information about any specific user or submission.
     *
     * @param array{enabled:bool,amount:?float,currency:?string} $feeInfo
     * @return array<string,mixed>
     */
    public static function unverified(SupportApiRequestContext $context, array $feeInfo, array $availableActions): array
    {
        return [
            'verified' => $context->verified(),
            'resourceVerified' => false,
            'assurance' => $context->assurance(),
            'feeEnabled' => $feeInfo['enabled'],
            'amount' => $feeInfo['amount'],
            'currency' => $feeInfo['currency'],
            'availableActions' => $availableActions,
        ];
    }
}
