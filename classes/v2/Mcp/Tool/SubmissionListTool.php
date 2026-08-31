<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaginationParams;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionListSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;

/**
 * MCP-003: `submission.list_mine`, the MCP equivalent of REST's
 * `supportSubmissionListRequest()` (`GET /ojsSupportGateway/submissions`).
 * Deliberately reuses `SubmissionListSerializer` verbatim — the caller
 * (the tool-registration closure) is responsible for resolving candidates,
 * re-checking each one's real relationship, and paginating exactly like
 * the REST endpoint does; this class only ever serializes already-computed
 * input, same as every other tool here.
 */
final class SubmissionListTool
{
    public const NAME = 'submission.list_mine';
    public const DESCRIPTION = 'Lists the submissions the caller has an actual author or reviewer relationship to — the MCP equivalent of the REST submissions list endpoint.';

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function handle(SupportApiRequestContext $result): array
    {
        return SubmissionListSerializer::unverified($result);
    }

    /**
     * @param array<int,array{relationship:\APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship,title:string,supportState:string,actionRequired:?bool}> $entries
     *
     * @return array<string,mixed>
     */
    public static function handleVerified(SupportApiRequestContext $result, array $entries, PaginationParams $pagination, bool $hasMore): array
    {
        return SubmissionListSerializer::verified($result, $entries, $pagination, $hasMore);
    }
}
