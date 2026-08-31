<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;

/**
 * MCP-003: `identity.get_support_identity`, the MCP equivalent of
 * `ojs_get_support_identity` (REST). Deliberately reuses
 * `SupportIdentitySerializer::serialize()` verbatim rather than building a
 * second serialization — this is what actually guarantees REST/MCP
 * response equivalence (MCP-006), not merely an intent to keep them in
 * sync by hand.
 *
 * Identity resolution itself (reading the Chatwoot conversation tuple from
 * tool arguments and calling the same `SupportApiRequestResolver` REST
 * uses) happens in the caller — this tool only ever serializes an
 * already-resolved `SupportApiRequestContext`, exactly like every other
 * tool here only ever serializes already-computed input.
 */
final class SupportIdentityTool
{
    public const NAME = 'identity.get_support_identity';
    public const DESCRIPTION = "Resolves the caller's OJS support identity for a given Chatwoot conversation — the MCP equivalent of ojs_get_support_identity.";

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function handle(SupportApiRequestContext $result): array
    {
        return SupportIdentitySerializer::serialize($result);
    }
}
