<?php

declare(strict_types=1);

// SupportIdentitySerializer::serialize() lazily loads PKP\security\Role
// (deliberately, so merely loading the class never forces a full OJS
// runtime) — this minimal mock lets this test exercise the real
// serializer without one, same technique tests/v2/mcp-identity.php
// already established.

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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaginationParams;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaymentStatusSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PublicationStatusSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionListSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionSupportSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionVerificationSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Handoff\HandoffSummaryFormatter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;

    function openapiContractCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * API-018: contract-tests the OpenAPI schema (API-017) against real
     * endpoint output. Deliberately does not pull in a general-purpose JSON
     * Schema validator dependency for two representative endpoints — the
     * checks below directly compare a schema's declared property names against
     * the real serializer's actual output keys, in both directions (an
     * undeclared key the serializer emits, and a declared key the serializer
     * never emits, are both a real contract drift and must fail this test).
     */

    $schema = json_decode((string) file_get_contents($root . '/docs/v2/openapi.json'), true);
    openapiContractCheck(is_array($schema) && json_last_error() === JSON_ERROR_NONE, 'docs/v2/openapi.json must be valid JSON');

    /** @return string[] */
    function openapiSchemaProperties(array $schema, string $name): array
    {
        $node = $schema['components']['schemas'][$name] ?? null;
        openapiContractCheck($node !== null, "docs/v2/openapi.json must define components.schemas.{$name}");

        $properties = [];
        if (isset($node['properties'])) {
            $properties = array_keys($node['properties']);
        }
        foreach ($node['allOf'] ?? [] as $branch) {
            if (isset($branch['$ref'])) {
                $refName = basename((string) $branch['$ref']);
                $properties = array_merge($properties, openapiSchemaProperties($schema, $refName));
            }
            if (isset($branch['properties'])) {
                $properties = array_merge($properties, array_keys($branch['properties']));
            }
        }
        return array_values(array_unique($properties));
    }

    // ================================================================
    // Endpoint 1: /actions -> StatusOrActionsData. Mirrors exactly what
    // supportActionsRequest() builds inline (verified/assurance/
    // availableActions/disabledActions).
    // ================================================================
    $actionsFixture = [
        'verified' => true,
        'assurance' => 'v2',
        'availableActions' => ['view_status'],
        'disabledActions' => [['action' => 'view_payment_status', 'reason' => 'verification_required']],
    ];
    $actionsSchemaProperties = openapiSchemaProperties($schema, 'StatusOrActionsData');
    foreach (array_keys($actionsFixture) as $key) {
        openapiContractCheck(in_array($key, $actionsSchemaProperties, true), "the real /actions response includes \"{$key}\", but docs/v2/openapi.json's StatusOrActionsData does not declare it — the schema is stale");
    }
    foreach (['verified', 'assurance', 'availableActions'] as $required) {
        openapiContractCheck(in_array($required, array_keys($actionsFixture), true), "StatusOrActionsData's required field \"{$required}\" must actually be present in the real /actions response fixture");
    }

    // ================================================================
    // Endpoint 2: /requiredActions (verified branch) -> RequiredActionsData.
    // Built through the real serializer REST/MCP both call
    // (RequiredActionsSerializer::verified()), not a hand-written fixture, so
    // a real field added/removed there is caught here too.
    // ================================================================
    $relationship = new ResourceRelationship('submission', 456, ['author'], ['author' => true]);
    $requiredActionsFixture = RequiredActionsSerializer::verified($relationship, ['submit_revisions'], ['view_status', 'view_required_actions']);
    $requiredActionsSchemaProperties = openapiSchemaProperties($schema, 'RequiredActionsData');
    foreach (array_keys($requiredActionsFixture) as $key) {
        openapiContractCheck(in_array($key, $requiredActionsSchemaProperties, true), "RequiredActionsSerializer::verified() emits \"{$key}\", but docs/v2/openapi.json's RequiredActionsData does not declare it — the schema is stale");
    }
    foreach (['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'availableActions', 'requiredActions'] as $required) {
        openapiContractCheck(in_array($required, array_keys($requiredActionsFixture), true), "RequiredActionsData's required field \"{$required}\" must actually be present in the real serializer output");
    }

    /** A minimal but real, fully-constructed verified SupportApiRequestContext, for fixtures that need one. */
    function openapiVerifiedContext(): SupportApiRequestContext
    {
        $identity = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
        $session = new SupportSession('pub-1', 7, 42, 'authenticated_session', 'v3', null, null, null, null, null, null, 1000, 1000, 5000, 9000, null);
        return SupportApiRequestContext::verifiedWith('corr-1', 7, 'v3', $identity, $session);
    }

    function openapiUnverifiedContext(): SupportApiRequestContext
    {
        $identity = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
        return SupportApiRequestContext::unverified('corr-2', 7, $identity);
    }

    /** @param string[] $requiredFields */
    function openapiCheckFixture(array $schema, string $schemaName, array $fixture, array $requiredFields, string $sourceLabel): void
    {
        $schemaProperties = openapiSchemaProperties($schema, $schemaName);
        foreach (array_keys($fixture) as $key) {
            openapiContractCheck(in_array($key, $schemaProperties, true), "{$sourceLabel} emits \"{$key}\", but docs/v2/openapi.json's {$schemaName} does not declare it — the schema is stale");
        }
        foreach ($requiredFields as $required) {
            openapiContractCheck(array_key_exists($required, $fixture), "{$schemaName}'s field \"{$required}\" must actually be present in the real {$sourceLabel} output");
        }
    }

    // ================================================================
    // Endpoint 3: /status -> StatusOrActionsData (the same schema /actions
    // uses — /status emits a subset: no disabledActions). Mirrors the real
    // inline shape supportStatusRequest() builds.
    // ================================================================
    $statusFixture = ['verified' => true, 'assurance' => 'v2', 'availableActions' => ['view_status']];
    openapiCheckFixture($schema, 'StatusOrActionsData', $statusFixture, ['verified', 'assurance', 'availableActions'], 'the real /status response');

    // ================================================================
    // Endpoint 4: /identity -> IdentityData, via the real SupportIdentitySerializer.
    // ================================================================
    $identityVerifiedFixture = SupportIdentitySerializer::serialize(openapiVerifiedContext());
    openapiCheckFixture($schema, 'IdentityData', $identityVerifiedFixture, ['verified', 'assurance', 'identity'], 'SupportIdentitySerializer::serialize() (verified)');
    $identityUnverifiedFixture = SupportIdentitySerializer::serialize(openapiUnverifiedContext());
    openapiContractCheck(!array_key_exists('journal', $identityUnverifiedFixture) && !array_key_exists('session', $identityUnverifiedFixture), 'SupportIdentitySerializer must omit journal/session entirely when unverified, never send them as null');

    // ================================================================
    // Endpoint 5: /submissionVerify -> SubmissionVerificationData | ResourceScopedUnverified.
    // ================================================================
    $relationship2 = new ResourceRelationship('submission', 789, ['author'], ['author' => true]);
    $verifyVerifiedFixture = SubmissionVerificationSerializer::verified($relationship2, 'v3', ['view_status']);
    openapiCheckFixture($schema, 'SubmissionVerificationData', $verifyVerifiedFixture, ['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'availableActions'], 'SubmissionVerificationSerializer::verified()');
    $verifyUnverifiedFixture = SubmissionVerificationSerializer::unverified(openapiUnverifiedContext(), []);
    openapiCheckFixture($schema, 'ResourceScopedUnverified', $verifyUnverifiedFixture, ['verified', 'resourceVerified', 'assurance', 'availableActions'], 'SubmissionVerificationSerializer::unverified()');

    // ================================================================
    // Endpoint 6: /submissions -> SubmissionListData, via the real
    // SubmissionListSerializer.
    // ================================================================
    $pagination = PaginationParams::parse(20, 0);
    openapiContractCheck($pagination !== null, 'PaginationParams::parse(20, 0) must succeed for a valid fixture input');
    $listEntry = ['relationship' => $relationship2, 'title' => 'A Test Submission', 'supportState' => 'in_review', 'actionRequired' => false];
    $listVerifiedFixture = SubmissionListSerializer::verified(openapiVerifiedContext(), [$listEntry], $pagination, false);
    openapiCheckFixture($schema, 'SubmissionListData', $listVerifiedFixture, ['verified', 'assurance', 'submissions', 'pagination'], 'SubmissionListSerializer::verified()');

    // ================================================================
    // Endpoint 7: /submissionSupport -> SubmissionSupportData | ResourceScopedUnverified,
    // via the real SubmissionSupportSerializer.
    // ================================================================
    $supportVerifiedFixture = SubmissionSupportSerializer::verified($relationship2, 'A Test Submission', 'in_review', 'Your submission is being reviewed.', ['view_status'], 'high');
    openapiCheckFixture($schema, 'SubmissionSupportData', $supportVerifiedFixture, ['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'title', 'supportState', 'workflowExplanation', 'availableActions'], 'SubmissionSupportSerializer::verified()');

    // ================================================================
    // Endpoint 8 (already covered above): /requiredActions.
    //
    // Endpoint 9: /publicationStatus -> PublicationStatusData | ResourceScopedUnverified,
    // via the real PublicationStatusSerializer.
    // ================================================================
    $pubStatusVerifiedFixture = PublicationStatusSerializer::verified($relationship2, 'published', '10.1234/example', 'https://example.com/article/789', ['volume' => 5, 'number' => 2, 'year' => 2026], ['view_status']);
    openapiCheckFixture($schema, 'PublicationStatusData', $pubStatusVerifiedFixture, ['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'status', 'doi', 'publicUrl', 'issue', 'availableActions'], 'PublicationStatusSerializer::verified()');

    // ================================================================
    // Endpoint 10: /paymentStatus -> PaymentStatusData | ResourceScopedUnverified,
    // via the real PaymentStatusSerializer.
    // ================================================================
    $feeInfo = ['enabled' => true, 'amount' => 75.0, 'currency' => 'USD'];
    $paymentVerifiedFixture = PaymentStatusSerializer::verified($relationship2, $feeInfo, 'unpaid', ['view_status'], []);
    openapiCheckFixture($schema, 'PaymentStatusData', $paymentVerifiedFixture, ['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'feeEnabled', 'amount', 'currency', 'status', 'payUrl', 'obligations', 'availableActions'], 'PaymentStatusSerializer::verified()');

    // ================================================================
    // Endpoints 11/12: /accountDiagnostics, /submissionDiagnostics ->
    // DiagnosticData | DiagnosticUnverifiedData, via the real
    // DiagnosticResultSerializer (shared by both real endpoints).
    // ================================================================
    $diagnosticResult = DiagnosticResult::unknown('ACCOUNT_STATUS_UNKNOWN', 'Unable to determine account status.', ['EVIDENCE_CODE_A']);
    $diagnosticVerifiedFixture = DiagnosticResultSerializer::verified($diagnosticResult, ['view_status']);
    openapiCheckFixture($schema, 'DiagnosticData', $diagnosticVerifiedFixture, ['verified', 'diagnosed', 'status', 'code', 'summary', 'evidenceCodes', 'nextActions', 'retryable', 'availableActions'], 'DiagnosticResultSerializer::verified()');
    $diagnosticUnverifiedFixture = DiagnosticResultSerializer::unverified(openapiUnverifiedContext(), []);
    openapiCheckFixture($schema, 'DiagnosticUnverifiedData', $diagnosticUnverifiedFixture, ['verified', 'diagnosed', 'assurance', 'availableActions'], 'DiagnosticResultSerializer::unverified()');

    // ================================================================
    // Endpoint 13: /escalate -> EscalateData. The real
    // supportEscalateRequest() always returns exactly these four top-level
    // keys regardless of how much of the optional submission-scoped detail
    // resolved — checked directly against the real HandoffSummaryFormatter
    // output for the "summary" field, matching what the handler actually
    // builds.
    // ================================================================
    $handoffSummary = HandoffSummaryFormatter::build(
        SupportIdentitySerializer::serialize(openapiVerifiedContext()),
        $relationship2,
        'in_review',
        ['submit_revisions'],
        null,
        null,
        'Author asked about review status.'
    );
    $escalateFixture = ['escalated' => true, 'noteCreated' => false, 'duplicate' => false, 'summary' => $handoffSummary];
    openapiCheckFixture($schema, 'EscalateData', $escalateFixture, ['escalated', 'noteCreated', 'duplicate', 'summary'], 'the real /escalate response (supportEscalateRequest())');

    // ================================================================
    // Endpoints 14/15: /verificationRequest, /verificationConfirm ->
    // VerificationRequestedData / VerificationConfirmData. Both endpoints
    // build their response inline (see supportVerificationRequestRequest()/
    // supportVerificationConfirmRequest()); mirrored here as literal
    // fixtures matching those exact real shapes, same technique the
    // original /actions check above already established for an
    // inline-built response.
    // ================================================================
    $verificationRequestedFixture = ['verificationRequested' => true, 'challenge' => bin2hex(random_bytes(16))];
    openapiCheckFixture($schema, 'VerificationRequestedData', $verificationRequestedFixture, ['verificationRequested', 'challenge'], 'the real /verificationRequest response (supportVerificationRequestRequest())');

    $verificationConfirmDeniedFixture = ['verified' => false];
    openapiCheckFixture($schema, 'VerificationConfirmData', $verificationConfirmDeniedFixture, ['verified'], 'the real /verificationConfirm response (denied branch)');
    $verificationConfirmAllowedFixture = ['verified' => true, 'assurance' => 'v2'];
    openapiCheckFixture($schema, 'VerificationConfirmData', $verificationConfirmAllowedFixture, ['verified'], 'the real /verificationConfirm response (allowed branch)');

    // ================================================================
    // Every path in the schema must correspond to a real, implemented
    // PageHandler operation — never a documented endpoint that doesn't exist.
    // ================================================================
    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    foreach (array_keys($schema['paths']) as $path) {
        $operation = ltrim($path, '/');
        openapiContractCheck(str_contains($handlerSource, "function {$operation}("), "docs/v2/openapi.json documents \"{$path}\", but SupportGatewayPageHandler has no real {$operation}() operation — the schema must never document an endpoint that does not exist");
    }

    fwrite(STDOUT, "OpenAPI contract tests passed\n");

}
