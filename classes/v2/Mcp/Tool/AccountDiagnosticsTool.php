<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;

/**
 * MCP-003: `diagnostics.account`, the MCP equivalent of
 * `ojs_diagnose_account` (REST). Reuses `DiagnosticResultSerializer`
 * verbatim — the caller resolves identity/capability/diagnosis exactly
 * like the REST endpoint does. Deliberately scoped to the caller's own
 * account only (no arbitrary user lookup), same as REST.
 */
final class AccountDiagnosticsTool
{
    public const NAME = 'diagnostics.account';
    public const DESCRIPTION = "Diagnoses the caller's own OJS account (access/login/profile/password-reset/mail-configuration) — the MCP equivalent of ojs_diagnose_account. Never an arbitrary user lookup.";

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
                'scope' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'scope'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string,mixed> */
    public static function handleVerified(DiagnosticResult $diagnosis, array $availableActions): array
    {
        return DiagnosticResultSerializer::verified($diagnosis, $availableActions);
    }
}
