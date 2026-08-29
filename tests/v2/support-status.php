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
    $now = 1_000_000_000;
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

    // --- Source-level checks for the service-auth + wiring in the plugin/handler ---
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    statusCheck(str_contains($pluginSource, 'function supportStatusRequest'), 'plugin must implement the conversation-bound status endpoint');
    statusCheck(str_contains($pluginSource, 'HTTP_AUTHORIZATION'), 'status endpoint must require a Bearer service token');
    statusCheck(str_contains($pluginSource, 'hash_equals($expected, $provided)'), 'service token comparison must be timing-safe');
    statusCheck(str_contains($pluginSource, "chatwootSupportApiToken"), 'service token must come from per-journal configuration, not a hardcoded value');
    statusCheck(
        str_contains($pluginSource, "if (\$expected === '') {\n            return false;"),
        'missing service token configuration must fail closed'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    statusCheck(str_contains($handlerSource, 'function status('), 'handler must register the status operation');
    statusCheck(
        preg_match('/function status\(\$args, \$request\): JSONMessage\s*\{\s*if \(\(\$_SERVER\[.REQUEST_METHOD.\]/', $handlerSource) === 1,
        'status endpoint must accept POST only'
    );

    fwrite(STDOUT, "Support status API tests passed\n");
}
