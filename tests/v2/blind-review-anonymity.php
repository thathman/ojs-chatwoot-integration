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
    }
}

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $submissionsById = [];

        /** @var array<string,array<int>> keyed by "userId:submissionId" -> raw workflow-stage role-id lists */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,object[]> keyed by "submissionId:reviewerId" -> list of FakeReviewAssignment */
        public static array $reviewAssignmentsByPair = [];

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

                        // Real Repo::reviewAssignment()->getCollector() has no
                        // "give me every reviewer of this submission" method at
                        // all in this plugin's usage — only ever filtered by a
                        // specific reviewer id. This fake deliberately omits
                        // one too, so a future accidental call site that tries
                        // to enumerate all reviewers fails loudly (missing
                        // method) rather than silently returning everyone's data.

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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionSupportSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\RequiredActionMapper;

    function blindReviewCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // POL-009/010: blind-review author / reviewer-anonymity coverage.
    //
    // The plugin's real reviewer-evidence and review-status lookups
    // (OjsSubmissionRelationshipEvidenceProvider::evidence(),
    // Ojs35CompatibilityAdapter::getReviewAssignmentStatuses()) only ever
    // call Repo::reviewAssignment()->getCollector()->filterByReviewerIds([$userId])
    // — i.e. "does THIS user have an assignment," never "list every
    // reviewer of this submission." That is the actual blind-review
    // guarantee here, not a redaction step applied after the fact. This
    // test proves it holds under a genuine two-reviewer fixture, using
    // the real production classes end-to-end rather than isolated units.
    // ================================================================

    final class FakeSubmissionForBlindReview
    {
        public function __construct(private int $id, private int $contextId)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getData(string $key): mixed
        {
            return $key === 'contextId' ? $this->contextId : null;
        }
    }

    final class FakeReviewAssignmentForBlindReview
    {
        public function __construct(private int $status)
        {
        }
        public function getStatus(): int
        {
            return $this->status;
        }
    }

    const SUBMISSION_ID = 900;
    const CONTEXT_ID = 7;
    const AUTHOR_USER_ID = 1;
    const REVIEWER_A_USER_ID = 42;
    const REVIEWER_B_USER_ID = 43;
    const STATUS_ACCEPTED = 5;       // reviewer A: must submit_review
    const STATUS_AWAITING_RESPONSE = 0; // reviewer B: must respond_to_review_invitation

    \APP\facades\Repo::$submissionsById[SUBMISSION_ID] = new FakeSubmissionForBlindReview(SUBMISSION_ID, CONTEXT_ID);
    \APP\facades\Repo::$workflowStagesByUserId[AUTHOR_USER_ID . ':' . SUBMISSION_ID] = [[\PKP\security\Role::ROLE_ID_AUTHOR]];
    // Reviewers are deliberately given NO workflow-stage role — matching
    // real OJS (reviewer access is a review assignment, not a workflow
    // stage grant), so evidence()'s author/editorial/manager checks must
    // all come back false for them.
    \APP\facades\Repo::$reviewAssignmentsByPair[SUBMISSION_ID . ':' . REVIEWER_A_USER_ID] = [new FakeReviewAssignmentForBlindReview(STATUS_ACCEPTED)];
    \APP\facades\Repo::$reviewAssignmentsByPair[SUBMISSION_ID . ':' . REVIEWER_B_USER_ID] = [new FakeReviewAssignmentForBlindReview(STATUS_AWAITING_RESPONSE)];

    $evidenceProvider = new OjsSubmissionRelationshipEvidenceProvider();
    $relationshipResolver = new SubmissionRelationshipResolver($evidenceProvider);
    $adapter = new Ojs35CompatibilityAdapter();
    $submission = \APP\facades\Repo::$submissionsById[SUBMISSION_ID];

    // --- Reviewer A sees only their own assignment/status/actions ---
    $reviewerAContext = new SupportContext(CONTEXT_ID, 'journal-a', REVIEWER_A_USER_ID, [\PKP\security\Role::ROLE_ID_REVIEWER], 'index', 'index', 'en');
    $reviewerARelationship = $relationshipResolver->resolve($reviewerAContext, $submission);
    blindReviewCheck($reviewerARelationship !== null && $reviewerARelationship->types() === ['reviewer'], 'reviewer A must resolve exactly one relationship type: reviewer');
    blindReviewCheck($reviewerARelationship->evidence()['author'] === false, 'reviewer A must never be evidenced as the author, even though the submission has one');

    $reviewerAStatuses = $adapter->getReviewAssignmentStatuses(SUBMISSION_ID, REVIEWER_A_USER_ID);
    blindReviewCheck($reviewerAStatuses === [STATUS_ACCEPTED], 'reviewer A must see only their own assignment status, never reviewer B\'s');
    blindReviewCheck(RequiredActionMapper::forReviewer($reviewerAStatuses) === ['submit_review'], 'reviewer A\'s required action must be derived only from their own status');

    // --- Reviewer B sees only their own assignment/status/actions ---
    $reviewerBContext = new SupportContext(CONTEXT_ID, 'journal-a', REVIEWER_B_USER_ID, [\PKP\security\Role::ROLE_ID_REVIEWER], 'index', 'index', 'en');
    $reviewerBRelationship = $relationshipResolver->resolve($reviewerBContext, $submission);
    blindReviewCheck($reviewerBRelationship !== null && $reviewerBRelationship->types() === ['reviewer'], 'reviewer B must resolve exactly one relationship type: reviewer');

    $reviewerBStatuses = $adapter->getReviewAssignmentStatuses(SUBMISSION_ID, REVIEWER_B_USER_ID);
    blindReviewCheck($reviewerBStatuses === [STATUS_AWAITING_RESPONSE], 'reviewer B must see only their own assignment status, never reviewer A\'s');
    blindReviewCheck(
        RequiredActionMapper::forReviewer($reviewerBStatuses) === ['respond_to_review_invitation'],
        'reviewer B\'s required action must be derived only from their own status, independent of reviewer A\'s different status on the same submission'
    );

    // --- Neither reviewer's serialized support-status response can carry
    // the other reviewer's identity, status, or a reviewer count ---
    $reviewerAResponse = SubmissionSupportSerializer::verified(
        $reviewerARelationship,
        'Test Submission',
        'under_review',
        'Your review is in progress.',
        RequiredActionMapper::forReviewer($reviewerAStatuses)
    );
    $reviewerAJson = (string) json_encode($reviewerAResponse);
    foreach ([(string) REVIEWER_B_USER_ID, 'respond_to_review_invitation'] as $forbidden) {
        blindReviewCheck(!str_contains($reviewerAJson, $forbidden), "reviewer A's serialized response must never contain reviewer B's data ({$forbidden})");
    }

    $reviewerBResponse = SubmissionSupportSerializer::verified(
        $reviewerBRelationship,
        'Test Submission',
        'under_review',
        'A response is needed.',
        RequiredActionMapper::forReviewer($reviewerBStatuses)
    );
    $reviewerBJson = (string) json_encode($reviewerBResponse);
    foreach (['submit_review'] as $forbidden) {
        blindReviewCheck(!str_contains($reviewerBJson, $forbidden), "reviewer B's serialized response must never contain reviewer A's data ({$forbidden})");
    }

    // --- The author sees no reviewer identity, status, or count either,
    // even though two real reviewer assignments exist on this submission ---
    $authorContext = new SupportContext(CONTEXT_ID, 'journal-a', AUTHOR_USER_ID, [\PKP\security\Role::ROLE_ID_AUTHOR], 'index', 'index', 'en');
    $authorRelationship = $relationshipResolver->resolve($authorContext, $submission);
    blindReviewCheck($authorRelationship !== null && $authorRelationship->types() === ['author'], 'the author must resolve exactly one relationship type: author');
    blindReviewCheck($authorRelationship->evidence()['reviewer'] === false, 'the author must never be evidenced as a reviewer');

    $authorResponse = SubmissionSupportSerializer::verified(
        $authorRelationship,
        'Test Submission',
        'under_review',
        'Your submission is under review.',
        []
    );
    $authorJson = (string) json_encode($authorResponse);
    foreach ([
        (string) REVIEWER_A_USER_ID,
        (string) REVIEWER_B_USER_ID,
        'reviewer_id',
        'reviewerName',
        'reviewerCount',
        'reviewer',
    ] as $forbidden) {
        blindReviewCheck(!str_contains($authorJson, $forbidden), "the author's serialized response must never reveal reviewer identity/count/existence ({$forbidden})");
    }

    fwrite(STDOUT, "Blind review anonymity tests passed\n");
}
