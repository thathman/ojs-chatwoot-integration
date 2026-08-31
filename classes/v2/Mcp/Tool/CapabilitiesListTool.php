<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

/**
 * MCP-003: `capabilities.list_available`, the MCP equivalent of REST's
 * `supportActionsRequest()` (`GET /ojsSupportGateway/actions`) — capability
 * discovery, separated from identity/status so a caller can decide what it
 * may offer without inferring permissions from other responses.
 *
 * REST builds this shape inline (no dedicated serializer class exists for
 * it), so this tool does the same rather than inventing one; the caller
 * (the tool-registration closure) resolves the real `CapabilityDecision`
 * and passes the already-computed available/disabled action lists.
 */
final class CapabilitiesListTool
{
    public const NAME = 'capabilities.list_available';
    public const DESCRIPTION = 'Lists the actions currently available (and, when safe to disclose, disabled) to the caller at their current verification level — the MCP equivalent of the REST actions endpoint.';

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

    /**
     * @param string[] $availableActions
     * @param array<int,array{action:string,reason:string}> $disabledActions
     *
     * @return array<string,mixed>
     */
    public static function handle(bool $verified, string $assurance, array $availableActions, array $disabledActions): array
    {
        return [
            'verified' => $verified,
            'assurance' => $assurance,
            'availableActions' => $availableActions,
            'disabledActions' => $disabledActions,
        ];
    }
}
