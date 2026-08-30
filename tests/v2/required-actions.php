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

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $submissionsById = [];

        /** @var array<int,array<int,array<int>>> keyed by userId, value is the raw "stages" shape the provider iterates */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,object[]> keyed by "submissionId:reviewerId" -> list of FakeReviewAssignment */
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
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
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\RequiredActionMapper;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\SupportStateMapper;

    function requiredActionsCheck(bool $condition, string $message): void
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
        public function getId(): int { return $this->id; }
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
            return new class($this->title) {
                public function __construct(private string $title) {}
                public function getLocalizedTitle(): string { return $this->title; }
            };
        }
    }

    final class FakeReviewAssignment
    {
        public function __construct(private int $status) {}
        public function getStatus(): int { return $this->status; }
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
    }

    final class FakeRequest
    {
        public function getContext(): object { return new FakeContext(); }
        public function getUser(): ?object { return null; }
        public function getRequestedPage(): string { return 'ojsSupportGateway'; }
        public function getRequestedOp(): string { return 'requiredActions'; }
    }

    // ================================================================
    // Part 1: RequiredActionMapper — deterministic, evidence-only.
    // ================================================================
    requiredActionsCheck(RequiredActionMapper::forAuthor('draft') === ['complete_submission'], 'draft state must require complete_submission');
    requiredActionsCheck(RequiredActionMapper::forAuthor('revision_requested') === ['submit_revisions'], 'revision_requested state must require submit_revisions');
    foreach (['submitted', 'review_in_progress', 'copyediting_in_progress', 'production_in_progress', 'published', 'declined', 'scheduled_for_publication', 'unknown'] as $noActionState) {
        requiredActionsCheck(RequiredActionMapper::forAuthor($noActionState) === [], "state '{$noActionState}' must not fabricate an author action");
    }

    // ReviewAssignment::STATUS_* verified against pkp-lib stable-3_5_0.
    requiredActionsCheck(RequiredActionMapper::forReviewer([0]) === ['respond_to_review_invitation'], 'AWAITING_RESPONSE must require responding to the invitation');
    requiredActionsCheck(RequiredActionMapper::forReviewer([4]) === ['respond_to_review_invitation'], 'RESPONSE_OVERDUE must still require responding to the invitation');
    requiredActionsCheck(RequiredActionMapper::forReviewer([11]) === ['respond_to_review_invitation'], 'REQUEST_RESEND must require responding to the invitation');
    requiredActionsCheck(RequiredActionMapper::forReviewer([5]) === ['submit_review'], 'ACCEPTED must require submitting the review');
    requiredActionsCheck(RequiredActionMapper::forReviewer([6]) === ['submit_review'], 'REVIEW_OVERDUE must require submitting the review');
    foreach ([1, 7, 8, 9, 10, 12] as $settledStatus) {
        requiredActionsCheck(RequiredActionMapper::forReviewer([$settledStatus]) === [], "settled ReviewAssignment status {$settledStatus} must not require any reviewer action");
    }
    requiredActionsCheck(RequiredActionMapper::forReviewer([]) === [], 'no assignments at all must require no action');
    requiredActionsCheck(
        RequiredActionMapper::forReviewer([8, 0]) === ['respond_to_review_invitation'],
        'a settled assignment from one round plus an outstanding invitation from another must surface the outstanding one, not average them away'
    );
    requiredActionsCheck(
        RequiredActionMapper::forReviewer([8, 5]) === ['submit_review'],
        'a settled assignment plus an accepted-but-unsubmitted one must surface submit_review'
    );
    requiredActionsCheck(
        RequiredActionMapper::forReviewer([0, 5]) === ['respond_to_review_invitation'],
        'response-needed must take priority over review-needed when both are present across rounds'
    );

    // ================================================================
    // Part 2: RequiredActionsSerializer — allowlist shape and leak checks.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3);
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    requiredActionsCheck($authorRelationship !== null && $authorRelationship->has('author'), 'test fixture: author must resolve a relationship to their own submission');

    $verifiedPayload = RequiredActionsSerializer::verified($authorRelationship, ['submit_revisions'], ['view_status', 'view_required_actions']);
    requiredActionsCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    requiredActionsCheck($verifiedPayload['resourceVerified'] === true, 'verified payload must say resourceVerified=true');
    requiredActionsCheck($verifiedPayload['assurance'] === 'v3', 'verified payload must carry v3 resource assurance');
    requiredActionsCheck($verifiedPayload['resource'] === ['type' => 'submission', 'id' => 456], 'verified payload must expose resource type/id');
    requiredActionsCheck($verifiedPayload['relationships'] === ['author'], 'verified payload must expose relationships');
    requiredActionsCheck($verifiedPayload['requiredActions'] === ['submit_revisions'], 'verified payload must expose the computed required actions');
    requiredActionsCheck(!array_key_exists('evidence', $verifiedPayload), 'verified payload must never expose the internal evidence array');
    requiredActionsCheck(!array_key_exists('title', $verifiedPayload), 'this endpoint must not duplicate submission-support fields like title');

    $verifiedJson = json_encode($verifiedPayload);
    foreach (['email', 'reviewer_id', 'reviewerName', 'abstract', 'orcid', 'doi', 'file', 'title'] as $forbidden) {
        requiredActionsCheck(
            $verifiedJson !== false && !str_contains(strtolower($verifiedJson), strtolower($forbidden)),
            "verified payload must never contain the substring '{$forbidden}'"
        );
    }

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = RequiredActionsSerializer::unverified($unverifiedApiContext, ['list_my_submissions']);
    requiredActionsCheck($unverifiedPayload['resourceVerified'] === false, 'unverified payload must say resourceVerified=false');
    requiredActionsCheck(!array_key_exists('resource', $unverifiedPayload), 'unverified payload must not confirm a resource type/id');
    requiredActionsCheck(!array_key_exists('requiredActions', $unverifiedPayload), 'unverified payload must never expose required actions');

    // ================================================================
    // Part 3: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportRequiredActionsRequest() does. The endpoint
    // method itself is not called directly because it exits the process
    // via SupportApiResponse (same convention as the other suites).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);
    \PKP\user\Repo::$usersById[43] = new FakeOjsUser(43, [65536]);

    final class InMemorySupportSessionRepositoryForActions implements SupportSessionRepositoryInterface
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
        public function purgeExpired(int $now): int { return 0; }
    }

    $now = time();
    $repo = new InMemorySupportSessionRepositoryForActions();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    requiredActionsCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    requiredActionsCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Verified author whose submission needs revisions ---
    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3);
    $authorApiResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'requiredActions');
    requiredActionsCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for requiredActions');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    requiredActionsCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $endpointDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest
    ));
    requiredActionsCheck(
        $endpointDecision->allows('submission.read_own_required_actions'),
        'a verified author relationship at v3 assurance must unlock submission.read_own_required_actions'
    );

    // Simulate a review round in revision_requested via a submission whose
    // stageId is external review, but this suite's DAORegistry fake never
    // returns a review round, so exercise the author draft path instead
    // (independently verified against real evidence in submission-list.php).
    \APP\facades\Repo::$submissionsById[458] = new FakeSubmission(458, 7, status: 1, stageId: 1, submissionProgress: 'step2');
    \APP\facades\Repo::$workflowStagesByUserId['42:458'] = [[65538]];
    $draftSubmission = $bridge->loadSubmission(458);
    $draftRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $draftSubmission);
    requiredActionsCheck($draftRelationship !== null && $draftRelationship->has('author'), 'end-to-end: author must resolve a relationship to their own draft');
    $draftStateFields = $bridge->getSubmissionStateFields($draftSubmission);
    $draftState = SupportStateMapper::map($draftStateFields['status'], $draftStateFields['stageId'], $draftStateFields['reviewRoundStatus'], $draftStateFields['submissionProgress']);
    requiredActionsCheck($draftState === 'draft', 'end-to-end: draft state must be computed the same way ojs_get_submission_support computes it');
    requiredActionsCheck(RequiredActionMapper::forAuthor($draftState) === ['complete_submission'], 'end-to-end: a real draft must require complete_submission');

    // --- Verified reviewer with an outstanding assignment ---
    \APP\facades\Repo::$submissionsById[457] = new FakeSubmission(457, 7, status: 1, stageId: 2);
    \APP\facades\Repo::$workflowStagesByUserId['43:457'] = [];
    \APP\facades\Repo::$reviewAssignmentsByPair['457:43'] = [new FakeReviewAssignment(5)]; // ACCEPTED
    $reviewerIdentity = new SupportContext(7, 'journal-a', 43, [65536], 'index', 'index', 'en');
    $reviewerSubmission = $bridge->loadSubmission(457);
    $reviewerRelationship = $bridge->resolveSubmissionRelationship($reviewerIdentity, $reviewerSubmission);
    requiredActionsCheck($reviewerRelationship !== null && $reviewerRelationship->has('reviewer'), 'end-to-end: assigned reviewer must resolve a relationship to their assignment');
    $reviewStatuses = $bridge->getReviewAssignmentStatuses(457, 43);
    requiredActionsCheck($reviewStatuses === [5], 'end-to-end: bridge must read the real ReviewAssignment status through the compatibility adapter');
    requiredActionsCheck(RequiredActionMapper::forReviewer($reviewStatuses) === ['submit_review'], 'end-to-end: an accepted-but-unsubmitted assignment must require submit_review');

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    requiredActionsCheck(
        $guessedRelationship !== null && $guessedRelationship->isEmpty(),
        'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error'
    );
    $guessedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $authorApiResult->assurance(),
        $authorApiResult->identity(),
        $guessedRelationship
    ));
    requiredActionsCheck(
        !$guessedDecision->allows('submission.read_own_required_actions'),
        'a guessed submission ID with no real relationship must never unlock submission.read_own_required_actions'
    );

    // --- Expired/unbound support session: resolver itself must return unverified ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'requiredActions');
    requiredActionsCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any submission is even loaded'
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
        requiredActionsCheck(
            !$lowAssuranceDecision->allows('submission.read_own_required_actions'),
            "assurance {$lowAssurance} must not unlock submission.read_own_required_actions even with a real relationship present"
        );
    }

    // ================================================================
    // Part 4: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    requiredActionsCheck(str_contains($pluginSource, 'function supportRequiredActionsRequest'), 'plugin must implement the required-actions endpoint');
    requiredActionsCheck(str_contains($pluginSource, 'RequiredActionsSerializer'), 'endpoint must use the dedicated allowlist serializer');
    requiredActionsCheck(str_contains($pluginSource, "submission.read_own_required_actions"), 'endpoint must gate on submission.read_own_required_actions');

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    requiredActionsCheck(str_contains($handlerSource, 'function requiredActions('), 'handler must register the requiredActions operation');

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/RequiredActionsSerializer.php');
    requiredActionsCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Required actions tests passed\n");
}
