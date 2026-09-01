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
                    return null; // this suite doesn't exercise review-round state
                }
            };
        }
    }
}

namespace PKP\core {
    final class PKPApplication
    {
        public const ROUTE_PAGE = 'page';
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

        /** @var array<int,object> */
        public static array $usersById = [];

        /** @var array<int,array<int,array<int>>> keyed by userId, value is the raw "stages" shape the provider iterates */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,bool> keyed by "submissionId:reviewerId" */
        public static array $reviewAssignments = [];

        /** @var array<int,object> keyed by issueId */
        public static array $issuesById = [];

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
                public function get(int $id): ?object
                {
                    return \APP\facades\Repo::$usersById[$id] ?? null;
                }

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

        public static function issue(): object
        {
            return new class () {
                public function get(int $id): ?object
                {
                    return \APP\facades\Repo::$issuesById[$id] ?? null;
                }
            };
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PublicationStatusSerializer;
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

    function publicationStatusCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakePublication
    {
        public function __construct(private string $title, private ?string $doi = null, private ?int $issueId = null)
        {
        }
        public function getLocalizedTitle(): string
        {
            return $this->title;
        }
        public function getDoi(): ?string
        {
            return $this->doi;
        }
        public function getIssueId(): ?int
        {
            return $this->issueId;
        }
    }

    final class FakeSubmission
    {
        public function __construct(
            private int $id,
            private int $contextId,
            private int $status = 1,
            private int $stageId = 1,
            private ?FakePublication $publication = null,
            private string $submissionProgress = ''
        ) {
            $this->publication ??= new FakePublication('Untitled');
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getBestId(): string
        {
            return (string) $this->id;
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
        public function getCurrentPublication(): FakePublication
        {
            return $this->publication;
        }
    }

    final class FakeIssue
    {
        public function __construct(private int $volume, private int $number, private int $year, private bool $published)
        {
        }
        public function getVolume(): int
        {
            return $this->volume;
        }
        public function getNumber(): int
        {
            return $this->number;
        }
        public function getYear(): int
        {
            return $this->year;
        }
        public function getPublished(): bool
        {
            return $this->published;
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

    final class FakeDispatcher
    {
        public function url($request, $routeType, $routeName, $page, $op, $path = []): string
        {
            return 'https://journal-a.example.com/article/view/' . implode('/', $path);
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
            return 'publicationStatus';
        }
        public function getDispatcher(): object
        {
            return new FakeDispatcher();
        }
    }

    // ================================================================
    // Part 1: PublicationStatusSerializer — allowlist shape and leak checks.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 3, stageId: 5, publication: new FakePublication('A Safe Title', '10.1234/example', 900));
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    publicationStatusCheck($authorRelationship !== null && $authorRelationship->has('author'), 'test fixture: author must resolve a relationship to their own submission');

    $verifiedPayload = PublicationStatusSerializer::verified($authorRelationship, 'published', '10.1234/example', 'https://journal-a.example.com/article/view/456', ['volume' => 12, 'number' => 3, 'year' => 2026], ['view_publication_status']);
    publicationStatusCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    publicationStatusCheck($verifiedPayload['resourceVerified'] === true, 'verified payload must say resourceVerified=true');
    publicationStatusCheck($verifiedPayload['assurance'] === 'v3', 'verified payload must carry v3 resource assurance');
    publicationStatusCheck($verifiedPayload['resource'] === ['type' => 'submission', 'id' => 456], 'verified payload must expose resource type/id');
    publicationStatusCheck($verifiedPayload['status'] === 'published', 'verified payload must expose the normalized status');
    publicationStatusCheck($verifiedPayload['doi'] === '10.1234/example', 'verified payload must expose the DOI when present');
    publicationStatusCheck($verifiedPayload['publicUrl'] === 'https://journal-a.example.com/article/view/456', 'verified payload must expose the public URL when published');
    publicationStatusCheck($verifiedPayload['issue'] === ['volume' => 12, 'number' => 3, 'year' => 2026], 'verified payload must expose issue volume/number/year');
    publicationStatusCheck(!array_key_exists('evidence', $verifiedPayload), 'verified payload must never expose the internal evidence array');
    publicationStatusCheck(!array_key_exists('title', $verifiedPayload), 'this endpoint must not duplicate submission-support fields like title');

    $verifiedJson = json_encode($verifiedPayload);
    foreach (['email', 'reviewer_id', 'reviewerName', 'abstract', 'orcid', 'file', 'title'] as $forbidden) {
        publicationStatusCheck(
            $verifiedJson !== false && !str_contains(strtolower($verifiedJson), strtolower($forbidden)),
            "verified payload must never contain the substring '{$forbidden}'"
        );
    }

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = PublicationStatusSerializer::unverified($unverifiedApiContext, ['list_my_submissions']);
    publicationStatusCheck($unverifiedPayload['resourceVerified'] === false, 'unverified payload must say resourceVerified=false');
    publicationStatusCheck(!array_key_exists('resource', $unverifiedPayload), 'unverified payload must not confirm a resource type/id');
    publicationStatusCheck(!array_key_exists('doi', $unverifiedPayload), 'unverified payload must never expose a DOI');
    publicationStatusCheck(!array_key_exists('status', $unverifiedPayload), 'unverified payload must never expose a status');

    // ================================================================
    // Part 2: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportPublicationStatusRequest() does. The endpoint
    // method itself is not called directly because it exits the process
    // via SupportApiResponse (same convention as the other suites).
    // ================================================================
    \APP\facades\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    final class InMemorySupportSessionRepositoryForPub implements SupportSessionRepositoryInterface
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
    $repo = new InMemorySupportSessionRepositoryForPub();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    publicationStatusCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    publicationStatusCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Published submission: full data including a published issue ---
    \APP\facades\Repo::$issuesById[900] = new FakeIssue(12, 3, 2026, true);
    $authorApiResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'publicationStatus');
    publicationStatusCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for publicationStatus');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    publicationStatusCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own published submission');

    $endpointDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest
    ));
    publicationStatusCheck(
        $endpointDecision->allows('submission.read_own_publication_status'),
        'a verified author relationship at v3 assurance must unlock submission.read_own_publication_status'
    );

    $stateFields = $bridge->getSubmissionStateFields($submissionForRequest);
    $supportState = SupportStateMapper::map($stateFields['status'], $stateFields['stageId'], $stateFields['reviewRoundStatus'], $stateFields['submissionProgress']);
    publicationStatusCheck($supportState === 'published', 'end-to-end: state must compute to published for a real status=3 submission');

    $publicationFields = $bridge->getPublicationFields($submissionForRequest);
    publicationStatusCheck($publicationFields['doi'] === '10.1234/example', 'end-to-end: bridge must read the real DOI through the compatibility adapter');
    publicationStatusCheck($publicationFields['issueId'] === 900, 'end-to-end: bridge must read the real issueId through the compatibility adapter');

    $issueInfo = $bridge->getIssueInfo($publicationFields['issueId']);
    publicationStatusCheck($issueInfo !== null && $issueInfo['published'] === true, 'end-to-end: bridge must read the real published issue through the compatibility adapter');
    publicationStatusCheck($issueInfo['volume'] === 12 && $issueInfo['number'] === 3 && $issueInfo['year'] === 2026, 'end-to-end: issue fields must match the real Issue object');

    $publicUrl = $bridge->getPublicSubmissionUrl(new FakeRequest(), $submissionForRequest);
    publicationStatusCheck($publicUrl === 'https://journal-a.example.com/article/view/456', 'end-to-end: bridge must build the real public URL through the compatibility adapter using getBestId()');

    // --- Unpublished issue must not leak issue metadata even if linked ---
    \APP\facades\Repo::$submissionsById[458] = new FakeSubmission(458, 7, status: 1, stageId: 3, publication: new FakePublication('In Review', null, 901));
    \APP\facades\Repo::$workflowStagesByUserId['42:458'] = [[65538]];
    \APP\facades\Repo::$issuesById[901] = new FakeIssue(13, 1, 2027, false); // not yet published
    $reviewSubmission = $bridge->loadSubmission(458);
    $reviewStateFields = $bridge->getSubmissionStateFields($reviewSubmission);
    $reviewState = SupportStateMapper::map($reviewStateFields['status'], $reviewStateFields['stageId'], $reviewStateFields['reviewRoundStatus'], $reviewStateFields['submissionProgress']);
    publicationStatusCheck($reviewState === 'review_in_progress', 'end-to-end: an in-review submission must not be treated as published');
    publicationStatusCheck($reviewState !== 'published' && $reviewState !== 'scheduled_for_publication', 'this state must map to not_yet_published at the endpoint level, never fabricate doi/url/issue');

    $reviewIssueId = $bridge->getPublicationFields($reviewSubmission)['issueId'];
    $reviewIssueInfo = $bridge->getIssueInfo($reviewIssueId);
    publicationStatusCheck($reviewIssueInfo !== null && $reviewIssueInfo['published'] === false, 'test fixture: this issue is genuinely not yet published');

    // --- Scheduled submission must expose doi/issue but never a public URL ---
    \APP\facades\Repo::$submissionsById[459] = new FakeSubmission(459, 7, status: 5, stageId: 5, publication: new FakePublication('Scheduled Piece', '10.1234/scheduled', 902));
    \APP\facades\Repo::$workflowStagesByUserId['42:459'] = [[65538]];
    \APP\facades\Repo::$issuesById[902] = new FakeIssue(14, 1, 2027, true);
    $scheduledSubmission = $bridge->loadSubmission(459);
    $scheduledStateFields = $bridge->getSubmissionStateFields($scheduledSubmission);
    $scheduledState = SupportStateMapper::map($scheduledStateFields['status'], $scheduledStateFields['stageId'], $scheduledStateFields['reviewRoundStatus'], $scheduledStateFields['submissionProgress']);
    publicationStatusCheck($scheduledState === 'scheduled_for_publication', 'end-to-end: status=5 must compute to scheduled_for_publication');
    // The endpoint itself is the one that enforces "no publicUrl unless
    // exactly 'published'" — verified here by re-checking the same
    // condition the endpoint source uses (Part 4 below asserts the guard exists).

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    publicationStatusCheck(
        $guessedRelationship !== null && $guessedRelationship->isEmpty(),
        'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error'
    );
    $guessedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $authorApiResult->assurance(),
        $authorApiResult->identity(),
        $guessedRelationship
    ));
    publicationStatusCheck(
        !$guessedDecision->allows('submission.read_own_publication_status'),
        'a guessed submission ID with no real relationship must never unlock submission.read_own_publication_status'
    );

    // --- Expired/unbound support session: resolver itself must return unverified ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'publicationStatus');
    publicationStatusCheck(
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
        publicationStatusCheck(
            !$lowAssuranceDecision->allows('submission.read_own_publication_status'),
            "assurance {$lowAssurance} must not unlock submission.read_own_publication_status even with a real relationship present"
        );
    }

    // ================================================================
    // Part 3: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    publicationStatusCheck(str_contains($pluginSource, 'function supportPublicationStatusRequest'), 'plugin must implement the publication-status endpoint');
    publicationStatusCheck(str_contains($pluginSource, 'PublicationStatusSerializer'), 'endpoint must use the dedicated allowlist serializer');
    publicationStatusCheck(str_contains($pluginSource, 'submission.read_own_publication_status'), 'endpoint must gate on submission.read_own_publication_status');
    publicationStatusCheck(
        str_contains($pluginSource, "\$supportState === 'published' ? \$bridge->getPublicSubmissionUrl"),
        'endpoint must restrict publicUrl generation to exactly the published state, never scheduled_for_publication'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    publicationStatusCheck(str_contains($handlerSource, 'function publicationStatus('), 'handler must register the publicationStatus operation');

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/PublicationStatusSerializer.php');
    publicationStatusCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Publication status tests passed\n");
}
