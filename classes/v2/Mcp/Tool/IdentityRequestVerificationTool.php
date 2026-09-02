<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

/**
 * MCP-011: `identity.request_verification`, the MCP equivalent of
 * `ojs_request_verification` (REST, see
 * ChatwootIntegrationV2Plugin::supportVerificationRequestRequest()). The
 * MCP tool handler reuses the exact same real pipeline components (the
 * shared `SupportApiRequestResolver`, the real
 * `RuntimeContextBridge::requestVerificationChallenge()`,
 * `VerificationEmailContentBuilder`, `Mail::send(new
 * SupportVerificationMailable(...))`, the shared audit sink, and
 * `ResponseTimingNormalizer`) rather than re-implementing verification
 * logic a second time — REST/MCP equivalence by construction, same as
 * every other MCP tool in this build.
 *
 * Deliberately V0/unauthenticated-capable within the MCP transport's own
 * service-token gate: this is how an anonymous caller first proves who
 * they are, so it cannot itself require prior verification. The MCP
 * transport credential (`mcpServiceToken`) authenticates that Captain is
 * a legitimate caller — it never itself establishes end-user identity.
 *
 * Anti-enumeration is structural, not conventional: the tool result is
 * the same generic `{verificationRequested: true, challenge: "..."}`
 * shape regardless of whether the claimed email exists, the account is
 * disabled, mail cannot be sent, or the request is throttled.
 */
final class IdentityRequestVerificationTool
{
    public const NAME = 'identity.request_verification';
    public const DESCRIPTION = 'Requests a PIN or secure-link verification challenge for a claimed email address — the MCP equivalent of ojs_request_verification. Always returns the same generic result shape regardless of whether the account exists.';

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chatwootAccountId' => ['type' => 'string'],
                'chatwootContactId' => ['type' => 'string'],
                'chatwootConversationId' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'purpose' => ['type' => 'string'],
                'method' => ['type' => 'string'],
            ],
            'required' => ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'email', 'purpose'],
            'additionalProperties' => false,
        ];
    }
}
