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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaginationParams;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\AccountDiagnosticEngine;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SubmissionDiagnosticEngine;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpSupportApiFailureMapper;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\AccountDiagnosticsTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\EscalateSupportTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\PaymentStatusTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\PublicationStatusTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\RequiredActionsTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionDiagnosticsTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionListTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionSupportStatusTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SupportIdentityTool;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\SupportStateMapper;

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

    // ================================================================
    // RequiredActionsTool — must reuse RequiredActionsSerializer::verified()
    // verbatim (REST/MCP equivalence by construction).
    // ================================================================
    $authorRelationship = new ResourceRelationship('submission', 456, ['author'], ['author' => true]);
    $verifiedResult = RequiredActionsTool::handleVerified($authorRelationship, ['submit_revisions'], ['view_status', 'view_required_actions']);
    mcpIdentityCheck($verifiedResult['verified'] === true && $verifiedResult['resourceVerified'] === true, 'the MCP required-actions tool must report verified/resourceVerified=true, same as REST');
    mcpIdentityCheck($verifiedResult['requiredActions'] === ['submit_revisions'], 'the MCP required-actions tool must expose the computed required actions verbatim');
    mcpIdentityCheck(!array_key_exists('evidence', $verifiedResult), 'the MCP required-actions tool must never expose internal relationship evidence, same as REST');

    // ================================================================
    // SubmissionSupportStatusTool — must reuse SubmissionSupportSerializer::verified()
    // verbatim (REST/MCP equivalence by construction).
    // ================================================================
    $supportState = SupportStateMapper::map(1, 3);
    $stateConfidence = SupportStateMapper::confidence(1, 3);
    $supportResult = SubmissionSupportStatusTool::handleVerified(
        $authorRelationship,
        'A Safe Manuscript Title',
        $supportState,
        SupportStateMapper::explain($supportState),
        ['view_status'],
        $stateConfidence
    );
    mcpIdentityCheck($supportResult['verified'] === true && $supportResult['resourceVerified'] === true, 'the MCP submission-support-status tool must report verified/resourceVerified=true, same as REST');
    mcpIdentityCheck($supportResult['supportState'] === $supportState, 'the MCP submission-support-status tool must expose the real normalized support state');
    mcpIdentityCheck($supportResult['stateConfidence'] === $stateConfidence, 'the MCP submission-support-status tool must expose the real state confidence (STA-008)');
    mcpIdentityCheck($supportResult['title'] === 'A Safe Manuscript Title', 'the MCP submission-support-status tool must expose the real title');

    // ================================================================
    // PublicationStatusTool — must reuse PublicationStatusSerializer::verified()
    // verbatim (REST/MCP equivalence by construction).
    // ================================================================
    $notYetPublished = PublicationStatusTool::handleVerified($authorRelationship, 'not_yet_published', null, null, null, ['view_publication_status']);
    mcpIdentityCheck($notYetPublished['status'] === 'not_yet_published' && $notYetPublished['doi'] === null && $notYetPublished['publicUrl'] === null && $notYetPublished['issue'] === null, 'the MCP publication-status tool must report not_yet_published with doi/publicUrl/issue all null, same as REST');

    $published = PublicationStatusTool::handleVerified($authorRelationship, 'published', '10.1234/example', 'https://journal-a.example.com/article/view/456', ['volume' => 12, 'number' => 3, 'year' => 2026], ['view_publication_status']);
    mcpIdentityCheck($published['status'] === 'published' && $published['doi'] === '10.1234/example', 'the MCP publication-status tool must expose the real doi once published');
    mcpIdentityCheck($published['issue'] === ['volume' => 12, 'number' => 3, 'year' => 2026], 'the MCP publication-status tool must expose the real issue metadata once published');

    // ================================================================
    // PaymentStatusTool — must reuse PaymentStatusSerializer verbatim
    // (REST/MCP equivalence by construction).
    // ================================================================
    $paidResult = PaymentStatusTool::handleVerified($authorRelationship, ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'], 'paid', ['view_payment_status']);
    mcpIdentityCheck($paidResult['verified'] === true && $paidResult['status'] === 'paid', 'the MCP payment-status tool must report the real paid status');
    mcpIdentityCheck($paidResult['amount'] === 50.0 && $paidResult['currency'] === 'USD', 'the MCP payment-status tool must expose the real fee amount/currency');

    // ================================================================
    // AccountDiagnosticsTool — must reuse DiagnosticResultSerializer::verified()
    // and the real AccountDiagnosticEngine verbatim (REST/MCP equivalence
    // by construction).
    // ================================================================
    $diagnosis = AccountDiagnosticEngine::diagnose(AccountDiagnosticEngine::SCOPE_ACCOUNT_ACCESS, false, null);
    $diagnosticsResult = AccountDiagnosticsTool::handleVerified($diagnosis, ['view_status']);
    mcpIdentityCheck($diagnosticsResult['verified'] === true && $diagnosticsResult['diagnosed'] === true, 'the MCP account-diagnostics tool must report verified/diagnosed=true, same as REST');
    mcpIdentityCheck($diagnosticsResult['code'] === 'ACCOUNT_ACTIVE', 'the MCP account-diagnostics tool must expose the real diagnostic code');

    // ================================================================
    // SubmissionDiagnosticsTool — must reuse DiagnosticResultSerializer::verified()
    // and the real SubmissionDiagnosticEngine verbatim.
    // ================================================================
    $submissionDiagnosis = SubmissionDiagnosticEngine::diagnoseSubmissionAccess(['author']);
    $submissionDiagnosticsResult = SubmissionDiagnosticsTool::handleVerified($submissionDiagnosis, ['view_status']);
    mcpIdentityCheck($submissionDiagnosticsResult['verified'] === true && $submissionDiagnosticsResult['diagnosed'] === true, 'the MCP submission-diagnostics tool must report verified/diagnosed=true, same as REST');
    mcpIdentityCheck($submissionDiagnosticsResult['status'] === DiagnosticResult::STATUS_CONFIRMED, 'the MCP submission-diagnostics tool must expose the real diagnostic status');

    // ================================================================
    // EscalateSupportTool — advertises its shape only; the plugin's
    // registration closure builds the summary via the real
    // HandoffSummaryFormatter/SupportIdentitySerializer (asserted at the
    // wiring level in tests/v2/mcp-tools.php).
    // ================================================================
    mcpIdentityCheck(EscalateSupportTool::NAME === 'support.escalate', 'the escalate tool must advertise the real support.escalate name, matching the REST ojs_escalate_support equivalent');
    $escalateSchema = EscalateSupportTool::inputSchema();
    mcpIdentityCheck(
        $escalateSchema['required'] === ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId', 'reason'],
        'the escalate tool must require exactly the same conversation tuple plus reason that REST requires'
    );
    mcpIdentityCheck(($escalateSchema['additionalProperties'] ?? true) === false, 'the escalate tool schema must reject unknown arguments');

    // ================================================================
    // SubmissionListTool — must reuse SubmissionListSerializer::unverified()/
    // verified() verbatim (REST/MCP equivalence by construction).
    // ================================================================
    $listUnverified = SubmissionListTool::handle($unverifiedContext);
    mcpIdentityCheck($listUnverified['verified'] === false && $listUnverified['submissions'] === [], 'the MCP list-mine tool must degrade to the same generic empty shape REST uses for an unverified/denied caller');

    $reviewerRelationship = new ResourceRelationship('submission', 789, ['reviewer'], ['reviewer' => true]);
    $listPagination = PaginationParams::parse(20, 0);
    mcpIdentityCheck($listPagination !== null, 'PaginationParams::parse must accept a valid limit/offset pair');
    $listEntries = [
        ['relationship' => $authorRelationship, 'title' => 'Manuscript A', 'supportState' => 'in_review', 'actionRequired' => null],
        ['relationship' => $reviewerRelationship, 'title' => 'Manuscript B', 'supportState' => 'under_review', 'actionRequired' => null],
    ];
    $verifiedContext = SupportApiRequestContext::verifiedWith(
        'corr-1',
        7,
        'v3',
        $unverifiedIdentity,
        new SupportSession('pub-1', 7, 42, 'authenticated_session', 'v3', null, null, null, null, null, null, 1000, 1000, 5000, 9000, null)
    );
    $listVerified = SubmissionListTool::handleVerified($verifiedContext, $listEntries, $listPagination, false);
    mcpIdentityCheck($listVerified['verified'] === true && count($listVerified['submissions']) === 2, 'the MCP list-mine tool must expose exactly the real resolved candidates, same as REST');
    mcpIdentityCheck($listVerified['submissions'][0]['id'] === 456 && $listVerified['submissions'][1]['id'] === 789, 'the MCP list-mine tool must expose the real submission ids from each relationship');
    mcpIdentityCheck($listVerified['pagination'] === ['limit' => 20, 'offset' => 0, 'hasMore' => false], 'the MCP list-mine tool must expose the real pagination window, same as REST');

    fwrite(STDOUT, "MCP identity tests passed\n");
}
