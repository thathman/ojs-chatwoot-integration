<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaymentStatusSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * MCP-003: `payment.get_submission_status`, the MCP equivalent of
 * `ojs_get_payment_status` (REST). Reuses `PaymentStatusSerializer`
 * verbatim — the caller resolves relationship/capability/obligations
 * exactly like the REST endpoint does (including the Airix payment
 * provider precedence rule: a registered provider's obligation, when
 * present, is authoritative over the native OJS publication fee).
 */
final class PaymentStatusTool
{
    public const NAME = 'payment.get_submission_status';
    public const DESCRIPTION = "Public fee facts (enabled/amount/currency) plus, when authorized, one submission's paid/unpaid status — the MCP equivalent of ojs_get_payment_status.";

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
                'submissionId' => ['type' => 'integer'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'submissionId'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function handleVerified(ResourceRelationship $relationship, array $feeInfo, string $status, array $availableActions, array $obligations = []): array
    {
        return PaymentStatusSerializer::verified($relationship, $feeInfo, $status, $availableActions, $obligations);
    }
}
