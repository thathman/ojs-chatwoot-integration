<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ServiceTokenAuthenticator;

/**
 * MCP-002 (docs/v2/ADRS.md ADR-023, "credential namespace"): the MCP
 * transport's own Bearer credential check, deliberately a distinct entry
 * point from the Support API's `ServiceTokenAuthenticator` call sites even
 * though both share the same generic, stateless Bearer-match algorithm —
 * what must never be shared is the *configured secret value*. A caller
 * must always pass the journal's own `mcpServiceToken` setting here, never
 * `chatwootSupportApiToken`/`chatwootApiAccessToken`/`chatwootCaptainApiToken`,
 * so a leaked Captain-facing token can never also unlock the MCP surface
 * and vice versa.
 *
 * Proving a caller holds this credential establishes only that "this
 * application may talk to the MCP server" — never any end-user identity,
 * relationship, or capability. Every MCP tool call still independently
 * resolves identity/relationship/capability exactly like every other
 * adapter (see McpPublicConsumer).
 */
final class McpAuthenticator
{
    public static function verify(string $configuredMcpToken, ?string $authorizationHeader): bool
    {
        return ServiceTokenAuthenticator::verify($configuredMcpToken, $authorizationHeader);
    }
}
