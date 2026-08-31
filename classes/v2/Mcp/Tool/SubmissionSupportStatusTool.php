<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionSupportSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * MCP-003: `submission.get_support_status`, the MCP equivalent of
 * `ojs_get_submission_support` (REST). Reuses `SubmissionSupportSerializer`
 * verbatim — the caller resolves relationship/capability/state exactly
 * like the REST endpoint does; this class only ever serializes
 * already-computed input.
 */
final class SubmissionSupportStatusTool
{
    public const NAME = 'submission.get_support_status';
    public const DESCRIPTION = "One submission's normalized support state (e.g. under review, revisions requested, published) — the MCP equivalent of ojs_get_submission_support.";

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
    public static function handleVerified(
        ResourceRelationship $relationship,
        string $title,
        string $supportState,
        string $workflowExplanation,
        array $availableActions,
        string $stateConfidence
    ): array {
        return SubmissionSupportSerializer::verified($relationship, $title, $supportState, $workflowExplanation, $availableActions, $stateConfidence);
    }
}
