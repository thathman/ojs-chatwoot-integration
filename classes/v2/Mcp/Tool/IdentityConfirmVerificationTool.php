<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

/**
 * MCP-012: `identity.confirm_verification`, the MCP equivalent of
 * `ojs_confirm_verification`'s PIN variant (REST, see
 * ChatwootIntegrationV2Plugin::supportVerificationConfirmRequest()).
 * Reuses the exact same real atomic challenge-consumption engine
 * (`RuntimeContextBridge::confirmVerificationPin()`) and support-session
 * establishment (`establishSupportSessionFromExternalVerification()`) —
 * never a second, independently-implemented PIN check.
 *
 * Collapses every distinct failure reason (wrong PIN, expired, revoked,
 * superseded, locked out, wrong conversation, wrong purpose, unknown
 * reference) into the same generic `{verified: false}` result — same
 * anti-enumeration discipline as the REST endpoint. Never returns the
 * stored secret. Successful verification establishes V2 assurance only,
 * never V3, exactly like REST.
 */
final class IdentityConfirmVerificationTool
{
    public const NAME = 'identity.confirm_verification';
    public const DESCRIPTION = 'Confirms a PIN against a previously requested verification challenge and, on success, establishes a support session bound to this exact conversation — the MCP equivalent of ojs_confirm_verification. Collapses every failure reason into the same generic {verified: false} result.';

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
                'challenge' => ['type' => 'string'],
                'purpose' => ['type' => 'string'],
                'pin' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'challenge', 'purpose', 'pin'],
            'additionalProperties' => false,
        ];
    }
}
