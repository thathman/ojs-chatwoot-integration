<?php

declare(strict_types=1);

namespace PKP\db {
    final class DAORegistry
    {
        public static function getDAO(string $name): object
        {
            return new class () {
                public function getCurrentVersion(): object
                {
                    return new class () {
                        public function getVersionString(): string
                        {
                            return '3.5.0.0';
                        }
                    };
                }
                public function getLastReviewRoundBySubmissionId(int $submissionId, ?int $stageId = null): ?object
                {
                    return null; // this suite doesn't exercise review-round state; covered by submission-list.php
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

        public static function user(): self
        {
            return new self();
        }

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

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $submissionsById = [];

        /** @var array<int,array<int,array<int>>> keyed by userId, value is the raw "stages" shape the provider iterates */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,bool> keyed by "submissionId:reviewerId" */
        public static array $reviewAssignments = [];

        public static function submission(): object
        {
            return new class () {
                public function get(int $id): ?object
                {
                    return \APP\facades\Repo::$submissionsById[$id] ?? null;
                }
            };
        }

        public static function user(): object
        {
            return new class () {
                public function getAccessibleWorkflowStages(int $userId, int $contextId, $submission, array $roleIds): array
                {
                    $submissionId = is_object($submission) && method_exists($submission, 'getId') ? $submission->getId() : 0;
                    return \APP\facades\Repo::$workflowStagesByUserId["{$userId}:{$submissionId}"] ?? [];
                }
            };
        }

        public static function reviewAssignment(): object
        {
            return new class () {
                public function getCollector(): object
                {
                    return new class () {
                        private array $submissionIds = [];
                        private array $reviewerIds = [];

                        public function filterBySubmissionIds(array $ids): static
                        {
                            $this->submissionIds = $ids;
                            return $this;
                        }

                        public function filterByReviewerIds(array $ids): static
                        {
                            $this->reviewerIds = $ids;
                            return $this;
                        }

                        public function getMany(): array
                        {
                            $result = [];
                            foreach ($this->submissionIds as $submissionId) {
                                foreach ($this->reviewerIds as $reviewerId) {
                                    if (\APP\facades\Repo::$reviewAssignments["{$submissionId}:{$reviewerId}"] ?? false) {
                                        $result[] = new \stdClass();
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionSupportSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\SupportStateMapper;

    function submissionSupportCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeSubmission
    {
        public function __construct(
            private int $id,
            private int $contextId,
            private int $status = 1,
            private int $stageId = 1,
            private string $title = 'Untitled',
            private string $submissionProgress = ''
        ) {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getData(string $key): mixed
        {
            return match ($key) {
                'contextId' => $this->contextId,
                'status' => $this->status,
                'stageId' => $this->stageId,
                'submissionProgress' => $this->submissionProgress,
                default => null,
            };
        }
        public function getCurrentPublication(): object
        {
            return new class ($this->title) {
                public function __construct(private string $title)
                {
                }
                public function getLocalizedTitle(): string
                {
                    return $this->title;
                }
            };
        }
    }

    final class FakeRole
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    final class FakeOjsUser
    {
        public function __construct(private int $id, private array $roleIds)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getRoles(int $contextId): array
        {
            return array_map(static fn (int $id) => new FakeRole($id), $this->roleIds);
        }
    }

    final class FakeContext
    {
        public function getId(): int
        {
            return 7;
        }
        public function getPath(): string
        {
            return 'journal-a';
        }
    }

    final class FakeRequest
    {
        public function getContext(): object
        {
            return new FakeContext();
        }
        public function getUser(): ?object
        {
            return null;
        }
        public function getRequestedPage(): string
        {
            return 'ojsSupportGateway';
        }
        public function getRequestedOp(): string
        {
            return 'submissionSupport';
        }
    }

    // ================================================================
    // Part 1: SupportStateMapper::explain() — every state this mapper can
    // produce must have a safe, non-empty explanation, and the fallback
    // for anything unrecognized must not fabricate specifics.
    // ================================================================
    $allStates = ['draft', 'submitted', 'review_in_progress', 'revision_requested', 'copyediting_in_progress', 'production_in_progress', 'published', 'declined', 'scheduled_for_publication', 'unknown'];
    foreach ($allStates as $state) {
        submissionSupportCheck(SupportStateMapper::explain($state) !== '', "explain() must return a non-empty sentence for state '{$state}'");
    }
    submissionSupportCheck(
        SupportStateMapper::explain('some_future_state_this_class_does_not_know_about') === SupportStateMapper::explain('unknown'),
        'explain() must fall back to the generic unknown explanation rather than guessing for an unrecognized state'
    );

    // ================================================================
    // Part 2: SubmissionSupportSerializer — allowlist shape and leak checks.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3, title: 'A Safe Manuscript Title');
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionSupportCheck($authorRelationship !== null && $authorRelationship->has('author'), 'test fixture: author must resolve a relationship to their own submission');

    $verifiedPayload = SubmissionSupportSerializer::verified(
        $authorRelationship,
        'A Safe Manuscript Title',
        'review_in_progress',
        SupportStateMapper::explain('review_in_progress'),
        ['view_status', 'view_required_actions']
    );
    submissionSupportCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    submissionSupportCheck($verifiedPayload['resourceVerified'] === true, 'verified payload must say resourceVerified=true');
    submissionSupportCheck($verifiedPayload['assurance'] === 'v3', 'verified payload must carry v3 resource assurance');
    submissionSupportCheck($verifiedPayload['resource'] === ['type' => 'submission', 'id' => 456], 'verified payload must expose resource type/id');
    submissionSupportCheck($verifiedPayload['relationships'] === ['author'], 'verified payload must expose relationships');
    submissionSupportCheck($verifiedPayload['title'] === 'A Safe Manuscript Title', 'verified payload must expose the safe title');
    submissionSupportCheck($verifiedPayload['supportState'] === 'review_in_progress', 'verified payload must expose the normalized support state');
    submissionSupportCheck($verifiedPayload['workflowExplanation'] !== '', 'verified payload must expose a non-empty workflow explanation');
    submissionSupportCheck(!array_key_exists('evidence', $verifiedPayload), 'verified payload must never expose the internal evidence array');
    submissionSupportCheck(!array_key_exists('stateConfidence', $verifiedPayload), 'stateConfidence must be omitted entirely, not null, when the caller does not pass one');

    // STA-008: stateConfidence is additive — passing it must surface it,
    // and it must match what SupportStateMapper::confidence() itself
    // computes for the same state.
    $confidencePayload = SubmissionSupportSerializer::verified(
        $authorRelationship,
        'A Safe Manuscript Title',
        'review_in_progress',
        SupportStateMapper::explain('review_in_progress'),
        ['view_status', 'view_required_actions'],
        SupportStateMapper::confidence(1, 3)
    );
    submissionSupportCheck($confidencePayload['stateConfidence'] === DiagnosticResult::STATUS_CONFIRMED, 'stateConfidence must be surfaced when provided');

    $verifiedJson = json_encode($verifiedPayload);
    foreach (['email', 'reviewer_id', 'reviewerName', 'abstract', 'orcid', 'doi', 'file'] as $forbidden) {
        submissionSupportCheck(
            $verifiedJson !== false && !str_contains(strtolower($verifiedJson), strtolower($forbidden)),
            "verified payload must never contain the substring '{$forbidden}'"
        );
    }

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = SubmissionSupportSerializer::unverified($unverifiedApiContext, ['list_my_submissions']);
    submissionSupportCheck($unverifiedPayload['resourceVerified'] === false, 'unverified payload must say resourceVerified=false');
    submissionSupportCheck(!array_key_exists('resource', $unverifiedPayload), 'unverified payload must not confirm a resource type/id');
    submissionSupportCheck(!array_key_exists('title', $unverifiedPayload), 'unverified payload must never expose a title, even by omission-checking');
    submissionSupportCheck(!array_key_exists('supportState', $unverifiedPayload), 'unverified payload must not expose a support state');

    // ================================================================
    // Part 3: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportSubmissionSupportRequest() does. The endpoint
    // method itself is not called directly because it exits the process
    // via SupportApiResponse (same convention as the other suites).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    final class InMemorySupportSessionRepositoryForSupport implements SupportSessionRepositoryInterface
    {
        /** @var array<string,SupportSession> */
        public array $sessions = [];

        public function create(SupportSession $session): void
        {
            $this->sessions[$session->publicId()] = $session;
        }
        public function save(SupportSession $session): void
        {
            $this->sessions[$session->publicId()] = $session;
        }
        public function findByPublicId(string $publicId): ?SupportSession
        {
            return $this->sessions[$publicId] ?? null;
        }

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

        public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void
        {
        }
        public function revokeOthersForConversation(int $contextId, string $chatwootAccountId, string $chatwootContactId, string $chatwootConversationId, string $exceptPublicId, int $now): void
        {
        }
        public function purgeExpired(int $now): int
        {
            return 0;
        }
    }

    $now = time();
    $repo = new InMemorySupportSessionRepositoryForSupport();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    submissionSupportCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    submissionSupportCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Authorized author requesting support for their own submission ---
    $authorApiResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'submissionSupport');
    submissionSupportCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for submissionSupport');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    submissionSupportCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $endpointDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest
    ));
    submissionSupportCheck(
        $endpointDecision->allows('submission.read_own_support_status'),
        'a verified author relationship at v3 assurance must unlock submission.read_own_support_status'
    );

    $stateFields = $bridge->getSubmissionStateFields($submissionForRequest);
    $supportState = SupportStateMapper::map($stateFields['status'], $stateFields['stageId'], $stateFields['reviewRoundStatus'], $stateFields['submissionProgress']);
    submissionSupportCheck($supportState === 'review_in_progress', 'end-to-end pipeline must compute the same state SupportStateMapper would from real submission fields');
    submissionSupportCheck($bridge->getSubmissionTitle($submissionForRequest) === 'A Safe Manuscript Title', 'end-to-end pipeline must read the real submission title through the bridge');

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    submissionSupportCheck(
        $guessedRelationship !== null && $guessedRelationship->isEmpty(),
        'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error'
    );
    $guessedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $authorApiResult->assurance(), // no relationship => no v3 upgrade
        $authorApiResult->identity(),
        $guessedRelationship
    ));
    submissionSupportCheck(
        !$guessedDecision->allows('submission.read_own_support_status'),
        'a guessed submission ID with no real relationship must never unlock submission.read_own_support_status'
    );

    // --- Expired/unbound support session: resolver itself must return unverified ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'submissionSupport');
    submissionSupportCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any submission is even loaded'
    );

    // --- Wrong conversation tuple must not resolve the bound session ---
    $wrongTuple = $apiResolver->resolve(new FakeRequest(), 'corr-3', 7, 'service-secret', '2', '100', '500', 'submissionSupport');
    submissionSupportCheck(
        !($wrongTuple instanceof SupportApiFailure) && $wrongTuple->verified() === false,
        'a wrong conversation tuple must resolve as unverified, not error and not leak the real binding'
    );

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // --- V0-V2 cannot reach the V3 path even with a real, positive relationship ---
    foreach (['v0', 'v1', 'v2'] as $lowAssurance) {
        $lowAssuranceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $lowAssurance,
            $authorContext,
            $authorRelationship
        ));
        submissionSupportCheck(
            !$lowAssuranceDecision->allows('submission.read_own_support_status'),
            "assurance {$lowAssurance} must not unlock submission.read_own_support_status even with a real relationship present"
        );
    }

    // ================================================================
    // Part 4: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    submissionSupportCheck(str_contains($pluginSource, 'function supportSubmissionSupportRequest'), 'plugin must implement the submission-support endpoint');
    submissionSupportCheck(str_contains($pluginSource, 'SubmissionSupportSerializer'), 'endpoint must use the dedicated allowlist serializer');
    submissionSupportCheck(str_contains($pluginSource, 'submission.read_own_support_status'), 'endpoint must gate on submission.read_own_support_status');
    submissionSupportCheck(
        !str_contains($pluginSource, 'session->save') || !str_contains($pluginSource, "'v3'"),
        'v3 must never be written back onto the persisted support session by this endpoint either'
    );

    submissionSupportCheck(
        str_contains($pluginSource, 'SupportStateMapper::confidence('),
        'STA-008: the endpoint must compute stateConfidence via the real mapper, never fabricate one'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    submissionSupportCheck(str_contains($handlerSource, 'function submissionSupport('), 'handler must register the submissionSupport operation');

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/SubmissionSupportSerializer.php');
    submissionSupportCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Submission support tests passed\n");
}
