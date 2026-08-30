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

namespace PKP\security {
    final class Role
    {
        public const ROLE_ID_SITE_ADMIN = 1;
        public const ROLE_ID_MANAGER = 16;
        public const ROLE_ID_AUTHOR = 65538;
        public const ROLE_ID_REVIEWER = 65536;
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\AccountDiagnosticEngine;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

    function accountDiagnosticsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeUser
    {
        public function __construct(private int $id, private array $roleIds, private ?bool $disabled = false, private ?string $dateValidated = '2026-01-01 00:00:00') {}
        public function getId(): int { return $this->id; }
        public function getRoles(int $contextId): array
        {
            return array_map(static fn (int $id) => new FakeRole($id), $this->roleIds);
        }
        public function getDisabled(): ?bool { return $this->disabled; }
        public function getDateValidated(): ?string { return $this->dateValidated; }
    }

    final class FakeRole
    {
        public function __construct(private int $id) {}
        public function getId(): int { return $this->id; }
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
        public function getRequestedOp(): string { return 'accountDiagnostics'; }
    }

    // ================================================================
    // Part 1: AccountDiagnosticEngine — deterministic, evidence-only.
    // ================================================================
    // Determinism: same evidence, same diagnosis, every time.
    $first = AccountDiagnosticEngine::diagnose('account_access', true, null);
    $second = AccountDiagnosticEngine::diagnose('account_access', true, null);
    accountDiagnosticsCheck($first->code() === $second->code() && $first->status() === $second->status(), 'the same evidence must always produce the same diagnosis');

    // account_access
    $disabled = AccountDiagnosticEngine::diagnose('account_access', true, null);
    accountDiagnosticsCheck($disabled->status() === DiagnosticResult::STATUS_CONFIRMED && $disabled->code() === 'ACCOUNT_DISABLED', 'disabled=true must confirm ACCOUNT_DISABLED');
    accountDiagnosticsCheck(in_array('contact_editorial_office', $disabled->nextActions(), true), 'a disabled account must suggest contacting the editorial office');
    $active = AccountDiagnosticEngine::diagnose('account_access', false, null);
    accountDiagnosticsCheck($active->status() === DiagnosticResult::STATUS_CONFIRMED && $active->code() === 'ACCOUNT_ACTIVE', 'disabled=false must confirm ACCOUNT_ACTIVE');
    $unknownAccess = AccountDiagnosticEngine::diagnose('account_access', null, null);
    accountDiagnosticsCheck($unknownAccess->status() === DiagnosticResult::STATUS_UNKNOWN, 'a missing disabled field must never be guessed either way');

    // login — always confirms the present, never a past failure
    $login = AccountDiagnosticEngine::diagnose('login', false, '2026-01-01 00:00:00');
    accountDiagnosticsCheck($login->status() === DiagnosticResult::STATUS_CONFIRMED && $login->code() === 'LOGIN_OK', 'reaching this diagnostic at all is itself proof login currently works');

    // password_reset — no evidence source exists, must always be unknown
    $passwordReset = AccountDiagnosticEngine::diagnose('password_reset', false, '2026-01-01 00:00:00');
    accountDiagnosticsCheck($passwordReset->status() === DiagnosticResult::STATUS_UNKNOWN, 'password reset delivery/validity has no OJS evidence and must never be guessed');

    // profile — a present dateValidated confirms; absence is ambiguous, must stay unknown
    $validated = AccountDiagnosticEngine::diagnose('profile', false, '2026-01-01 00:00:00');
    accountDiagnosticsCheck($validated->status() === DiagnosticResult::STATUS_CONFIRMED && $validated->code() === 'EMAIL_VALIDATED', 'a present dateValidated must confirm EMAIL_VALIDATED');
    $unvalidated = AccountDiagnosticEngine::diagnose('profile', false, null);
    accountDiagnosticsCheck($unvalidated->status() === DiagnosticResult::STATUS_UNKNOWN, 'a null dateValidated is ambiguous (predates the field, or admin-created) and must not be confirmed as a problem');

    // Unknown/unrecognized scope must not fabricate anything either
    $unknownScope = AccountDiagnosticEngine::diagnose('some_future_scope_this_engine_does_not_know_about', false, '2026-01-01 00:00:00');
    accountDiagnosticsCheck($unknownScope->status() === DiagnosticResult::STATUS_UNKNOWN, 'an unrecognized scope must return unknown, never guess a diagnosis for it');

    // ================================================================
    // Part 2: DiagnosticResult — status validation, exceptions/provider
    // failures must become unknown, never a fabricated cause.
    // ================================================================
    $threw = false;
    try {
        new DiagnosticResult('not_a_real_status', 'X', 'x');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    accountDiagnosticsCheck($threw, 'DiagnosticResult must reject an unrecognized status rather than silently accept it');

    // ================================================================
    // Part 3: DiagnosticResultSerializer — allowlist shape and leak checks.
    // Every known diagnostic code must round-trip cleanly through it.
    // ================================================================
    foreach ([
        AccountDiagnosticEngine::diagnose('account_access', true, null),
        AccountDiagnosticEngine::diagnose('account_access', false, null),
        AccountDiagnosticEngine::diagnose('account_access', null, null),
        AccountDiagnosticEngine::diagnose('login', false, null),
        AccountDiagnosticEngine::diagnose('password_reset', false, null),
        AccountDiagnosticEngine::diagnose('profile', false, '2026-01-01 00:00:00'),
        AccountDiagnosticEngine::diagnose('profile', false, null),
    ] as $result) {
        $payload = DiagnosticResultSerializer::verified($result, ['view_status']);
        accountDiagnosticsCheck($payload['verified'] === true, "serialized payload for code {$result->code()} must say verified=true");
        accountDiagnosticsCheck($payload['diagnosed'] === true, "serialized payload for code {$result->code()} must say diagnosed=true");
        accountDiagnosticsCheck($payload['code'] === $result->code(), "serialized payload must preserve the diagnostic code {$result->code()}");
        accountDiagnosticsCheck(is_array($payload['evidenceCodes']), "serialized payload for code {$result->code()} must expose evidenceCodes as an array");
        accountDiagnosticsCheck(!array_key_exists('rawUser', $payload) && !array_key_exists('user', $payload), "serialized payload for code {$result->code()} must never expose a raw user object");
    }

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = DiagnosticResultSerializer::unverified($unverifiedApiContext, ['list_my_submissions']);
    accountDiagnosticsCheck($unverifiedPayload['diagnosed'] === false, 'unverified payload must say diagnosed=false');
    accountDiagnosticsCheck(!array_key_exists('status', $unverifiedPayload), 'unverified payload must never expose a diagnostic status');
    accountDiagnosticsCheck(!array_key_exists('code', $unverifiedPayload), 'unverified payload must never expose a diagnostic code');
    accountDiagnosticsCheck(!array_key_exists('evidenceCodes', $unverifiedPayload), 'unverified payload must never expose evidence codes');

    $confirmedJson = json_encode(DiagnosticResultSerializer::verified(AccountDiagnosticEngine::diagnose('account_access', true, null), []));
    foreach (['email', 'password', 'disabledreason', 'reason'] as $forbidden) {
        accountDiagnosticsCheck(
            $confirmedJson !== false && !str_contains(strtolower($confirmedJson), strtolower($forbidden)),
            "serialized diagnostic must never contain the substring '{$forbidden}'"
        );
    }

    // ================================================================
    // Part 4: catalog contract — V2 is sufficient (this is the caller's
    // own account, not a resource requiring V3 relationship proof).
    // ================================================================
    $catalogDefinition = CapabilityCatalog::definition('account.diagnose_own');
    accountDiagnosticsCheck(($catalogDefinition['minVerification'] ?? 99) === 2, 'account.diagnose_own must require exactly V2, not V3 — this is the caller\'s own account');
    accountDiagnosticsCheck(($catalogDefinition['relationships'] ?? ['x']) === [], 'account.diagnose_own must not require any resource relationship');

    // ================================================================
    // Part 5: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportAccountDiagnosticsRequest() does. The endpoint
    // method itself is not called directly because it exits the process
    // via SupportApiResponse (same convention as the other suites).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeUser(42, [65538], disabled: false, dateValidated: '2026-01-01 00:00:00');
    \PKP\user\Repo::$usersById[43] = new FakeUser(43, [65538], disabled: true, dateValidated: null);

    final class InMemorySupportSessionRepositoryForAccount implements SupportSessionRepositoryInterface
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
            foreach ($this->sessions as $session) {
                if (
                    !$session->isRevoked()
                    && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)
                ) {
                    return $session;
                }
            }
            return null;
        }

        public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void {}
        public function revokeOthersForConversation(int $contextId, string $chatwootAccountId, string $chatwootContactId, string $chatwootConversationId, string $exceptPublicId, int $now): void {}
        public function purgeExpired(int $now): int { return 0; }
    }

    $now = time();
    $repo = new InMemorySupportSessionRepositoryForAccount();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrapA = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $boundA = $service->bindAuthenticatedBootstrap($bootstrapA->bindingToken(), 7, 42, '1', '100', '500');
    accountDiagnosticsCheck($boundA !== null, 'test fixture: authenticated bootstrap should bind (user 42)');
    $bootstrapB = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 43, [65538], 'index', 'index', 'en'));
    $boundB = $service->bindAuthenticatedBootstrap($bootstrapB->bindingToken(), 7, 43, '1', '100', '501');
    accountDiagnosticsCheck($boundB !== null, 'test fixture: authenticated bootstrap should bind (user 43)');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    accountDiagnosticsCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Verified V2 identity: account fields read through the bridge ---
    $userAResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'accountDiagnostics');
    accountDiagnosticsCheck(!($userAResult instanceof SupportApiFailure) && $userAResult->verified(), 'the resolver must verify the V2 conversation identity for accountDiagnostics');

    $decisionA = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $userAResult->assurance(),
        $userAResult->identity()
    ));
    accountDiagnosticsCheck($decisionA->allows('account.diagnose_own'), 'a V2-verified identity must unlock account.diagnose_own');

    $fieldsA = $bridge->getUserAccountFields($userAResult->identity()->userId() ?? 0);
    accountDiagnosticsCheck($fieldsA['disabled'] === false, 'end-to-end: bridge must read the real disabled flag through the compatibility adapter');
    accountDiagnosticsCheck($fieldsA['dateValidated'] === '2026-01-01 00:00:00', 'end-to-end: bridge must read the real dateValidated field through the compatibility adapter');
    $diagnosisA = AccountDiagnosticEngine::diagnose('account_access', $fieldsA['disabled'], $fieldsA['dateValidated']);
    accountDiagnosticsCheck($diagnosisA->code() === 'ACCOUNT_ACTIVE', 'end-to-end: user 42\'s real fields must diagnose as ACCOUNT_ACTIVE');

    // --- A different verified identity (user 43) gets THEIR OWN evidence, not user 42's ---
    $userBResult = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '501', 'accountDiagnostics');
    accountDiagnosticsCheck(!($userBResult instanceof SupportApiFailure) && $userBResult->verified(), 'user 43\'s conversation must independently verify');
    $fieldsB = $bridge->getUserAccountFields($userBResult->identity()->userId() ?? 0);
    accountDiagnosticsCheck($fieldsB['disabled'] === true, 'end-to-end: a different identity must read their own account fields, not the previous caller\'s');
    $diagnosisB = AccountDiagnosticEngine::diagnose('account_access', $fieldsB['disabled'], $fieldsB['dateValidated']);
    accountDiagnosticsCheck($diagnosisB->code() === 'ACCOUNT_DISABLED', 'end-to-end: user 43\'s real fields must diagnose as ACCOUNT_DISABLED, independent of user 42');

    // --- Expired/unbound support session: resolver itself must return unverified, capability denied ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-3', 7, 'service-secret', '1', '100', '999', 'accountDiagnostics');
    accountDiagnosticsCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any account is even loaded'
    );
    $deniedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $unknownConversation->assurance(),
        $unknownConversation->identity()
    ));
    accountDiagnosticsCheck(!$deniedDecision->allows('account.diagnose_own'), 'an unverified/anonymous caller must never unlock account.diagnose_own');

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // ================================================================
    // Part 6: source-level checks — structurally proves this cannot become
    // an arbitrary account lookup endpoint (no username/email/userId param).
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    accountDiagnosticsCheck(str_contains($pluginSource, 'function supportAccountDiagnosticsRequest'), 'plugin must implement the account-diagnostics endpoint');
    accountDiagnosticsCheck(str_contains($pluginSource, 'DiagnosticResultSerializer'), 'endpoint must use the shared diagnostic serializer');
    accountDiagnosticsCheck(str_contains($pluginSource, 'account.diagnose_own'), 'endpoint must gate on account.diagnose_own');

    // Scoped to this method's own body only (up to the next method
    // declaration) — a file-wide lazy regex would falsely trip on
    // unrelated later methods (e.g. verification) that legitimately read
    // an email for a completely different, already-anti-enumeration-safe
    // purpose.
    $methodStart = strpos($pluginSource, 'function supportAccountDiagnosticsRequest');
    accountDiagnosticsCheck($methodStart !== false, 'must be able to locate the account-diagnostics method body for the source-level check below');
    $nextMethodStart = strpos($pluginSource, 'public function', $methodStart + 1);
    $methodBody = $nextMethodStart !== false
        ? substr($pluginSource, $methodStart, $nextMethodStart - $methodStart)
        : substr($pluginSource, $methodStart);
    accountDiagnosticsCheck(
        !preg_match('/getUserVar\([\'"](email|username|userId|user_id)[\'"]\)/', $methodBody),
        'the account-diagnostics endpoint must never read a caller-supplied email/username/userId — it diagnoses only the verified caller\'s own account'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    accountDiagnosticsCheck(str_contains($handlerSource, 'function accountDiagnostics('), 'handler must register the accountDiagnostics operation');

    $engineSource = (string) file_get_contents($root . '/classes/v2/Diagnostics/AccountDiagnosticEngine.php');
    accountDiagnosticsCheck(!str_contains($engineSource, 'getDisabledReason'), 'engine must never read the free-form admin-entered disabledReason text');

    fwrite(STDOUT, "Account diagnostics tests passed\n");
}
