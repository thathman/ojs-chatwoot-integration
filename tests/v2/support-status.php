<?php

declare(strict_types=1);

namespace PKP\db {
    final class DAORegistry
    {
        public static function getDAO(string $name): object
        {
            return new class {
                public function getCurrentVersion(): object
                {
                    return new class {
                        public function getVersionString(): string { return '3.5.0.0'; }
                    };
                }
            };
        }
    }
}

namespace PKP\user {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $usersById = [];

        public static function user(): self { return new self(); }

        public function get(int $id): ?object
        {
            return self::$usersById[$id] ?? null;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

    function statusCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeRole
    {
        public function __construct(private int $id) {}
        public function getId(): int { return $this->id; }
    }

    final class FakeUser
    {
        public function __construct(private int $id, private array $roleIds) {}
        public function getId(): int { return $this->id; }
        public function getRoles(int $contextId): array
        {
            return array_map(static fn (int $id) => new FakeRole($id), $this->roleIds);
        }
    }

    final class FakeContext
    {
        public function getId(): int { return 7; }
        public function getPath(): string { return 'journal-a'; }
    }

    final class FakeRequest
    {
        public function getContext(): object { return new FakeContext(); }
        public function getUser(): ?object { return null; }
        public function getRequestedPage(): string { return 'ojsSupportGateway'; }
        public function getRequestedOp(): string { return 'status'; }
    }

    // Author role ID 65538 (Author) per PKP\security\Role::ROLE_ID_AUTHOR.
    \PKP\user\Repo::$usersById[42] = new FakeUser(42, [65538]);

    final class InMemorySupportSessionRepository implements SupportSessionRepositoryInterface
    {
        /** @var array<string,SupportSession> */
        public array $sessions = [];

        public function create(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
        public function save(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
        public function findByPublicId(string $publicId): ?SupportSession { return $this->sessions[$publicId] ?? null; }

        public function claimBindingToken(
            string $bindingTokenHash,
            int $contextId,
            int $userId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId,
            int $now,
            int $idleExpiresAt
        ): ?SupportSession {
            foreach ($this->sessions as $publicId => $session) {
                if (
                    $session->contextId() !== $contextId
                    || $session->userId() !== $userId
                    || $session->bindingTokenHash() !== $bindingTokenHash
                    || !$session->bindingAvailable($now)
                ) {
                    continue;
                }

                foreach ($this->sessions as $otherId => $other) {
                    if (
                        $otherId !== $publicId
                        && !$other->isRevoked()
                        && $other->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)
                    ) {
                        $this->sessions[$otherId] = $other->revoked($now);
                    }
                }

                $bound = $session->withConversationBinding(
                    $chatwootAccountId,
                    $chatwootContactId,
                    $chatwootConversationId,
                    $now,
                    min($idleExpiresAt, $session->absoluteExpiresAt())
                );
                $this->sessions[$publicId] = $bound;
                return $bound;
            }
            return null;
        }

        public function findByConversationBinding(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId
        ): ?SupportSession {
            $matches = [];
            foreach ($this->sessions as $session) {
                if (
                    !$session->isRevoked()
                    && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)
                ) {
                    $matches[] = $session;
                }
            }
            usort($matches, fn (SupportSession $a, SupportSession $b): int => $b->createdAt() <=> $a->createdAt());
            return $matches[0] ?? null;
        }

        public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void {}
        public function purgeExpired(int $now): int { return 0; }
    }

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    statusCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    // --- Scenario: no bound session -> generic unverified status, baseline-only actions ---
    $noSession = $bridge->resolveBoundSupportSession(7, '1', '100', '500');
    statusCheck($noSession === null, 'unknown conversation tuple must not resolve any session');

    $unverifiedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v0',
        $baseContext
    ));
    statusCheck($unverifiedDecision !== null, 'capability evaluation must succeed for an unauthenticated context');
    $unverifiedActions = $bridge->availableActions($unverifiedDecision);
    statusCheck(
        $unverifiedActions === ['contact_editorial_office', 'view_journal_information'],
        'unverified status must only expose baseline public actions'
    );
    statusCheck(
        !in_array('list_my_submissions', $unverifiedActions, true),
        'unverified status must never expose account-scoped actions'
    );

    // --- Scenario: bound, live author session -> verified with author-scoped actions ---
    $repo = new InMemorySupportSessionRepository();
    // Real current time, not an arbitrary fixed epoch: the resolver end-to-end
    // checks below call the real SupportSession::isExpired(time()), so a
    // session bound relative to a stale fake "now" would appear expired.
    $now = time();
    $service = new SupportSessionService($repo, static fn (): int => $now);

    $context42 = new \APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $bootstrap = $service->bootstrapAuthenticated($context42);
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    statusCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    // Swap the repository into the same kernel instance the bridge already built.
    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $liveSession = $bridge->resolveBoundSupportSession(7, '1', '100', '500');
    statusCheck($liveSession !== null, 'bound conversation should resolve the live session');
    statusCheck(!$liveSession->isExpired($now), 'freshly bound session must not be expired');

    $freshContext = $bridge->resolveContextForUser(new FakeRequest(), $liveSession->userId(), 'en');
    statusCheck($freshContext !== null, 'authoritative user ID should re-resolve a live context');
    statusCheck($freshContext->isAuthenticated(), 'resolved context must be authenticated');
    statusCheck($freshContext->roleIds() === [65538], 'roles must be re-derived live, not trusted from the session record');

    $verifiedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $liveSession->assuranceLevel(),
        $freshContext
    ));
    $verifiedActions = $bridge->availableActions($verifiedDecision);
    statusCheck(
        in_array('list_my_submissions', $verifiedActions, true),
        'verified authenticated identity should expose account-scoped actions'
    );
    statusCheck(
        !in_array('view_status', $verifiedActions, true),
        'submission-specific actions must stay denied without a resource relationship'
    );

    // --- Scenario: wrong conversation tuple must not resolve the session ---
    statusCheck(
        $bridge->resolveBoundSupportSession(7, '1', '100', '999') === null,
        'a different conversation ID must not resolve an unrelated bound session'
    );
    statusCheck(
        $bridge->resolveBoundSupportSession(8, '1', '100', '500') === null,
        'a different journal context must not resolve a session bound to another journal'
    );

    // --- SupportApiRequestResolver end-to-end (catches wiring/argument-order bugs the source checks below cannot) ---
    $_SERVER['HTTPS'] = 'on';
    $resolver = new SupportApiRequestResolver($bridge);

    $_SERVER['HTTP_AUTHORIZATION'] = '';
    $noAuth = $resolver->resolve(new FakeRequest(), 'corr-a', 7, 'service-secret', '1', '100', '500', 'status');
    statusCheck($noAuth instanceof SupportApiFailure, 'missing service token must produce a real failure, not a generic unverified success');
    statusCheck($noAuth->httpStatus === 401, 'missing service token must fail with 401');
    statusCheck($noAuth->correlationId === 'corr-a', 'failure must preserve the caller-visible correlation ID');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-secret';
    $wrongAuth = $resolver->resolve(new FakeRequest(), 'corr-b', 7, 'service-secret', '1', '100', '500', 'status');
    statusCheck($wrongAuth instanceof SupportApiFailure && $wrongAuth->httpStatus === 401, 'wrong service token must fail with 401');

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $missingTuple = $resolver->resolve(new FakeRequest(), 'corr-c', 7, 'service-secret', '', '100', '500', 'status');
    statusCheck($missingTuple instanceof SupportApiFailure && $missingTuple->httpStatus === 400, 'a malformed conversation tuple must fail with 400, not 500 or 200');

    $unverifiedResult = $resolver->resolve(new FakeRequest(), 'corr-d', 7, 'service-secret', '1', '100', '999', 'status');
    statusCheck(
        !($unverifiedResult instanceof SupportApiFailure) && $unverifiedResult->verified() === false,
        'an unknown conversation tuple must resolve to a generic unverified context, not an error'
    );

    $verifiedResult = $resolver->resolve(new FakeRequest(), 'corr-e', 7, 'service-secret', '1', '100', '500', 'status');
    statusCheck(
        !($verifiedResult instanceof SupportApiFailure) && $verifiedResult->verified() === true,
        'the actually-bound conversation must resolve as verified through the resolver'
    );
    statusCheck($verifiedResult->identity()->roleIds() === [65538], 'resolver-produced identity must carry live re-derived roles');

    unset($_SERVER['HTTPS']);
    $insecure = $resolver->resolve(new FakeRequest(), 'corr-f', 7, 'service-secret', '1', '100', '500', 'status');
    statusCheck($insecure instanceof SupportApiFailure, 'a plain-HTTP request must be rejected before service auth is even checked');
    $_SERVER['HTTPS'] = 'on';

    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    unset($_SERVER['HTTPS']);
    $viaProxy = $resolver->resolve(new FakeRequest(), 'corr-g', 7, 'service-secret', '1', '100', '500', 'status');
    statusCheck(!($viaProxy instanceof SupportApiFailure), 'a reverse-proxy-terminated HTTPS connection (X-Forwarded-Proto) must be accepted');
    unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_AUTHORIZATION']);

    // --- Source-level checks for wiring in the plugin/handler ---
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    statusCheck(str_contains($pluginSource, 'function supportStatusRequest'), 'plugin must implement the conversation-bound status endpoint');
    statusCheck(str_contains($pluginSource, 'function supportIdentityRequest'), 'plugin must implement the identity endpoint');
    statusCheck(str_contains($pluginSource, 'function supportActionsRequest'), 'plugin must implement the actions endpoint');
    statusCheck(str_contains($pluginSource, 'chatwootSupportApiToken'), 'service token must come from per-journal configuration, not a hardcoded value');
    statusCheck(str_contains($pluginSource, 'SupportApiRequestResolver'), 'endpoints must share the same request-resolution pipeline');
    statusCheck(str_contains($pluginSource, 'SupportApiResponse::success'), 'endpoints must emit through the shared Support API responder');

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    statusCheck(str_contains($handlerSource, 'function status('), 'handler must register the status operation');
    statusCheck(str_contains($handlerSource, 'function identity('), 'handler must register the identity operation');
    statusCheck(str_contains($handlerSource, 'function actions('), 'handler must register the actions operation');
    statusCheck(
        substr_count($handlerSource, '$this->requirePost();') === 10,
        'status/identity/actions/accountDiagnostics/submissionVerify/submissions/submissionSupport/requiredActions/publicationStatus/paymentStatus must all be POST-only'
    );
    statusCheck(str_contains($handlerSource, "function bind(\$args, \$request): JSONMessage"), '/bind must keep the PKP JSONMessage transport for the browser handshake');

    fwrite(STDOUT, "Support status API tests passed\n");
}
