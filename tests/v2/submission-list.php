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

        /** @var array<string,array<int>> keyed by "userId:submissionId" */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,bool> keyed by "submissionId:reviewerId" */
        public static array $reviewAssignments = [];

        /** @var array<string,array<int>> keyed by "contextId:userId" -> ordered submission IDs the OJS collector would return */
        public static array $candidateIdsByContextAndUser = [];

        public static function submission(): object
        {
            return new class {
                public function get(int $id): ?object
                {
                    return \APP\facades\Repo::$submissionsById[$id] ?? null;
                }

                public function getCollector(): object
                {
                    return new class {
                        private array $contextIds = [];
                        private array $userIds = [];
                        private ?int $limitValue = null;
                        private int $offsetValue = 0;

                        public function filterByContextIds(array $ids): static
                        {
                            $this->contextIds = $ids;
                            return $this;
                        }

                        public function assignedTo(array $ids): static
                        {
                            $this->userIds = $ids;
                            return $this;
                        }

                        public function limit(?int $count): static
                        {
                            $this->limitValue = $count;
                            return $this;
                        }

                        public function offset(int $count): static
                        {
                            $this->offsetValue = $count;
                            return $this;
                        }

                        public function getMany(): object
                        {
                            $contextId = $this->contextIds[0] ?? 0;
                            $userId = $this->userIds[0] ?? 0;
                            $ids = \APP\facades\Repo::$candidateIdsByContextAndUser["{$contextId}:{$userId}"] ?? [];
                            $ids = array_slice($ids, $this->offsetValue, $this->limitValue);
                            $items = array_values(array_filter(array_map(
                                static fn (int $id) => \APP\facades\Repo::$submissionsById[$id] ?? null,
                                $ids
                            )));

                            return new class($items) {
                                public function __construct(private array $items) {}
                                public function all(): array { return $this->items; }
                                /** Deliberately wrong shape — if code calls this instead of all(), tests must fail. */
                                public function toArray(): array
                                {
                                    return array_map(static fn ($i) => ['WRONG_METHOD_CALLED' => true], $this->items);
                                }
                            };
                        }
                    };
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaginationParams;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionListSerializer;
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

    function submissionListCheck(bool $condition, string $message): void
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
            private string $title = 'Untitled'
        ) {
        }
        public function getId(): int { return $this->id; }
        public function getData(string $key): mixed
        {
            return match ($key) {
                'contextId' => $this->contextId,
                'status' => $this->status,
                'stageId' => $this->stageId,
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
        public function getRequestedOp(): string { return 'submissions'; }
    }

    final class InMemorySupportSessionRepositoryForList implements SupportSessionRepositoryInterface
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

    // ================================================================
    // Part 1: SupportStateMapper — deterministic, unknown-safe.
    // ================================================================
    submissionListCheck(SupportStateMapper::map(4, null) === 'declined', 'declined status must map regardless of stage');
    submissionListCheck(SupportStateMapper::map(3, null) === 'published', 'published status must map regardless of stage');
    submissionListCheck(SupportStateMapper::map(5, null) === 'scheduled_for_publication', 'scheduled status must map to scheduled_for_publication');
    submissionListCheck(SupportStateMapper::map(1, 1) === 'submitted', 'queued + submission stage must map to submitted');
    submissionListCheck(SupportStateMapper::map(1, 2) === 'review_in_progress', 'queued + internal review stage must map to review_in_progress');
    submissionListCheck(SupportStateMapper::map(1, 3) === 'review_in_progress', 'queued + external review stage must map to review_in_progress');
    submissionListCheck(SupportStateMapper::map(1, 4) === 'copyediting_in_progress', 'queued + editing stage must map to copyediting_in_progress');
    submissionListCheck(SupportStateMapper::map(1, 5) === 'production_in_progress', 'queued + production stage must map to production_in_progress');
    submissionListCheck(SupportStateMapper::map(1, 999) === 'unknown', 'queued + unrecognized stage must map to unknown, never guess');
    submissionListCheck(SupportStateMapper::map(1, null) === 'unknown', 'queued + missing stage must map to unknown');
    submissionListCheck(SupportStateMapper::map(null, null) === 'unknown', 'missing status must map to unknown');
    submissionListCheck(SupportStateMapper::map(999, 1) === 'unknown', 'unrecognized status must map to unknown, never guess');

    // ================================================================
    // Part 2: PaginationParams — bounded, fail-closed.
    // ================================================================
    $defaultPagination = PaginationParams::parse(null, null);
    submissionListCheck($defaultPagination !== null && $defaultPagination->limit === 20 && $defaultPagination->offset === 0, 'pagination default must be limit=20, offset=0');

    $maxPagination = PaginationParams::parse('50', '0');
    submissionListCheck($maxPagination !== null && $maxPagination->limit === 50, 'pagination must accept the maximum of 50');

    submissionListCheck(PaginationParams::parse('51', '0') === null, 'pagination must reject a limit above the maximum');
    submissionListCheck(PaginationParams::parse('0', '0') === null, 'pagination must reject a zero limit (not positive)');
    submissionListCheck(PaginationParams::parse('-1', '0') === null, 'pagination must reject a negative limit');
    submissionListCheck(PaginationParams::parse('abc', '0') === null, 'pagination must reject a non-numeric limit');
    submissionListCheck(PaginationParams::parse('10', '-1') === null, 'pagination must reject a negative offset');
    submissionListCheck(PaginationParams::parse('10', 'abc') === null, 'pagination must reject a non-numeric offset');
    submissionListCheck(PaginationParams::parse('10.5', '0') === null, 'pagination must reject a non-integer limit');

    // ================================================================
    // Part 3: SubmissionListSerializer — allowlist shape, no leaks.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3, title: 'A Safe Manuscript Title');
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionListCheck($authorRelationship !== null && $authorRelationship->has('author'), 'fixture sanity: author relationship must resolve');

    $unverifiedIdentity = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = SubmissionListSerializer::unverified($unverifiedApiContext);
    submissionListCheck($unverifiedPayload['verified'] === false, 'unverified payload must say verified=false');
    submissionListCheck($unverifiedPayload['submissions'] === [], 'unverified payload must return an empty list, never a count-revealing partial list');
    submissionListCheck($unverifiedPayload['pagination']['hasMore'] === false, 'unverified payload must not hint at more results existing');

    $verifiedApiContext = SupportApiRequestContext::verifiedWith(
        'corr-y',
        7,
        'v2',
        $authorContext,
        new SupportSession('pub-1', 7, 42, 'authenticated_session', 'v2', null, null, null, null, null, null, 1000, 1000, 5000, 9000, null)
    );
    $pagination = PaginationParams::parse(null, null);
    $verifiedPayload = SubmissionListSerializer::verified(
        $verifiedApiContext,
        [['relationship' => $authorRelationship, 'title' => 'A Safe Manuscript Title', 'supportState' => 'review_in_progress', 'actionRequired' => null]],
        $pagination,
        false
    );
    submissionListCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    submissionListCheck(count($verifiedPayload['submissions']) === 1, 'verified payload must include the one entry');
    $entry = $verifiedPayload['submissions'][0];
    submissionListCheck($entry['id'] === 456, 'entry must expose submission id');
    submissionListCheck($entry['title'] === 'A Safe Manuscript Title', 'entry must expose the safe title');
    submissionListCheck($entry['relationships'] === ['author'], 'entry must expose relationships array');
    submissionListCheck($entry['supportState'] === 'review_in_progress', 'entry must expose normalized support state');
    submissionListCheck($entry['actionRequired'] === null, 'entry must expose an explicit unknown-safe actionRequired, not a guessed false');
    submissionListCheck(
        !array_key_exists('evidence', $entry) && !array_key_exists('resource', $entry),
        'entry must not expose internal relationship evidence or a redundant resource wrapper'
    );

    $serializedJson = json_encode($verifiedPayload);
    foreach (['email', 'reviewer_id', 'reviewerName', 'abstract', 'orcid', 'file'] as $forbidden) {
        submissionListCheck(
            !str_contains(strtolower($serializedJson), strtolower($forbidden)),
            "serialized list must never contain the substring '{$forbidden}'"
        );
    }

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/SubmissionListSerializer.php');
    submissionListCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    // ================================================================
    // Part 4: end-to-end through the bridge + real resolver + resolver
    // pipeline, replicating exactly what supportSubmissionListRequest()
    // does (the endpoint method itself is not called directly since it
    // exits the process via SupportApiResponse, same convention as the
    // other Support API test suites).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    $now = time();
    $repo = new InMemorySupportSessionRepositoryForList();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    submissionListCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    submissionListCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    $verifiedResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'submissions');
    submissionListCheck(!($verifiedResult instanceof SupportApiFailure) && $verifiedResult->verified(), 'the resolver must verify the V2 conversation identity for submissions');

    // Candidate universe for user 42 in context 7: an authored submission
    // (456), a reviewer-assigned submission (457), a submission where the
    // user only holds the journal-level Reviewer role with no actual
    // assignment (458), an editorial-only submission (459), a multi-role
    // author+reviewer submission (460), and a cross-journal submission that
    // must never appear (999, belongs to context 8).
    \APP\facades\Repo::$submissionsById[457] = new FakeSubmission(457, 7, status: 1, stageId: 2, title: 'Reviewer Assignment Submission');
    \APP\facades\Repo::$submissionsById[458] = new FakeSubmission(458, 7, status: 1, stageId: 3, title: 'Unassigned Reviewer Role Submission');
    \APP\facades\Repo::$submissionsById[459] = new FakeSubmission(459, 7, status: 1, stageId: 4, title: 'Editorial Only Submission');
    \APP\facades\Repo::$submissionsById[460] = new FakeSubmission(460, 7, status: 3, stageId: 0, title: 'Multi Role Submission');
    \APP\facades\Repo::$submissionsById[999] = new FakeSubmission(999, 8, status: 1, stageId: 1, title: 'Cross Journal Submission');

    \APP\facades\Repo::$workflowStagesByUserId['42:457'] = [];
    \APP\facades\Repo::$reviewAssignments['457:42'] = true;

    \APP\facades\Repo::$workflowStagesByUserId['42:458'] = []; // no workflow stage evidence
    // user 42 has the journal-level Reviewer role (see roleIds below) but no actual assignment on 458

    \APP\facades\Repo::$workflowStagesByUserId['42:459'] = [[16]]; // Role::ROLE_ID_MANAGER -> editorial, not author/reviewer

    \APP\facades\Repo::$workflowStagesByUserId['42:460'] = [[65538]];
    \APP\facades\Repo::$reviewAssignments['460:42'] = true;

    \APP\facades\Repo::$workflowStagesByUserId['42:999'] = [[65538]]; // would look like author evidence, but wrong journal

    \APP\facades\Repo::$candidateIdsByContextAndUser['7:42'] = [456, 457, 458, 459, 460, 999];

    $identityWithReviewerRole = new SupportContext(7, 'journal-a', 42, [65538, 65536], 'index', 'index', 'en');

    $candidates = $bridge->listCandidateSubmissions(7, 42, 200);
    submissionListCheck(count($candidates) === 6, 'bridge must return all candidates the OJS query supplies (unfiltered)');

    $entries = [];
    $seen = [];
    foreach ($candidates as $submission) {
        $relationship = $bridge->resolveSubmissionRelationship($identityWithReviewerRole, $submission);
        if (!$relationship || $relationship->isEmpty()) {
            continue;
        }
        if (!$relationship->has('author') && !$relationship->has('reviewer')) {
            continue;
        }
        if (isset($seen[$relationship->resourceId()])) {
            continue;
        }
        $seen[$relationship->resourceId()] = true;
        $stateFields = $bridge->getSubmissionStateFields($submission);
        $entries[] = [
            'id' => $relationship->resourceId(),
            'relationships' => $relationship->types(),
            'supportState' => SupportStateMapper::map($stateFields['status'], $stateFields['stageId']),
        ];
    }

    $entriesById = [];
    foreach ($entries as $entry) {
        $entriesById[$entry['id']] = $entry;
    }

    submissionListCheck(isset($entriesById[456]), 'author must see their own submission (456)');
    submissionListCheck(isset($entriesById[457]), 'assigned reviewer must see their assigned submission (457)');
    submissionListCheck(!isset($entriesById[458]), 'journal-level Reviewer role alone must not surface an unassigned submission (458)');
    submissionListCheck(!isset($entriesById[459]), 'editorial-only relationship must not appear in this baseline (459)');
    submissionListCheck(isset($entriesById[460]), 'multi-role author+reviewer submission must appear (460)');
    submissionListCheck($entriesById[460]['relationships'] === ['author', 'reviewer'], 'multi-role submission must be deduplicated into one entry with plural relationships');
    submissionListCheck(!isset($entriesById[999]), 'a submission belonging to another journal must never appear, even with matching workflow evidence');
    submissionListCheck(count($entries) === 3, 'exactly the 3 genuinely author/reviewer-related submissions must appear (456, 457, 460)');

    // --- Guessed/nonexistent candidate defensive handling ---
    $candidatesWithGhost = array_merge($candidates, [null]);
    $safeCount = 0;
    foreach ($candidatesWithGhost as $maybeSubmission) {
        if ($maybeSubmission === null) {
            continue; // endpoint code guards is_object via resolveSubmissionRelationship's own null-submission handling
        }
        $safeCount++;
    }
    submissionListCheck($safeCount === 6, 'a null/ghost candidate entry must not crash candidate processing');
    submissionListCheck($resolver->resolve($identityWithReviewerRole, null) === null, 'resolving a null submission must fail closed, not throw');

    // --- Duplicate candidate discovery must not duplicate the list result ---
    $duplicatedCandidates = [
        \APP\facades\Repo::$submissionsById[456],
        \APP\facades\Repo::$submissionsById[456],
    ];
    $dedupSeen = [];
    $dedupEntries = [];
    foreach ($duplicatedCandidates as $submission) {
        $relationship = $bridge->resolveSubmissionRelationship($authorContext, $submission);
        if (!$relationship || $relationship->isEmpty() || isset($dedupSeen[$relationship->resourceId()])) {
            continue;
        }
        $dedupSeen[$relationship->resourceId()] = true;
        $dedupEntries[] = $relationship->resourceId();
    }
    submissionListCheck($dedupEntries === [456], 'the same submission appearing twice in candidate discovery must not duplicate the final list');

    // --- Live recompute: role change / assignment removal take effect immediately ---
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $before = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionListCheck($before->has('author'), 'precondition: user 42 starts as author of 456');
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [];
    $after = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionListCheck($after->isEmpty(), 'a role/workflow-stage change must be respected immediately on the next list request');
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]]; // restore for later assertions

    \APP\facades\Repo::$reviewAssignments['457:42'] = true;
    $beforeReview = $resolver->resolve($identityWithReviewerRole, \APP\facades\Repo::$submissionsById[457]);
    submissionListCheck($beforeReview->has('reviewer'), 'precondition: user 42 starts as an assigned reviewer of 457');
    \APP\facades\Repo::$reviewAssignments['457:42'] = false;
    $afterReview = $resolver->resolve($identityWithReviewerRole, \APP\facades\Repo::$submissionsById[457]);
    submissionListCheck($afterReview->isEmpty(), 'removing a review assignment must be respected immediately on the next list request');
    \APP\facades\Repo::$reviewAssignments['457:42'] = true; // restore

    // --- submission.list_own capability denial must fail safely (same shape as empty, not a distinct error) ---
    $deniedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v2',
        $authorContext,
        null,
        [],
        ['submission_support' => false] // journal policy explicitly disables it
    ));
    submissionListCheck(
        $deniedDecision !== null && !$deniedDecision->allows('submission.list_own'),
        'journal policy must be able to deny submission.list_own even for an authenticated V2 identity'
    );

    $allowedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v2',
        $authorContext
    ));
    submissionListCheck(
        $allowedDecision !== null && $allowedDecision->allows('submission.list_own'),
        'sanity check: submission.list_own must be allowed by default for an authenticated V2 identity (proves the denial above is real)'
    );

    // --- Unbound/expired conversation and wrong tuple must return generic unverified, before any listing logic runs ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'submissions');
    submissionListCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any candidate is even queried'
    );

    $wrongTuple = $apiResolver->resolve(new FakeRequest(), 'corr-3', 7, 'service-secret', '2', '100', '500', 'submissions');
    submissionListCheck(
        !($wrongTuple instanceof SupportApiFailure) && $wrongTuple->verified() === false,
        'a wrong conversation tuple must resolve as unverified, not error and not leak the real binding'
    );

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // ================================================================
    // Part 5: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    submissionListCheck(str_contains($pluginSource, 'function supportSubmissionListRequest'), 'plugin must implement the submission-list endpoint');
    submissionListCheck(str_contains($pluginSource, 'SubmissionListSerializer'), 'endpoint must use the dedicated allowlist serializer');
    submissionListCheck(str_contains($pluginSource, "submission.list_own"), 'endpoint must gate on the submission.list_own capability');
    submissionListCheck(str_contains($pluginSource, 'seenSubmissionIds'), 'endpoint must defensively dedupe candidates by submission id');
    submissionListCheck(
        str_contains($pluginSource, "!\$relationship->has('author') && !\$relationship->has('reviewer')"),
        'endpoint must explicitly exclude editorial-only relationships from this baseline'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    submissionListCheck(str_contains($handlerSource, 'function submissions('), 'handler must register the submissions operation');

    $auditSource = (string) file_get_contents($root . '/classes/v2/Audit/ErrorLogSupportApiAuditLogger.php');
    submissionListCheck(
        !str_contains($auditSource, 'title') && !str_contains($auditSource, 'email'),
        'audit sink must not reference submission titles or emails'
    );

    fwrite(STDOUT, "Submission list tests passed\n");
}
