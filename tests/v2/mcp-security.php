<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\RequiredActionsTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

function mcpSecurityCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// MCP-006: REST/MCP semantic equivalence, proven for one tool pair
// (submission.get_required_actions / ojs_get_required_actions).
//
// REST's supportRequiredActionsRequest() and the MCP registration closure
// both reduce to RequiredActionsSerializer::verified() fed the same
// relationship/requiredActions/availableActions computed by the same
// RequiredActionMapper/CapabilityPolicyEngine calls (already asserted at
// the wiring level in tests/v2/mcp-tools.php). This proves the reduction
// itself: identical fixture input through each transport's own call site
// produces byte-identical output, not merely "the same function called
// twice" — RequiredActionsTool::handleVerified() is the actual MCP call
// site, RequiredActionsSerializer::verified() is the actual REST call
// site.
// ================================================================
$authorRelationship = new ResourceRelationship('submission', 456, ['author'], ['author' => true]);
$requiredActions = ['submit_revisions'];
$availableActions = ['view_status', 'view_required_actions'];

$restShape = RequiredActionsSerializer::verified($authorRelationship, $requiredActions, $availableActions);
$mcpShape = RequiredActionsTool::handleVerified($authorRelationship, $requiredActions, $availableActions);
mcpSecurityCheck($restShape === $mcpShape, 'REST and MCP must produce byte-identical output for submission.get_required_actions given identical resolved relationship/actions — equivalence by construction, not by convention');

// ================================================================
// MCP-007: a public MCP credential/consumer plane must never be able to
// reach a staff-only capability.
//
// Per MCP-005, v2 satisfies this structurally: the public MCP namespace
// is incapable of staff capabilities because no staff/editorial
// capability is defined anywhere in the system yet (never because a
// runtime consumer check happens to deny it) — proven here in three
// independent ways so a future regression in any one of them is caught.
// ================================================================

// 1. No staff/editorial capability exists in the catalog at all — there
//    is nothing for any consumer, public or staff, to reach.
foreach (CapabilityCatalog::all() as $capability) {
    mcpSecurityCheck(
        !str_contains($capability, 'staff') && !str_contains($capability, 'editorial'),
        "CapabilityCatalog must define no staff/editorial capability yet (found \"{$capability}\") — MCP-005's structural-incapability claim depends on this"
    );
}

// 2. CONSUMER_MCP_STAFF confers no extra authority over
//    CONSUMER_MCP_PUBLIC_SUPPORT for the identical identity/relationship —
//    the consumer tag alone is not a capability gate, so even if a staff
//    plane were requested by mistake, it could not silently unlock more.
$identity = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
$publicDecision = CapabilityRequestTestHelper_evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
    'v3',
    $identity,
    $authorRelationship
));
$staffDecision = CapabilityRequestTestHelper_evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_MCP_STAFF,
    'v3',
    $identity,
    $authorRelationship
));
mcpSecurityCheck($publicDecision === $staffDecision, 'CONSUMER_MCP_STAFF must resolve to exactly the same allowed-capability set as CONSUMER_MCP_PUBLIC_SUPPORT for an identical identity/relationship — the consumer plane tag alone must never confer extra authority');

// 3. The real public MCP gateway never even constructs a
//    CONSUMER_MCP_STAFF request — the public endpoint is structurally
//    incapable of reaching the staff plane at all, not merely denied at
//    evaluation time.
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function mcpRequest(');
mcpSecurityCheck($methodStart !== false, 'plugin must implement mcpRequest()');
$nextMethodStart = strpos($pluginSource, "\n    public function", $methodStart + 1);
$mcpMethodBody = $nextMethodStart !== false ? substr($pluginSource, $methodStart, $nextMethodStart - $methodStart) : substr($pluginSource, $methodStart);
mcpSecurityCheck(!str_contains($mcpMethodBody, 'CONSUMER_MCP_STAFF'), 'the public MCP gateway (mcpRequest()) must never reference CONSUMER_MCP_STAFF at all — it has no route to the staff plane, structurally, not just by policy');

function CapabilityRequestTestHelper_evaluate(CapabilityRequest $request): array
{
    $engine = new \APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityPolicyEngine();
    return $engine->evaluate($request)->allowed();
}

fwrite(STDOUT, "MCP security/equivalence tests passed\n");
