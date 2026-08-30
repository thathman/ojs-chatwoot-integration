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
                public function getLastReviewRoundBySubmissionId(int $submissionId, ?int $stageId = null): ?object
                {
                    return null;
                }
            };
        }
    }
}

namespace APP\payment\ojs {
    final class OJSPaymentManager
    {
        public function __construct(private $context) {}
        public function isConfigured(): bool { return (bool) $this->context->getData('paymentsEnabled'); }
        public function publicationEnabled(): bool
        {
            $fee = $this->context->getData('publicationFee');
            return $this->isConfigured() && is_numeric($fee) && (float) $fee > 0;
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
        public const ROLE_ID_SUB_EDITOR = 17;
        public const ROLE_ID_ASSISTANT = 4097;
        public const ROLE_ID_AUTHOR = 65538;
        public const ROLE_ID_REVIEWER = 65536;
        public const ROLE_ID_READER = 1048576;
    }
}

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $submissionsById = [];

        /** @var array<int,array<int,array<int>>> */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,object[]> */
        public static array $reviewAssignmentsByPair = [];

        public static function submission(): object
        {
            return new class {
                public function get(int $id): ?object
                {
                    return \APP\facades\Repo::$submissionsById[$id] ?? null;
                }
            };
        }

        public static function user(): object
        {
            return new class {
                public function getAccessibleWorkflowStages(int $userId, int $contextId, $submission, array $roleIds): array
                {
                    $submissionId = is_object($submission) && method_exists($submission, 'getId') ? $submission->getId() : 0;
                    return \APP\facades\Repo::$workflowStagesByUserId["{$userId}:{$submissionId}"] ?? [];
                }
            };
        }

        public static function reviewAssignment(): object
        {
            return new class {
                public function getCollector(): object
                {
                    return new class {
                        private array $submissionIds = [];
                        private array $reviewerIds = [];

                        public function filterBySubmissionIds(array $ids): static { $this->submissionIds = $ids; return $this; }
                        public function filterByReviewerIds(array $ids): static { $this->reviewerIds = $ids; return $this; }

                        public function getMany(): array
                        {
                            $result = [];
                            foreach ($this->submissionIds as $submissionId) {
                                foreach ($this->reviewerIds as $reviewerId) {
                                    foreach (\APP\facades\Repo::$reviewAssignmentsByPair["{$submissionId}:{$reviewerId}"] ?? [] as $assignment) {
                                        $result[] = $assignment;
                                    }
                                }
                            }
                            return $result;
                        }
                    };
                }
            };
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Handoff\EscalationIdempotencyGuard;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Handoff\HandoffSummaryFormatter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

    function escalateCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeSubmission
    {
        public function __construct(private int $id, private int $contextId, private int $status = 1, private int $stageId = 1) {}
        public function getId(): int { return $this->id; }
        public function getData(string $key): mixed
        {
            return match ($key) {
                'contextId' => $this->contextId,
                'status' => $this->status,
                'stageId' => $this->stageId,
                'submissionProgress' => '',
                default => null,
            };
        }
        public function getCurrentPublication(): object
        {
            return new class {
                public function getLocalizedTitle(): string { return 'Untitled'; }
                public function getDoi(): ?string { return null; }
                public function getIssueId(): ?int { return null; }
            };
        }
    }

    final class FakeRole
    {
        public function __construct(private int $id) {}
        public function getId(): int { return $this->id; }
    }

    final class FakeOjsUser
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
        public function getData(string $key): mixed
        {
            return match ($key) {
                'paymentsEnabled' => false,
                'publicationFee' => 0.0,
                'currency' => 'USD',
                default => null,
            };
        }
    }

    final class FakeRequest
    {
        public function getContext(): object { return new FakeContext(); }
        public function getUser(): ?object { return null; }
        public function getRequestedPage(): string { return 'ojsSupportGateway'; }
        public function getRequestedOp(): string { return 'escalate'; }
    }

    // ================================================================
    // Part 1: HandoffSummaryFormatter — safe composition, reason
    // sanitization, and no-leak checks. Every returned field is either
    // server-derived or the capped/stripped caller-supplied reason.
    // ================================================================
    $unverifiedIdentitySummary = ['verified' => false, 'assurance' => 'v0', 'identity' => ['authenticated' => false, 'roles' => []]];
    $minimalSummary = HandoffSummaryFormatter::build($unverifiedIdentitySummary, null, null, [], null, null, 'I cannot log in');
    escalateCheck($minimalSummary['reason'] === 'I cannot log in', 'a clean reason must pass through unchanged');
    escalateCheck(!array_key_exists('resource', $minimalSummary), 'no resource key when no relationship was ever established');
    escalateCheck(!array_key_exists('supportState', $minimalSummary), 'no supportState key when none was computed');

    // Reason sanitization: control characters stripped, length capped.
    $dirtyReason = "Line one\x00\x07\x1F" . str_repeat('x', 1200);
    $dirtySummary = HandoffSummaryFormatter::build($unverifiedIdentitySummary, null, null, [], null, null, $dirtyReason);
    escalateCheck(!str_contains($dirtySummary['reason'], "\x00") && !str_contains($dirtySummary['reason'], "\x07"), 'control characters must be stripped from the reason');
    escalateCheck(strlen($dirtySummary['reason']) <= 1000, 'the reason must be capped at 1000 characters');

    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);
    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3);
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorRelationship = $resolver->resolve(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'), \APP\facades\Repo::$submissionsById[456]);
    escalateCheck($authorRelationship !== null && $authorRelationship->has('author'), 'test fixture: author must resolve a relationship');

    $verifiedIdentitySummary = ['verified' => true, 'assurance' => 'v3', 'identity' => ['authenticated' => true, 'roles' => ['author']]];
    $fullSummary = HandoffSummaryFormatter::build(
        $verifiedIdentitySummary,
        $authorRelationship,
        'revision_requested',
        ['submit_revisions'],
        ['status' => 'not_yet_published', 'doi' => null],
        ['feeEnabled' => false, 'status' => 'not_applicable'],
        'Editor has not responded in two weeks'
    );
    escalateCheck($fullSummary['resource'] === ['type' => 'submission', 'id' => 456, 'relationships' => ['author']], 'a real relationship must populate the resource block');
    escalateCheck($fullSummary['supportState'] === 'revision_requested', 'a computed support state must be included');
    escalateCheck($fullSummary['requiredActions'] === ['submit_revisions'], 'computed required actions must be included');
    escalateCheck($fullSummary['publication']['status'] === 'not_yet_published', 'publication facts must be included when provided');
    escalateCheck($fullSummary['payment']['status'] === 'not_applicable', 'payment facts must be included when provided');
    escalateCheck(!array_key_exists('evidence', $fullSummary) && !array_key_exists('resource', $fullSummary) || !array_key_exists('evidence', $fullSummary['resource'] ?? []), 'summary must never expose internal relationship evidence');

    $noteText = HandoffSummaryFormatter::renderNoteText($fullSummary);
    escalateCheck(str_contains($noteText, 'revision_requested'), 'rendered note text must include the support state');
    escalateCheck(str_contains($noteText, 'submit_revisions'), 'rendered note text must include required actions');
    escalateCheck(str_contains($noteText, 'Editor has not responded'), 'rendered note text must include the (sanitized) reason');
    foreach (['email', 'password', 'reviewer_id', 'card', 'secret', 'token'] as $forbidden) {
        escalateCheck(!str_contains(strtolower($noteText), strtolower($forbidden)), "rendered note text must never contain the substring '{$forbidden}'");
    }

    // ================================================================
    // Part 2: EscalationIdempotencyGuard — fail-open without APCu (the
    // CLI test runner has none, matching every other APCu-backed class
    // in this codebase, e.g. RateLimiter).
    // ================================================================
    $guard = new EscalationIdempotencyGuard();
    escalateCheck($guard->claim('k1') === true, 'idempotency guard must fail open (always claim) when APCu is unavailable');
    escalateCheck($guard->claim('k1') === true, 'without APCu, a repeated claim must also return true (no real dedup possible in this environment) rather than throw');

    // ================================================================
    // Part 3: catalog contract — support.escalate is deliberately open
    // (V0/unauthenticated), since a handoff must remain available even
    // when verification itself is failing.
    // ================================================================
    $catalogDefinition = CapabilityCatalog::definition('support.escalate');
    escalateCheck(($catalogDefinition['minVerification'] ?? 99) === 0, 'support.escalate must require only V0 — available even to an unverified caller');
    escalateCheck(($catalogDefinition['requiresAuthenticatedIdentity'] ?? true) === false, 'support.escalate must not require an authenticated identity');

    // ================================================================
    // Part 4: end-to-end through the bridge + real resolver, proving the
    // "does not grant additional data access" rule — a resource fact is
    // only ever included when the exact same capability its own
    // dedicated endpoint enforces is actually allowed.
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    final class InMemorySupportSessionRepositoryForEscalate implements SupportSessionRepositoryInterface
    {
        public array $sessions = [];
        public function create(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
        public function save(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
        public function findByPublicId(string $publicId): ?SupportSession { return $this->sessions[$publicId] ?? null; }

        public function claimBindingToken(
            string $bindingTokenHash, int $contextId, int $userId,
            string $chatwootAccountId, string $chatwootContactId, string $chatwootConversationId,
            int $now, int $idleExpiresAt
        ): ?SupportSession {
            foreach ($this->sessions as $publicId => $session) {
                if ($session->contextId() !== $contextId || $session->userId() !== $userId || $session->bindingTokenHash() !== $bindingTokenHash || !$session->bindingAvailable($now)) {
                    continue;
                }
                $bound = $session->withConversationBinding($chatwootAccountId, $chatwootContactId, $chatwootConversationId, $now, min($idleExpiresAt, $session->absoluteExpiresAt()));
                $this->sessions[$publicId] = $bound;
                return $bound;
            }
            return null;
        }

        public function findByConversationBinding(int $contextId, string $chatwootAccountId, string $chatwootContactId, string $chatwootConversationId): ?SupportSession
        {
            foreach ($this->sessions as $session) {
                if (!$session->isRevoked() && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)) {
                    return $session;
                }
            }
            return null;
        }

        public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void {}
        public function purgeExpired(int $now): int { return 0; }
    }

    $now = time();
    $repo = new InMemorySupportSessionRepositoryForEscalate();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    escalateCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    escalateCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    $authorApiResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'escalate');
    escalateCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for escalate');

    $identitySummaryForRequest = SupportIdentitySerializer::serialize($authorApiResult);
    escalateCheck($identitySummaryForRequest['verified'] === true, 'end-to-end: the identity summary embedded in the handoff must reflect the real verified state');
    escalateCheck(!array_key_exists('email', $identitySummaryForRequest), 'end-to-end: the identity summary must never include an email address');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    escalateCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $resourceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC, 'v3', $authorApiResult->identity(), $relationshipForRequest
    ));
    escalateCheck($resourceDecision->allows('submission.read_own_support_status'), 'end-to-end: a real author relationship at v3 must unlock submission.read_own_support_status for inclusion in the summary');

    // Payment must NOT be includable here: payment_support policy defaults off.
    $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext(new FakeRequest()));
    $paymentDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC, 'v3', $authorApiResult->identity(), $relationshipForRequest, ['payment_status' => $feeInfo['enabled']]
    ));
    escalateCheck(!$paymentDecision->allows('submission.read_own_payment_status'), 'end-to-end: payment facts must not be includable in the handoff summary while payment_support still defaults off — escalation must not grant more access than the dedicated endpoint would');

    // --- Guessed submission ID must never populate resource facts ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    escalateCheck($guessedRelationship !== null && $guessedRelationship->isEmpty(), 'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error');

    // --- Unverified/unbound conversation: escalation must still be allowed (V0), but with no resource facts ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'escalate');
    escalateCheck(!($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false, 'an unbound/expired conversation must resolve as unverified, but escalate itself must remain reachable at v0');
    $unverifiedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC, $unknownConversation->assurance(), $unknownConversation->identity()
    ));
    escalateCheck($unverifiedDecision->allows('support.escalate'), 'support.escalate must remain available even for an unverified/anonymous caller — a handoff must work when verification itself is failing');

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // ================================================================
    // Part 5: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    escalateCheck(str_contains($pluginSource, 'function supportEscalateRequest'), 'plugin must implement the escalate endpoint');
    escalateCheck(str_contains($pluginSource, "'support.escalate'"), 'endpoint must gate on support.escalate');
    escalateCheck(str_contains($pluginSource, 'HandoffSummaryFormatter'), 'endpoint must use the shared handoff summary formatter');
    escalateCheck(
        substr_count($pluginSource, "\$resourceDecision->allows('submission.read_own_") >= 3,
        'the endpoint must independently re-check each dedicated capability before including its fact in the summary — never assume access'
    );
    escalateCheck(
        str_contains($pluginSource, "\$chatwoot->createConversationNote(\$chatwootConversationId,"),
        'the note must be posted to the request\'s own conversation tuple, never a caller-supplied override'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    escalateCheck(str_contains($handlerSource, 'function escalate('), 'handler must register the escalate operation');

    $formatterSource = (string) file_get_contents($root . '/classes/v2/Handoff/HandoffSummaryFormatter.php');
    escalateCheck(!str_contains($formatterSource, '->evidence()'), 'formatter must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Escalate support tests passed\n");
}
