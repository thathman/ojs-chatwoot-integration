<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

/**
 * MCP-003: `support.escalate`, the MCP equivalent of `ojs_escalate_support`
 * (REST). Deliberately V0/unauthenticated-capable (a human handoff must
 * remain available even when verification itself is failing), same as
 * REST. The caller builds the full `HandoffSummaryFormatter` summary and
 * posts the Chatwoot private note exactly like the REST endpoint does —
 * this class only defines the tool's advertised shape; there is no
 * reusable pure serialization step beyond what `HandoffSummaryFormatter`
 * (already shared) provides.
 */
final class EscalateSupportTool
{
    public const NAME = 'support.escalate';
    public const DESCRIPTION = 'Creates a structured human handoff (a Chatwoot private note) summarizing exactly what this gateway already safely knows — the MCP equivalent of ojs_escalate_support. Available even to an unverified caller.';

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
                'reason' => ['type' => 'string'],
                'idempotencyKey' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'reason'],
            'additionalProperties' => false,
        ];
    }
}
