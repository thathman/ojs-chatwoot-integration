<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpHandlerError;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResourceRegistry;

function mcpResourcesCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// MCP-004: McpResourceRegistry — mirrors McpToolRegistry's shape and
// non-leak guarantee exactly.
// ================================================================
$registry = new McpResourceRegistry();
mcpResourcesCheck($registry->list() === [], 'a fresh registry must advertise no resources');
mcpResourcesCheck(!$registry->has('ojs://journal/7/support-profile'), 'a fresh registry must not report an unregistered resource as present');

$registry->register(
    'ojs://journal/7/support-profile',
    'Journal support profile',
    'Public journal.* support facts.',
    'application/json',
    fn (): array => ['journal.name' => 'A Safe Journal']
);

$listed = $registry->list();
mcpResourcesCheck(count($listed) === 1, 'list() must report exactly the one registered resource');
mcpResourcesCheck(
    $listed[0] === ['uri' => 'ojs://journal/7/support-profile', 'name' => 'Journal support profile', 'description' => 'Public journal.* support facts.', 'mimeType' => 'application/json'],
    'list() must expose exactly uri/name/description/mimeType, never the content handler itself'
);
foreach ($listed[0] as $value) {
    mcpResourcesCheck(!is_callable($value), 'list() must never leak a resource\'s handler through its advertised metadata (MCP-011)');
}

$read = $registry->read('ojs://journal/7/support-profile');
mcpResourcesCheck($read['uri'] === 'ojs://journal/7/support-profile' && $read['mimeType'] === 'application/json', 'read() must echo back the real uri/mimeType');
mcpResourcesCheck(json_decode($read['text'], true) === ['journal.name' => 'A Safe Journal'], 'read() must serialize the handler\'s real content, never a bespoke re-shaping');

try {
    $registry->read('ojs://journal/7/does-not-exist');
    mcpResourcesCheck(false, 'reading an unregistered resource must throw, never silently return an empty/guessed result');
} catch (McpHandlerError $e) {
    mcpResourcesCheck($e->mcpErrorCode() === McpErrorCode::UNKNOWN_TOOL_OR_RESOURCE, 'an unknown resource must fail with UNKNOWN_TOOL_OR_RESOURCE, same code an unknown tool uses');
}

// ================================================================
// Wiring: the plugin's mcpRequest() must register real resources/list and
// resources/read handlers, sourced only from the Knowledge Compiler
// (never submission/user-specific live state as a public static resource,
// per ADR-023).
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function mcpRequest(');
mcpResourcesCheck($methodStart !== false, 'plugin must implement mcpRequest()');
$nextMethodStart = strpos($pluginSource, "\n    public function", $methodStart + 1);
$mcpMethodBody = $nextMethodStart !== false ? substr($pluginSource, $methodStart, $nextMethodStart - $methodStart) : substr($pluginSource, $methodStart);

mcpResourcesCheck(str_contains($mcpMethodBody, 'McpProtocol::METHOD_RESOURCES_LIST'), 'mcpRequest() must register a real resources/list handler');
mcpResourcesCheck(str_contains($mcpMethodBody, 'McpProtocol::METHOD_RESOURCES_READ'), 'mcpRequest() must register a real resources/read handler');
mcpResourcesCheck(str_contains($mcpMethodBody, 'new McpResourceRegistry()'), 'mcpRequest() must build a real McpResourceRegistry, never a bespoke ad-hoc list');
mcpResourcesCheck(substr_count($mcpMethodBody, "ojs://journal/{\$contextId}/") >= 3, 'the resource hierarchy must be journal-scoped under ojs://journal/{contextId}/..., per ADR-023');
mcpResourcesCheck(
    str_contains($mcpMethodBody, 'JournalProfileTool::handle($compilation)') && str_contains($mcpMethodBody, 'SubmissionPolicyTool::handle($compilation)') && str_contains($mcpMethodBody, 'FeePolicyTool::handle($compilation)'),
    'every resource must source its content from the real Knowledge Compiler tools, never a second parallel path to journal/submission/fee facts'
);
mcpResourcesCheck(
    !preg_match('/resources\/read.*?loadSubmission|resources\/read.*?getSupportStateFor/s', $mcpMethodBody),
    'resources/read must never expose submission/user-specific live state as a public static resource, per ADR-023'
);

fwrite(STDOUT, "MCP resources tests passed\n");
