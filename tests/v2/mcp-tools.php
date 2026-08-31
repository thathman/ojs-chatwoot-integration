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
mcpToolsCheck(!str_contains($mcpMethodBody, "'chatwootSupportApiToken'") && !str_contains($mcpMethodBody, "'chatwootApiAccessToken'"), 'mcpRequest() must never authenticate against a Chatwoot/Support-API token setting');
mcpToolsCheck(str_contains($mcpMethodBody, 'McpAuthenticator::verify('), 'mcpRequest() must authenticate via McpAuthenticator');
mcpToolsCheck(
    strpos($mcpMethodBody, 'McpAuthenticator::verify(') < strpos($mcpMethodBody, 'McpRequestParser::parse('),
    'authentication must happen before the request body is ever parsed, so an unauthenticated caller can never use parse errors as a probing oracle'
);
mcpToolsCheck(str_contains($mcpMethodBody, JournalProfileTool::class) || str_contains($mcpMethodBody, 'JournalProfileTool'), 'mcpRequest() must register the real JournalProfileTool, not a placeholder');
mcpToolsCheck(str_contains($mcpMethodBody, 'SubmissionPolicyTool'), 'mcpRequest() must register the real SubmissionPolicyTool');
mcpToolsCheck(str_contains($mcpMethodBody, 'FeePolicyTool'), 'mcpRequest() must register the real FeePolicyTool');

$handlerSource = (string) file_get_contents($root . '/classes/v2/Http/McpGatewayPageHandler.php');
mcpToolsCheck(str_contains($handlerSource, 'function index('), 'the MCP page handler must register its index operation');
mcpToolsCheck(str_contains($handlerSource, 'mcpRequest'), 'the MCP page handler must dispatch to the real plugin method');

fwrite(STDOUT, "MCP tools tests passed\n");
