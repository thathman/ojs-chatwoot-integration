<?php

declare(strict_types=1);

namespace PKP\security {
    final class Role
    {
        public const ROLE_ID_SITE_ADMIN = 1;
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_SUB_EDITOR = 17;
        public const ROLE_ID_ASSISTANT = 4097;
        public const ROLE_ID_AUTHOR = 65538;
        public const ROLE_ID_REVIEWER = 65536;
        public const ROLE_ID_READER = 1048576;
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpSupportApiFailureMapper;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SupportIdentityTool;

    function mcpIdentityCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // McpSupportApiFailureMapper — every real SupportApiErrorCode must map
    // to a specific, correct McpErrorCode, never a blanket fallback that
    // would hide a real distinction (e.g. rate-limited vs. unauthorized).
    // ================================================================
    $cases = [
        [SupportApiErrorCode::AUTHENTICATION_FAILED, McpErrorCode::UNAUTHORIZED],
        [SupportApiErrorCode::VALIDATION_ERROR, McpErrorCode::INVALID_PARAMS],
        [SupportApiErrorCode::RATE_LIMITED, McpErrorCode::RATE_LIMITED],
        [SupportApiErrorCode::CAPABILITY_DENIED, McpErrorCode::UNAUTHORIZED],
        [SupportApiErrorCode::INTERNAL_ERROR, McpErrorCode::INTERNAL_ERROR],
        ['SOME_FUTURE_CODE_THIS_MAPPER_DOES_NOT_KNOW', McpErrorCode::INTERNAL_ERROR],
    ];
    foreach ($cases as [$restCode, $expectedMcpCode]) {
        $failure = new SupportApiFailure($restCode, 'a real message', 400, 'corr-1');
        $mapped = McpSupportApiFailureMapper::toHandlerError($failure);
        mcpIdentityCheck($mapped->mcpErrorCode() === $expectedMcpCode, "SupportApiErrorCode::{$restCode} must map to McpErrorCode {$expectedMcpCode}");
        mcpIdentityCheck($mapped->getMessage() === 'a real message', 'the mapped error must preserve the real failure message, never a generic placeholder');
    }

    // ================================================================
    // SupportIdentityTool — must reuse SupportIdentitySerializer::serialize()
    // verbatim (REST/MCP equivalence by construction, not by convention).
    // ================================================================
    $unverifiedIdentity = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
    $unverifiedContext = SupportApiRequestContext::unverified('corr-1', 7, $unverifiedIdentity);
    $unverifiedResult = SupportIdentityTool::handle($unverifiedContext);
    mcpIdentityCheck($unverifiedResult['verified'] === false, 'the MCP identity tool must report verified=false for an unverified context, same as REST');
    mcpIdentityCheck(!array_key_exists('journal', $unverifiedResult) && !array_key_exists('session', $unverifiedResult), 'the MCP identity tool must never leak journal/session detail for an unverified context, same as REST');

    fwrite(STDOUT, "MCP identity tests passed\n");
}
