<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpDispatcher;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpHandlerError;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpToolRegistry;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\FeePolicyTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\IdentityConfirmVerificationTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\IdentityRequestVerificationTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\JournalProfileTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionPolicyTool;

function mcpToolsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// MCP-003: McpToolRegistry.
// ================================================================
$registry = new McpToolRegistry();
mcpToolsCheck($registry->list() === [], 'a fresh registry must advertise no tools');
mcpToolsCheck(!$registry->has('anything'), 'a fresh registry must not claim to have any tool');

$registry->register('demo.tool', 'A demo tool.', ['type' => 'object'], fn (array $args): array => ['echo' => $args]);
$list = $registry->list();
mcpToolsCheck(count($list) === 1 && $list[0]['name'] === 'demo.tool' && $list[0]['description'] === 'A demo tool.', 'a registered tool must be advertised with its real name/description');
mcpToolsCheck(!array_key_exists('handler', $list[0]), 'list() must never expose a tool\'s handler, only its advertised metadata');
mcpToolsCheck($registry->has('demo.tool'), 'has() must recognize a registered tool');

$result = $registry->call('demo.tool', ['a' => 1]);
mcpToolsCheck($result === ['echo' => ['a' => 1]], 'call() must invoke the real registered handler with the given arguments and return its result verbatim');

$threw = false;
try {
    $registry->call('unregistered.tool', []);
} catch (McpHandlerError $e) {
    $threw = true;
    mcpToolsCheck($e->mcpErrorCode() === McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, 'calling an unregistered tool must throw McpHandlerError with UNKNOWN_TOOL_OR_RESOURCE');
}
mcpToolsCheck($threw, 'calling an unregistered tool must throw, never silently return null');

// ================================================================
// MCP-003: McpDispatcher must preserve a handler's own McpHandlerError
// code exactly, distinct from the generic INTERNAL_ERROR path.
// ================================================================
$dispatcher = new McpDispatcher();
$dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_CALL, function (McpRequest $r) use ($registry): array {
    $name = $r->params()['name'] ?? '';
    return ['content' => $registry->call((string) $name, [])];
});
$unknownToolResponse = $dispatcher->dispatch(new McpRequest(McpProtocol::METHOD_TOOLS_CALL, ['name' => 'nope'], 'id-1', McpProtocol::REVISION));
mcpToolsCheck($unknownToolResponse->isError() && $unknownToolResponse->errorCode() === McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, 'dispatching a tools/call for an unknown tool must surface UNKNOWN_TOOL_OR_RESOURCE specifically, not a generic INTERNAL_ERROR');

// ================================================================
// MCP-003: JournalProfileTool — reuses the real Knowledge Compiler
// output verbatim, never a raw KnowledgeFact object or provenance
// metadata, and degrades to an empty profile rather than fatal when no
// compilation is available.
// ================================================================
mcpToolsCheck(JournalProfileTool::handle(null) === [], 'no compilation available must degrade to an empty profile, never fatal');

$facts = [
    new KnowledgeFact('journal.name', 'Journal of Testing', KnowledgeClassification::PUBLIC, 'ojs.context', 'en', 'core.journal', 'name'),
    new KnowledgeFact('journal.contactEmail', 'editor@example.com', KnowledgeClassification::PUBLIC, 'ojs.context', 'en', 'core.journal', 'contactEmail'),
    new KnowledgeFact('submission.authorGuidelines', 'Follow the template.', KnowledgeClassification::PUBLIC, 'ojs.context', 'en', 'core.journal', 'authorGuidelines'),
];
$facts[] = new KnowledgeFact('fee.submissionEnabled', 'true', KnowledgeClassification::PUBLIC, 'ojs.payment_manager', 'en', 'core.payment', 'submissionFee');
$facts[] = new KnowledgeFact('fee.submissionAmount', '75.00', KnowledgeClassification::PUBLIC, 'ojs.payment_manager', 'en', 'core.payment', 'submissionFeeAmount');
$compilation = new KnowledgeCompilation(7, 'en', $facts, 'fingerprint-1', time());

$profile = JournalProfileTool::handle($compilation);
mcpToolsCheck($profile === ['journal.name' => 'Journal of Testing', 'journal.contactEmail' => 'editor@example.com'], 'the tool must return exactly the journal.* facts as a flat key => value map, excluding non-journal.* facts, and nothing else');
foreach ($profile as $value) {
    mcpToolsCheck(is_string($value), 'every value in the tool result must be a plain string — never a KnowledgeFact object or nested provenance structure');
}

mcpToolsCheck(SubmissionPolicyTool::handle(null) === [], 'no compilation available must degrade to an empty policy, never fatal');
$submissionPolicy = SubmissionPolicyTool::handle($compilation);
mcpToolsCheck($submissionPolicy === ['submission.authorGuidelines' => 'Follow the template.'], 'the submission policy tool must return exactly the submission.* facts, excluding journal.*/fee.* facts');

mcpToolsCheck(FeePolicyTool::handle(null) === [], 'no compilation available must degrade to an empty fee policy, never fatal');
$feePolicy = FeePolicyTool::handle($compilation);
mcpToolsCheck($feePolicy === ['fee.submissionEnabled' => 'true', 'fee.submissionAmount' => '75.00'], 'the fee policy tool must return exactly the fee.* facts, excluding journal.*/submission.* facts');

// ================================================================
// Wiring: the plugin's mcpRequest() must authenticate via the MCP's own
// distinct credential before ever parsing the body, and must never reuse
// a Chatwoot/Support-API token setting for it.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function mcpRequest(');
mcpToolsCheck($methodStart !== false, 'plugin must implement mcpRequest()');
$nextMethodStart = strpos($pluginSource, "\n    public function", $methodStart + 1);
$mcpMethodBody = $nextMethodStart !== false ? substr($pluginSource, $methodStart, $nextMethodStart - $methodStart) : substr($pluginSource, $methodStart);

mcpToolsCheck(str_contains($mcpMethodBody, "'mcpServiceToken'"), 'mcpRequest() must read a distinct mcpServiceToken setting');

// The authenticate-before-parse slice is everything up to the first body-parse
// call; a downstream tool (support.escalate) legitimately reads the real
// chatwootApiAccessToken later to post the actual Chatwoot note, exactly as
// REST does, so the no-token-reuse guarantee is scoped to authentication only.
$parseCallPos = strpos($mcpMethodBody, 'McpRequestParser::parse(');
mcpToolsCheck($parseCallPos !== false, 'mcpRequest() must parse the request body via McpRequestParser');
$authenticationSlice = substr($mcpMethodBody, 0, $parseCallPos);
mcpToolsCheck(!str_contains($authenticationSlice, "'chatwootSupportApiToken'") && !str_contains($authenticationSlice, "'chatwootApiAccessToken'"), 'mcpRequest() must never authenticate against a Chatwoot/Support-API token setting');
mcpToolsCheck(str_contains($mcpMethodBody, 'McpAuthenticator::verify('), 'mcpRequest() must authenticate via McpAuthenticator');
mcpToolsCheck(
    strpos($mcpMethodBody, 'McpAuthenticator::verify(') < strpos($mcpMethodBody, 'McpRequestParser::parse('),
    'authentication must happen before the request body is ever parsed, so an unauthenticated caller can never use parse errors as a probing oracle'
);
mcpToolsCheck(str_contains($mcpMethodBody, JournalProfileTool::class) || str_contains($mcpMethodBody, 'JournalProfileTool'), 'mcpRequest() must register the real JournalProfileTool, not a placeholder');
mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionPolicyTool'), 'mcpRequest() must register the real SubmissionPolicyTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'FeePolicyTool'), 'mcpRequest() must register the real FeePolicyTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'SupportIdentityTool'), 'mcpRequest() must register the real SupportIdentityTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'new SupportApiRequestResolver('), 'the identity tool must resolve identity through the real, same SupportApiRequestResolver REST uses — never a second, parallel identity resolution path');
mcpToolsCheck(str_contains($mcpMethodBody, '$configuredMcpToken'), 'the identity tool must pass the distinct MCP token into the resolver, never a Chatwoot/Support-API token');
mcpToolsCheck(str_contains($mcpMethodBody, 'McpSupportApiFailureMapper::toHandlerError('), 'a resolver failure must be mapped to a real McpHandlerError, never silently swallowed or generically rethrown');
mcpToolsCheck(str_contains($mcpMethodBody, 'RequiredActionsTool'), 'mcpRequest() must register the real RequiredActionsTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'v2ResolveMcpSubmissionContext'), 'the required-actions tool must resolve its submission relationship through the shared helper, never a bespoke inline copy');
mcpToolsCheck(str_contains($mcpMethodBody, 'CONSUMER_MCP_PUBLIC_SUPPORT'), 'MCP capability evaluation must use the real MCP consumer plane, never silently reuse the Chatwoot Captain one');
mcpToolsCheck(str_contains($mcpMethodBody, "'submission.read_own_required_actions'"), 'the required-actions tool must gate on the real submission.read_own_required_actions capability, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionSupportStatusTool'), 'mcpRequest() must register the real SubmissionSupportStatusTool');
mcpToolsCheck(str_contains($mcpMethodBody, "'submission.read_own_support_status'"), 'the submission-support-status tool must gate on the real submission.read_own_support_status capability, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'PublicationStatusTool'), 'mcpRequest() must register the real PublicationStatusTool');
mcpToolsCheck(str_contains($mcpMethodBody, "'submission.read_own_publication_status'"), 'the publication-status tool must gate on the real submission.read_own_publication_status capability, same as REST');
mcpToolsCheck(
    substr_count($mcpMethodBody, 'v2ResolveMcpSubmissionContext') >= 4,
    'all four submission-scoped tools built so far must resolve through the same shared helper, never each inventing its own copy'
);
mcpToolsCheck(str_contains($mcpMethodBody, 'PaymentStatusTool'), 'mcpRequest() must register the real PaymentStatusTool');
mcpToolsCheck(str_contains($mcpMethodBody, "'submission.read_own_payment_status'"), 'the payment-status tool must gate on the real submission.read_own_payment_status capability, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'v2ResolvePaymentObligations'), 'the payment-status tool must resolve provider obligations through the same shared helper REST uses, never a bespoke copy');
mcpToolsCheck(str_contains($mcpMethodBody, "['payment_status' => \$feeInfo['enabled']]"), 'the payment-status tool must pass the real payment_status feature flag into capability evaluation, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'AccountDiagnosticsTool'), 'mcpRequest() must register the real AccountDiagnosticsTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'AccountDiagnosticEngine::SCOPES'), 'the account-diagnostics tool must validate scope against the real registered scope list, never a hardcoded copy');
mcpToolsCheck(str_contains($mcpMethodBody, "'account.diagnose_own'"), 'the account-diagnostics tool must gate on the real account.diagnose_own capability, same as REST');
mcpToolsCheck(
    !preg_match('/getUserVar\([\'"](email|username|userId|user_id)[\'"]\)/', $mcpMethodBody),
    'no MCP tool built so far may accept a caller-supplied email/username/userId via getUserVar — arguments are read from the parsed tool call, and account diagnostics must only ever diagnose the verified caller\'s own account, same as REST'
);
mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionDiagnosticsTool'), 'mcpRequest() must register the real SubmissionDiagnosticsTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionDiagnosticEngine::SCOPES'), 'the submission-diagnostics tool must validate scope against the real registered scope list, never a hardcoded copy');
mcpToolsCheck(str_contains($mcpMethodBody, "'submission.diagnose_own'"), 'the submission-diagnostics tool must gate on the real submission.diagnose_own capability, same as REST');
mcpToolsCheck(
    str_contains($mcpMethodBody, 'diagnosePaymentForSubmission($bridge, $request, $result, $relationship, $submissionId, $userId, CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT)'),
    'the submission-diagnostics payment scope must reuse the same shared helper REST uses, passing the real MCP consumer plane rather than silently defaulting to the Chatwoot Captain one'
);
mcpToolsCheck(
    substr_count($mcpMethodBody, 'v2ResolveMcpSubmissionContext') >= 5,
    'all five submission-scoped tools built so far must resolve through the same shared helper, never each inventing its own copy'
);

mcpToolsCheck(str_contains($mcpMethodBody, 'EscalateSupportTool::NAME'), 'mcpRequest() must register the real EscalateSupportTool');
mcpToolsCheck(str_contains($mcpMethodBody, "->allows('support.escalate')"), 'the escalate tool must gate on the real support.escalate capability, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'HandoffSummaryFormatter::build('), 'the escalate tool must build its handoff summary through the real shared HandoffSummaryFormatter, never a bespoke copy');
mcpToolsCheck(str_contains($mcpMethodBody, 'new EscalationIdempotencyGuard()'), 'the escalate tool must reuse the real idempotency guard, never a bespoke copy');
mcpToolsCheck(str_contains($mcpMethodBody, 'new ChatwootApiService('), 'the escalate tool must post the handoff through the real ChatwootApiService, never a bespoke copy');
mcpToolsCheck(
    !preg_match('/EscalateSupportTool.*?CONSUMER_CHATWOOT_CAPTAIN_PUBLIC/s', $mcpMethodBody),
    'the escalate tool must evaluate capabilities under the real MCP consumer plane, never silently falling back to the Chatwoot Captain one'
);

mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionListTool::NAME'), 'mcpRequest() must register the real SubmissionListTool');
mcpToolsCheck(str_contains($mcpMethodBody, "->allows('submission.list_own')"), 'the list-mine tool must gate on the real submission.list_own capability, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'listCandidateSubmissions('), 'the list-mine tool must source candidates through the same broad, safe OJS-native query REST uses, never a bespoke copy');
mcpToolsCheck(str_contains($mcpMethodBody, "!\$relationship->has('author') && !\$relationship->has('reviewer')"), 'the list-mine tool must exclude editorial-only relationships, same as REST');
mcpToolsCheck(str_contains($mcpMethodBody, 'PaginationParams::parse('), 'the list-mine tool must validate limit/offset through the real shared pagination parser, never a bespoke copy');
mcpToolsCheck(
    !preg_match('/SubmissionListTool.*?CONSUMER_CHATWOOT_CAPTAIN_PUBLIC/s', $mcpMethodBody),
    'the list-mine tool must evaluate capabilities under the real MCP consumer plane, never silently falling back to the Chatwoot Captain one'
);
mcpToolsCheck(
    str_contains($mcpMethodBody, 'SubmissionListTool::handle($result)') && str_contains($mcpMethodBody, 'SubmissionListTool::handleVerified('),
    'the list-mine tool must degrade to the same generic unverified/denied shape REST uses (never a distinct error that would let a denied capability be enumerated), and serialize a real verified list through the real serializer'
);

mcpToolsCheck(str_contains($mcpMethodBody, 'CapabilitiesListTool::NAME'), 'mcpRequest() must register the real CapabilitiesListTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'CapabilitiesListTool::handle('), 'the capabilities tool must serialize through the real tool handler, never a bespoke inline shape');
mcpToolsCheck(str_contains($mcpMethodBody, '$bridge->disabledActions($decision)'), 'the capabilities tool must expose the real disabled-actions list, same as REST');
mcpToolsCheck(
    !preg_match('/CapabilitiesListTool.*?CONSUMER_CHATWOOT_CAPTAIN_PUBLIC/s', $mcpMethodBody),
    'the capabilities tool must evaluate capabilities under the real MCP consumer plane, never silently falling back to the Chatwoot Captain one'
);

// ================================================================
// MCP-011/MCP-012: identity.request_verification /
// identity.confirm_verification — the MCP equivalents of
// ojs_request_verification/ojs_confirm_verification. Must reuse the
// exact same real pipeline REST uses (RuntimeContextBridge's
// requestVerificationChallenge()/confirmVerificationPin()/
// establishSupportSessionFromExternalVerification(), the shared audit
// sink, ResponseTimingNormalizer) — never a second, independently
// implemented verification engine.
// ================================================================
mcpToolsCheck(str_contains($mcpMethodBody, IdentityRequestVerificationTool::class) || str_contains($mcpMethodBody, 'IdentityRequestVerificationTool'), 'mcpRequest() must register the real IdentityRequestVerificationTool');
mcpToolsCheck(str_contains($mcpMethodBody, IdentityConfirmVerificationTool::class) || str_contains($mcpMethodBody, 'IdentityConfirmVerificationTool'), 'mcpRequest() must register the real IdentityConfirmVerificationTool');

$requestVerificationStart = strpos($mcpMethodBody, 'IdentityRequestVerificationTool::NAME');
$confirmVerificationStart = strpos($mcpMethodBody, 'IdentityConfirmVerificationTool::NAME');
mcpToolsCheck($requestVerificationStart !== false && $confirmVerificationStart !== false, 'both verification tools must be registered');
$requestVerificationBlock = substr($mcpMethodBody, $requestVerificationStart, $confirmVerificationStart - $requestVerificationStart);
$confirmVerificationEnd = strpos($mcpMethodBody, "\$registry->register(\n            RequiredActionsTool::NAME", $confirmVerificationStart);
$confirmVerificationBlock = $confirmVerificationEnd !== false
    ? substr($mcpMethodBody, $confirmVerificationStart, $confirmVerificationEnd - $confirmVerificationStart)
    : substr($mcpMethodBody, $confirmVerificationStart);

mcpToolsCheck(str_contains($requestVerificationBlock, 'v2ResolveMcpIdentity('), 'identity.request_verification must authenticate the MCP caller through the same shared v2ResolveMcpIdentity() every other MCP tool uses, never a bespoke auth path');
mcpToolsCheck(str_contains($requestVerificationBlock, 'VerificationChallenge::PURPOSES'), 'identity.request_verification must validate purpose against the real registered purpose list, never a hardcoded copy');
mcpToolsCheck(str_contains($requestVerificationBlock, '$bridge->requestVerificationChallenge('), 'identity.request_verification must issue the challenge through the real, same bridge method REST uses, never a second verification engine');
mcpToolsCheck(str_contains($requestVerificationBlock, 'VerificationEmailTemplateService::compose('), 'identity.request_verification must build the email content through the real shared EmailTemplate-backed service, same as REST');
mcpToolsCheck(str_contains($requestVerificationBlock, 'Mail::send(new SupportVerificationMailable('), 'identity.request_verification must send through the real SupportVerificationMailable, same as REST');
mcpToolsCheck(str_contains($requestVerificationBlock, "'verificationRequest'"), 'identity.request_verification must audit under the real verificationRequest endpoint name, same as REST');
mcpToolsCheck(str_contains($requestVerificationBlock, 'ResponseTimingNormalizer::normalize('), 'identity.request_verification must apply the real timing-normalization floor, same anti-enumeration discipline as REST');
mcpToolsCheck(
    str_contains($requestVerificationBlock, "return ['verificationRequested' => true, 'challenge' => \$publicReference];"),
    'identity.request_verification must always return the same generic result shape regardless of whether the account exists — the anti-enumeration guarantee must be structural, not conditional'
);
mcpToolsCheck(
    substr_count($requestVerificationBlock, "return ['verificationRequested'") === 1,
    'identity.request_verification must have exactly one return statement for the success shape — no alternate early-return path may leak a different, distinguishable shape'
);

mcpToolsCheck(str_contains($confirmVerificationBlock, 'v2ResolveMcpIdentity('), 'identity.confirm_verification must authenticate the MCP caller through the same shared v2ResolveMcpIdentity(), never a bespoke auth path');
mcpToolsCheck(str_contains($confirmVerificationBlock, '$bridge->confirmVerificationPin('), 'identity.confirm_verification must confirm the PIN through the real, same atomic bridge method REST uses, never a second, independently-implemented check');
mcpToolsCheck(str_contains($confirmVerificationBlock, '$bridge->establishSupportSessionFromExternalVerification('), 'identity.confirm_verification must establish the session through the real shared bridge method, same as REST');
mcpToolsCheck(str_contains($confirmVerificationBlock, "'verificationConfirm'"), 'identity.confirm_verification must audit under the real verificationConfirm endpoint name, same as REST');
mcpToolsCheck(str_contains($confirmVerificationBlock, "return ['verified' => false];"), 'identity.confirm_verification must collapse every failure reason into the same generic {verified: false} result, never a distinguishable per-reason shape');
mcpToolsCheck(!str_contains($confirmVerificationBlock, 'plaintextSecret'), 'identity.confirm_verification must never return the stored secret/PIN back to the caller');

$handlerSource = (string) file_get_contents($root . '/classes/v2/Http/McpGatewayPageHandler.php');
mcpToolsCheck(str_contains($handlerSource, 'function index('), 'the MCP page handler must register its index operation');
mcpToolsCheck(str_contains($handlerSource, 'mcpRequest'), 'the MCP page handler must dispatch to the real plugin method');

fwrite(STDOUT, "MCP tools tests passed\n");
