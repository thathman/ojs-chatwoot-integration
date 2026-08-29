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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Http\RateLimiter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ServiceTokenAuthenticator;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\AvailableActionMapper;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityDecision;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;

    function apiIdentityCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // --- ServiceTokenAuthenticator ---
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('secret-a', 'Bearer secret-a') === true,
        'matching bearer token should authenticate'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('secret-a', 'Bearer wrong') === false,
        'mismatched token must fail closed'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('', 'Bearer secret-a') === false,
        'unconfigured token must fail closed even if a header is supplied'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('secret-a', null) === false,
        'missing Authorization header must fail closed'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('secret-a', 'Basic secret-a') === false,
        'non-Bearer scheme must be rejected'
    );
    // Rotation: two tokens configured, either the new or old should authenticate.
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('new-secret,old-secret', 'Bearer old-secret') === true,
        'rotation must accept the old token while both are configured'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('new-secret,old-secret', 'Bearer new-secret') === true,
        'rotation must accept the new token while both are configured'
    );
    apiIdentityCheck(
        ServiceTokenAuthenticator::verify('new-secret,old-secret', 'Bearer stale') === false,
        'a token outside the configured rotation set must still fail closed'
    );

    // --- RateLimiter (fails open without APCu, which the CLI test runner has none of) ---
    $limiter = new RateLimiter(2, 60);
    apiIdentityCheck($limiter->allow('k1') === true, 'rate limiter must allow when APCu is unavailable (fail-open by design)');

    // --- AvailableActionMapper::mapDenied ---
    $decision = new CapabilityDecision(
        ['journal.read_public_info'],
        [
            'submission.list_own' => 'authentication_required',
            'submission.read_own_support_status' => 'relationship_required',
            'submission.read_own_payment_status' => 'feature_unavailable',
            'review.read_own_assignment' => 'provider_not_enabled',
            'support.escalate' => 'unknown_capability',
        ]
    );
    $mapper = new AvailableActionMapper();
    $disabled = $mapper->mapDenied($decision);
    $disabledActions = array_column($disabled, 'action');
    sort($disabledActions);
    apiIdentityCheck(
        $disabledActions === ['list_my_submissions', 'view_payment_status', 'view_status'],
        'disabled actions must surface only safe, actionable denial reasons'
    );
    apiIdentityCheck(
        !in_array('view_review_assignment', $disabledActions, true),
        'internal plumbing denial reasons (provider_not_enabled) must not be surfaced to Captain'
    );

    // --- SupportIdentitySerializer ---
    $unverifiedIdentity = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
    $unverifiedContext = SupportApiRequestContext::unverified('corr-1', 7, $unverifiedIdentity);
    $serializedUnverified = SupportIdentitySerializer::serialize($unverifiedContext);
    apiIdentityCheck($serializedUnverified['verified'] === false, 'unverified identity payload must say verified=false');
    apiIdentityCheck(!array_key_exists('journal', $serializedUnverified), 'unverified identity payload must not leak journal detail');
    apiIdentityCheck(!array_key_exists('session', $serializedUnverified), 'unverified identity payload must not leak session detail');

    $verifiedIdentity = new SupportContext(7, 'journal-a', 42, [65538, 65536], 'index', 'index', 'en');
    $session = new SupportSession(
        'pub-1',
        7,
        42,
        'authenticated_session',
        'v2',
        null,
        null,
        1000,
        '1',
        '100',
        '500',
        1000,
        1000,
        5000,
        9000,
        null
    );
    $verifiedContext = SupportApiRequestContext::verifiedWith('corr-2', 7, 'v2', $verifiedIdentity, $session);
    $serializedVerified = SupportIdentitySerializer::serialize($verifiedContext);
    apiIdentityCheck($serializedVerified['verified'] === true, 'verified identity payload must say verified=true');
    apiIdentityCheck($serializedVerified['assurance'] === 'v2', 'verified identity payload must carry assurance level');
    apiIdentityCheck($serializedVerified['identity']['authenticated'] === true, 'verified identity payload must say authenticated=true');
    apiIdentityCheck(
        $serializedVerified['identity']['roles'] === ['author', 'reviewer'],
        'roles must be mapped to broad live labels, normalized and sorted'
    );
    apiIdentityCheck($serializedVerified['journal']['id'] === 7, 'verified identity payload should include journal id');
    apiIdentityCheck($serializedVerified['journal']['path'] === 'journal-a', 'verified identity payload should include journal path');
    apiIdentityCheck($serializedVerified['session']['method'] === 'authenticated_session', 'verified identity payload should include verification method');
    apiIdentityCheck($serializedVerified['session']['expiresAt'] === gmdate('c', 5000), 'verified identity payload should include session expiry');

    $serializedJson = json_encode($serializedVerified);
    apiIdentityCheck(
        $serializedJson !== false && !str_contains(strtolower($serializedJson), 'email'),
        'identity payload must never include an email field'
    );
    apiIdentityCheck(
        !str_contains($serializedJson, 'pub-1'),
        'identity payload must never leak the internal support-session public ID'
    );

    // --- Source-level checks ---
    $responseSource = (string) file_get_contents($root . '/classes/v2/Api/SupportApiResponse.php');
    apiIdentityCheck(str_contains($responseSource, "'ok' => true"), 'success envelope must use the ok/data/meta shape');
    apiIdentityCheck(str_contains($responseSource, "'ok' => false"), 'error envelope must use the ok/error/meta shape');
    apiIdentityCheck(str_contains($responseSource, 'Cache-Control: no-store'), 'Support API responses must not be cached');
    apiIdentityCheck(str_contains($responseSource, 'apiVersion'), 'envelope meta must carry an API version');

    $resolverSource = (string) file_get_contents($root . '/classes/v2/Api/SupportApiRequestResolver.php');
    apiIdentityCheck(str_contains($resolverSource, 'ServiceTokenAuthenticator::verify'), 'resolver must authenticate via the shared token authenticator');
    apiIdentityCheck(str_contains($resolverSource, 'rateLimiter->allow'), 'resolver must apply the shared rate limiter');
    apiIdentityCheck(str_contains($resolverSource, 'audit->record'), 'resolver must record allow/deny decisions through the audit seam');
    apiIdentityCheck(
        substr_count($resolverSource, 'SupportApiRequestContext::unverified') >= 1,
        'unresolved/expired/mismatched conversations must collapse into the same generic unverified result'
    );

    $auditSource = (string) file_get_contents($root . '/classes/v2/Audit/ErrorLogSupportApiAuditLogger.php');
    apiIdentityCheck(
        !str_contains($auditSource, 'bindingToken') && !str_contains($auditSource, 'plaintext'),
        'audit sink must never reference raw secrets'
    );

    fwrite(STDOUT, "Support API identity/actions unit tests passed\n");
}
