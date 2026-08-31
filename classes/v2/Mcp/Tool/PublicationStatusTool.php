<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PublicationStatusSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * MCP-003: `publication.get_status`, the MCP equivalent of
 * `ojs_get_publication_status` (REST). Reuses `PublicationStatusSerializer`
 * verbatim — the caller resolves relationship/capability/publication
 * fields exactly like the REST endpoint does.
 */
final class PublicationStatusTool
{
    public const NAME = 'publication.get_status';
    public const DESCRIPTION = "One submission's publication status — DOI, public URL, and issue (volume/number/year) once actually published — the MCP equivalent of ojs_get_publication_status.";

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
        string $status,
        ?string $doi,
        ?string $publicUrl,
        ?array $issue,
        array $availableActions
    ): array {
        return PublicationStatusSerializer::verified($relationship, $status, $doi, $publicUrl, $issue, $availableActions);
    }
}
