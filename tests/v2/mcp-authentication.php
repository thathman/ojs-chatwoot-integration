<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpAuthenticator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpPublicConsumer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityPolicyEngine;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;

function mcpAuthenticationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// MCP-002: McpAuthenticator — the MCP transport's own distinct credential
// check (same generic algorithm as ServiceTokenAuthenticator, a
// deliberately different configured value per ADR-023).
// ================================================================
mcpAuthenticationCheck(McpAuthenticator::verify('real-mcp-token', 'Bearer real-mcp-token'), 'a matching Bearer token must authenticate');
mcpAuthenticationCheck(!McpAuthenticator::verify('real-mcp-token', 'Bearer wrong-token'), 'a non-matching Bearer token must never authenticate');
mcpAuthenticationCheck(!McpAuthenticator::verify('real-mcp-token', null), 'a missing Authorization header must never authenticate');
mcpAuthenticationCheck(!McpAuthenticator::verify('', 'Bearer anything'), 'an unconfigured (empty) MCP token must never authenticate any caller');
mcpAuthenticationCheck(!McpAuthenticator::verify('real-mcp-token', 'real-mcp-token'), 'a header missing the "Bearer " scheme must never authenticate');

// Rotation: comma-separated configured tokens (old,new) — same as the
// Support API's own rotation support, so an admin can rotate the MCP
// secret without downtime.
mcpAuthenticationCheck(McpAuthenticator::verify('new-token,old-token', 'Bearer old-token'), 'either token in a comma-separated rotation list must authenticate');

// Namespace separation: a token configured for one purpose must never
// authenticate under a different configured value — this is what actually
// keeps the MCP and Support API/Chatwoot credentials distinct in
// practice, since journals are expected to configure different values.
mcpAuthenticationCheck(!McpAuthenticator::verify('mcp-only-token', 'Bearer chatwoot-only-token'), 'a token configured for a different purpose must never authenticate here');

// ================================================================
// MCP-002: McpPublicConsumer — a new consumer plane must never itself
// grant more default authority than any other public/unverified consumer.
// ================================================================
$anonymousContext = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
$mcpRequest = McpPublicConsumer::baseCapabilityRequest($anonymousContext);

mcpAuthenticationCheck($mcpRequest->consumerPlane() === CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT, 'the base MCP request must use the real MCP public consumer plane');
mcpAuthenticationCheck($mcpRequest->verificationAssurance() === 'v0', 'a bare MCP client credential must never itself establish more than v0 (unverified) assurance');

$mcpDecision = (new CapabilityPolicyEngine())->evaluate($mcpRequest);
$captainAnonymousRequest = new CapabilityRequest(CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC, 'v0', $anonymousContext);
$captainDecision = (new CapabilityPolicyEngine())->evaluate($captainAnonymousRequest);

$mcpAllowed = $mcpDecision->allowed();
$captainAllowed = $captainDecision->allowed();
sort($mcpAllowed);
sort($captainAllowed);
mcpAuthenticationCheck($mcpAllowed === $captainAllowed, 'an unverified MCP consumer must unlock exactly the same baseline capabilities as an unverified Chatwoot Captain consumer — a new consumer plane must never itself expand authority');
mcpAuthenticationCheck($mcpAllowed !== [], 'sanity: the baseline unverified capability set must not be empty, or the equality check above is vacuous');

// An authenticated-but-unrelated MCP context still cannot reach a
// resource-scoped capability without an actual, separately-proven
// relationship — same rule as every other consumer plane.
$authenticatedContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
$authenticatedMcpRequest = McpPublicConsumer::baseCapabilityRequest($authenticatedContext, null);
$authenticatedDecision = (new CapabilityPolicyEngine())->evaluate($authenticatedMcpRequest);
mcpAuthenticationCheck(
    !$authenticatedDecision->allows('submission.read_own_support_status'),
    'an MCP caller with an authenticated identity but no proven resource relationship must still never unlock a resource-scoped capability'
);

fwrite(STDOUT, "MCP authentication tests passed\n");
