<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;

/**
 * MCP-003: `diagnostics.submission`, the MCP equivalent of
 * `ojs_diagnose_submission` (REST). Reuses `DiagnosticResultSerializer`
 * verbatim — the caller resolves relationship/capability/scope dispatch
 * exactly like the REST endpoint does (see
 * `ChatwootIntegrationV2Plugin::supportSubmissionDiagnosticsRequest()` and
 * its `SubmissionDiagnosticEngine` scope match, reused verbatim by this
 * tool's registration closure).
 */
final class SubmissionDiagnosticsTool
{
    public const NAME = 'diagnostics.submission';
    public const DESCRIPTION = 'Diagnoses one submission across a named scope (access/progress/required action/review access/payment/publication/required files/upload limit) — the MCP equivalent of ojs_diagnose_submission.';

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
                'scope' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'submissionId', 'scope'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function handleVerified(DiagnosticResult $diagnosis, array $availableActions): array
    {
        return DiagnosticResultSerializer::verified($diagnosis, $availableActions);
    }
}
