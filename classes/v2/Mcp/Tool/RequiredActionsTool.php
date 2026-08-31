<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * MCP-003: `submission.get_required_actions`, the MCP equivalent of
 * `ojs_get_required_actions` (REST). Deliberately reuses
 * `RequiredActionsSerializer` verbatim — the caller (the tool-registration
 * closure) is responsible for resolving relationship/capability/required
 * actions exactly like the REST endpoint does; this class only ever
 * serializes already-computed input, same as every other tool here.
 */
final class RequiredActionsTool
{
    public const NAME = 'submission.get_required_actions';
    public const DESCRIPTION = 'Lists the actions currently required from the caller (author/reviewer) for one submission — the MCP equivalent of ojs_get_required_actions.';

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
    public static function handleVerified(ResourceRelationship $relationship, array $requiredActions, array $availableActions): array
    {
        return RequiredActionsSerializer::verified($relationship, $requiredActions, $availableActions);
    }
}
