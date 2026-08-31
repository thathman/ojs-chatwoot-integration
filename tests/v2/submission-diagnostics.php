<?php

declare(strict_types=1);

namespace PKP\db {
    final class DAORegistry
    {
        /** @var array<string,int> keyed "submissionId:stageId" */
        public static array $reviewRoundStatusBySubmissionAndStage = [];

        /** @var array<string,bool> keyed "userId:submissionId" */
        public static array $completedPublicationPayments = [];

        public static function getDAO(string $name): object
        {
            if ($name === 'OJSCompletedPaymentDAO') {
                return new class () {
                    public function hasPaidPublication($userId, $articleId): bool
                    {
                        return \PKP\db\DAORegistry::$completedPublicationPayments["{$userId}:{$articleId}"] ?? false;
                    }
                };
            }

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
                    $status = \PKP\db\DAORegistry::$reviewRoundStatusBySubmissionAndStage["{$submissionId}:{$stageId}"] ?? null;
                    if ($status === null) {
                        return null;
                    }
                    return new class ($status) {
                        public function __construct(private int $status)
                        {
                        }
                        public function getStatus(): int
                        {
                            return $this->status;
                        }
                    };
                }
            };
        }
    }
}

namespace APP\payment\ojs {
    final class OJSPaymentManager
    {
        public function __construct(private $context)
        {
        }
        public function isConfigured(): bool
        {
            return (bool) $this->context->getData('paymentsEnabled');
        }
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

        /** @var array<string,object[]> keyed "submissionId:reviewerId" -> list of FakeReviewAssignment */
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SubmissionDiagnosticEngine;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\RequiredActionMapper;
    use APP\plugins\generic\chatwootIntegration\classes\v2\State\SupportStateMapper;

    function submissionDiagnosticsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeReviewAssignment
    {
        public function __construct(private int $status)
        {
        }
        public function getStatus(): int
        {
            return $this->status;
        }
    }

    final class FakeSubmission
    {
        public function __construct(
            private int $id,
            private int $contextId,
            private int $status = 1,
            private int $stageId = 1,
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
            return new class () {
                public function getLocalizedTitle(): string
                {
                    return 'Untitled';
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
        public function __construct(private bool $paymentsEnabled = false, private float $publicationFee = 0.0, private string $currency = 'USD')
        {
        }
        public function getId(): int
        {
            return 7;
        }
        public function getPath(): string
        {
            return 'journal-a';
        }
        public function getData(string $key): mixed
        {
            return match ($key) {
                'paymentsEnabled' => $this->paymentsEnabled,
                'publicationFee' => $this->publicationFee,
                'currency' => $this->currency,
                default => null,
            };
        }
    }

    final class FakeRequest
    {
        public function __construct(private FakeContext $context)
        {
        }
        public function getContext(): object
        {
            return $this->context;
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
            return 'submissionDiagnostics';
        }
    }

    // ================================================================
    // Part 1: SubmissionDiagnosticEngine — deterministic, evidence-only.
    // ================================================================
    // Determinism.
    $a = SubmissionDiagnosticEngine::diagnoseSubmissionProgress('revision_requested');
    $b = SubmissionDiagnosticEngine::diagnoseSubmissionProgress('revision_requested');
    submissionDiagnosticsCheck($a->code() === $b->code() && $a->status() === $b->status(), 'the same evidence must always produce the same diagnosis');

    // submission_access
    $accessConfirmed = SubmissionDiagnosticEngine::diagnoseSubmissionAccess(['author']);
    submissionDiagnosticsCheck($accessConfirmed->status() === DiagnosticResult::STATUS_CONFIRMED && $accessConfirmed->code() === 'SUBMISSION_ACCESS_CONFIRMED', 'a real relationship must confirm submission access');
    $accessEmpty = SubmissionDiagnosticEngine::diagnoseSubmissionAccess([]);
    submissionDiagnosticsCheck($accessEmpty->status() === DiagnosticResult::STATUS_UNKNOWN, 'no relationship evidence must never be confirmed as access');

    // submission_progress — matches the exact example in the spec: revision_requested -> confirmed REVISION_REQUIRED
    $revisionRequired = SubmissionDiagnosticEngine::diagnoseSubmissionProgress('revision_requested');
    submissionDiagnosticsCheck($revisionRequired->status() === DiagnosticResult::STATUS_CONFIRMED && $revisionRequired->code() === 'REVISION_REQUIRED', 'revision_requested must confirm REVISION_REQUIRED');
    submissionDiagnosticsCheck(in_array('submit_revisions', $revisionRequired->nextActions(), true), 'REVISION_REQUIRED must suggest submit_revisions as a next action');
    $unknownProgress = SubmissionDiagnosticEngine::diagnoseSubmissionProgress('unknown');
    submissionDiagnosticsCheck($unknownProgress->status() === DiagnosticResult::STATUS_UNKNOWN, 'an unknown support state must remain unknown, never guessed');
    $draftProgress = SubmissionDiagnosticEngine::diagnoseSubmissionProgress('draft');
    submissionDiagnosticsCheck($draftProgress->code() === 'SUBMISSION_INCOMPLETE' && in_array('complete_submission', $draftProgress->nextActions(), true), 'draft must confirm SUBMISSION_INCOMPLETE with complete_submission as next action');

    // required_action
    $noAction = SubmissionDiagnosticEngine::diagnoseRequiredAction([]);
    submissionDiagnosticsCheck($noAction->status() === DiagnosticResult::STATUS_CONFIRMED && $noAction->code() === 'NO_ACTION_REQUIRED', 'an empty required-actions list must confirm NO_ACTION_REQUIRED');
    $hasAction = SubmissionDiagnosticEngine::diagnoseRequiredAction(['submit_revisions']);
    submissionDiagnosticsCheck($hasAction->code() === 'ACTION_REQUIRED' && $hasAction->nextActions() === ['submit_revisions'], 'a non-empty required-actions list must confirm ACTION_REQUIRED and pass the actions through as nextActions');

    // review_access
    $notReviewer = SubmissionDiagnosticEngine::diagnoseReviewAccess(false, []);
    submissionDiagnosticsCheck($notReviewer->status() === DiagnosticResult::STATUS_UNKNOWN && $notReviewer->code() === 'NOT_A_REVIEWER', 'a non-reviewer must get NOT_A_REVIEWER, not a fabricated review status');
    $isReviewer = SubmissionDiagnosticEngine::diagnoseReviewAccess(true, [5]);
    submissionDiagnosticsCheck($isReviewer->status() === DiagnosticResult::STATUS_CONFIRMED && $isReviewer->code() === 'REVIEWER_ASSIGNMENT_FOUND', 'a real reviewer with assignment evidence must confirm REVIEWER_ASSIGNMENT_FOUND');

    // required_files (DIA-006)
    submissionDiagnosticsCheck(in_array(SubmissionDiagnosticEngine::SCOPE_REQUIRED_FILES, SubmissionDiagnosticEngine::SCOPES, true), 'required_files must be a real registered scope');
    $noneMissing = SubmissionDiagnosticEngine::diagnoseRequiredFiles([]);
    submissionDiagnosticsCheck(
        $noneMissing->status() === DiagnosticResult::STATUS_CONFIRMED && $noneMissing->code() === 'REQUIRED_FILES_COMPLETE',
        'an empty missing-genres list must confirm REQUIRED_FILES_COMPLETE (covers both "nothing required" and "everything uploaded")'
    );
    $someMissing = SubmissionDiagnosticEngine::diagnoseRequiredFiles(['Data Availability Statement']);
    submissionDiagnosticsCheck(
        $someMissing->status() === DiagnosticResult::STATUS_CONFIRMED
            && $someMissing->code() === 'REQUIRED_FILES_MISSING'
            && str_contains($someMissing->summary(), 'Data Availability Statement')
            && in_array('upload_required_files', $someMissing->nextActions(), true),
        'a non-empty missing-genres list must confirm REQUIRED_FILES_MISSING, name the missing genre, and suggest upload_required_files'
    );

    // publication
    $notPublished = SubmissionDiagnosticEngine::diagnosePublication('review_in_progress');
    submissionDiagnosticsCheck($notPublished->code() === 'PUBLICATION_NOT_YET_PUBLISHED', 'a non-published state must confirm PUBLICATION_NOT_YET_PUBLISHED');
    $published = SubmissionDiagnosticEngine::diagnosePublication('published');
    submissionDiagnosticsCheck($published->code() === 'PUBLICATION_PUBLISHED', 'published state must confirm PUBLICATION_PUBLISHED');

    // payment — must never reveal more than the dedicated endpoint would
    $paymentDenied = SubmissionDiagnosticEngine::diagnosePayment(false, true, null);
    submissionDiagnosticsCheck($paymentDenied->status() === DiagnosticResult::STATUS_UNKNOWN && $paymentDenied->code() === 'PAYMENT_STATUS_UNAVAILABLE', 'a denied payment capability must never reveal specific payment status, matching the dedicated endpoint\'s default-off policy');
    $paymentNotApplicable = SubmissionDiagnosticEngine::diagnosePayment(true, false, null);
    submissionDiagnosticsCheck($paymentNotApplicable->code() === 'PAYMENT_NOT_APPLICABLE', 'an allowed capability with fee disabled must confirm PAYMENT_NOT_APPLICABLE');
    $paymentPaid = SubmissionDiagnosticEngine::diagnosePayment(true, true, true);
    submissionDiagnosticsCheck($paymentPaid->code() === 'PAYMENT_PAID', 'an allowed capability with a completed payment must confirm PAYMENT_PAID');
    $paymentUnpaid = SubmissionDiagnosticEngine::diagnosePayment(true, true, false);
    submissionDiagnosticsCheck($paymentUnpaid->code() === 'PAYMENT_UNPAID', 'an allowed capability with no completed payment must confirm PAYMENT_UNPAID');

    // ================================================================
    // Part 2: DiagnosticResultSerializer — every known code round-trips.
    // ================================================================
    foreach ([$accessConfirmed, $revisionRequired, $unknownProgress, $noAction, $hasAction, $notReviewer, $isReviewer, $notPublished, $published, $paymentDenied, $paymentNotApplicable, $paymentPaid, $paymentUnpaid] as $result) {
        $payload = DiagnosticResultSerializer::verified($result, ['view_status']);
        submissionDiagnosticsCheck($payload['code'] === $result->code(), "serialized payload must preserve the diagnostic code {$result->code()}");
        submissionDiagnosticsCheck(!array_key_exists('rawSubmission', $payload) && !array_key_exists('submission', $payload), "serialized payload for code {$result->code()} must never expose a raw submission object");
    }

    // ================================================================
    // Part 3: catalog contract — V3 + author/reviewer, same as the other
    // submission-scoped capabilities.
    // ================================================================
    $catalogDefinition = CapabilityCatalog::definition('submission.diagnose_own');
    submissionDiagnosticsCheck(($catalogDefinition['minVerification'] ?? 0) === 3, 'submission.diagnose_own must require V3');
    submissionDiagnosticsCheck(($catalogDefinition['relationships'] ?? []) === ['author', 'reviewer'], 'submission.diagnose_own must be available to both authors and reviewers');

    // ================================================================
    // Part 4: end-to-end through the bridge + real resolver, proving
    // relationship isolation (reviewer cannot get author/payment
    // evidence, author cannot get reviewer identity) and live recompute
    // (removed relationship immediately removes diagnostic access).
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7, status: 1, stageId: 3);
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]]; // author
    \PKP\db\DAORegistry::$reviewRoundStatusBySubmissionAndStage['456:3'] = 1; // REVISIONS_REQUESTED

    \APP\facades\Repo::$submissionsById[457] = new FakeSubmission(457, 7, status: 1, stageId: 2);
    \APP\facades\Repo::$workflowStagesByUserId['43:457'] = [];
    \APP\facades\Repo::$reviewAssignmentsByPair['457:43'] = [new FakeReviewAssignment(5)]; // ACCEPTED, reviewer only

    $authorRelationship = $resolver->resolve(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'), \APP\facades\Repo::$submissionsById[456]);
    submissionDiagnosticsCheck($authorRelationship !== null && $authorRelationship->has('author') && !$authorRelationship->has('reviewer'), 'test fixture: user 42 must be author-only on 456');
    $reviewerRelationship = $resolver->resolve(new SupportContext(7, 'journal-a', 43, [65536], 'index', 'index', 'en'), \APP\facades\Repo::$submissionsById[457]);
    submissionDiagnosticsCheck($reviewerRelationship !== null && $reviewerRelationship->has('reviewer') && !$reviewerRelationship->has('author'), 'test fixture: user 43 must be reviewer-only on 457');

    // Reviewer cannot obtain author-only evidence: required_action for a
    // reviewer-only relationship must never include RequiredActionMapper::forAuthor().
    $reviewerRequiredActions = array_values(array_unique(array_merge(
        $reviewerRelationship->has('author') ? RequiredActionMapper::forAuthor('draft') : [],
        $reviewerRelationship->has('reviewer') ? RequiredActionMapper::forReviewer([5]) : []
    )));
    submissionDiagnosticsCheck($reviewerRequiredActions === ['submit_review'], 'a reviewer-only relationship must only ever surface reviewer required actions, never author ones');

    // Author cannot obtain reviewer identity: review_access for an
    // author-only relationship must report NOT_A_REVIEWER, never fabricate
    // reviewer assignment evidence.
    $authorReviewAccess = SubmissionDiagnosticEngine::diagnoseReviewAccess($authorRelationship->has('reviewer'), []);
    submissionDiagnosticsCheck($authorReviewAccess->code() === 'NOT_A_REVIEWER', 'an author-only relationship must never be told they have reviewer access');

    // Live recompute: removing the relationship immediately removes access —
    // the endpoint always re-resolves via resolveSubmissionRelationship(),
    // never caches.
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [];
    $afterRevocation = $resolver->resolve(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'), \APP\facades\Repo::$submissionsById[456]);
    submissionDiagnosticsCheck($afterRevocation->isEmpty(), 'removing the author relationship must be respected immediately on the next resolve(), with no caching');
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]]; // restore for later assertions

    // ================================================================
    // Part 5: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportSubmissionDiagnosticsRequest() does. The
    // endpoint method itself is not called directly because it exits the
    // process via SupportApiResponse (same convention as the other suites).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);
    \PKP\user\Repo::$usersById[43] = new FakeOjsUser(43, [65536]);

    final class InMemorySupportSessionRepositoryForSubmissionDiag implements SupportSessionRepositoryInterface
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
    $repo = new InMemorySupportSessionRepositoryForSubmissionDiag();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrapAuthor = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $boundAuthor = $service->bindAuthenticatedBootstrap($bootstrapAuthor->bindingToken(), 7, 42, '1', '100', '500');
    submissionDiagnosticsCheck($boundAuthor !== null, 'test fixture: author bootstrap should bind');
    $bootstrapReviewer = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 43, [65536], 'index', 'index', 'en'));
    $boundReviewer = $service->bindAuthenticatedBootstrap($bootstrapReviewer->bindingToken(), 7, 43, '1', '100', '501');
    submissionDiagnosticsCheck($boundReviewer !== null, 'test fixture: reviewer bootstrap should bind');

    $noFeeContext = new FakeContext(paymentsEnabled: false);
    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest($noFeeContext), 'en');
    submissionDiagnosticsCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Verified author on their own revision-requested submission ---
    $authorApiResult = $apiResolver->resolve(new FakeRequest($noFeeContext), 'corr-1', 7, 'service-secret', '1', '100', '500', 'submissionDiagnostics');
    submissionDiagnosticsCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for submissionDiagnostics');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    submissionDiagnosticsCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $endpointDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest
    ));
    submissionDiagnosticsCheck($endpointDecision->allows('submission.diagnose_own'), 'a verified author relationship at v3 assurance must unlock submission.diagnose_own');

    $stateFields = $bridge->getSubmissionStateFields($submissionForRequest);
    $supportState = SupportStateMapper::map($stateFields['status'], $stateFields['stageId'], $stateFields['reviewRoundStatus'], $stateFields['submissionProgress']);
    submissionDiagnosticsCheck($supportState === 'revision_requested', 'end-to-end: the real submission must compute to revision_requested via the same SupportStateMapper the endpoint uses');
    $endToEndDiagnosis = SubmissionDiagnosticEngine::diagnoseSubmissionProgress($supportState);
    submissionDiagnosticsCheck($endToEndDiagnosis->code() === 'REVISION_REQUIRED', 'end-to-end: the diagnosis must match the spec\'s own worked example for this exact evidence');

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    submissionDiagnosticsCheck($guessedRelationship !== null && $guessedRelationship->isEmpty(), 'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error');
    $guessedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        $authorApiResult->assurance(),
        $authorApiResult->identity(),
        $guessedRelationship
    ));
    submissionDiagnosticsCheck(!$guessedDecision->allows('submission.diagnose_own'), 'a guessed submission ID with no real relationship must never unlock submission.diagnose_own — this endpoint cannot be used to probe arbitrary submissions');

    // --- Expired/unbound support session: resolver itself must return unverified ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest($noFeeContext), 'corr-2', 7, 'service-secret', '1', '100', '999', 'submissionDiagnostics');
    submissionDiagnosticsCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any submission is even loaded — anti-enumeration preserved'
    );

    // --- Payment scope: capability denial cannot be bypassed through diagnostics ---
    \PKP\db\DAORegistry::$completedPublicationPayments['42:456'] = true; // a real completed payment exists...
    $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext(new FakeRequest($noFeeContext)));
    submissionDiagnosticsCheck($feeInfo['enabled'] === false, 'test fixture: fees are disabled on this journal context');
    $paymentCapabilityDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest,
        ['payment_status' => $feeInfo['enabled']]
    ));
    $paymentAllowed = $paymentCapabilityDecision->allows('submission.read_own_payment_status');
    submissionDiagnosticsCheck(!$paymentAllowed, 'the payment capability must independently deny here exactly as it would for the dedicated payment endpoint');
    $paymentDiagnosisEndToEnd = SubmissionDiagnosticEngine::diagnosePayment($paymentAllowed, $feeInfo['enabled'], null);
    submissionDiagnosticsCheck(
        $paymentDiagnosisEndToEnd->code() === 'PAYMENT_STATUS_UNAVAILABLE',
        '...but the diagnostic must still refuse to reveal it, proving diagnostics cannot bypass the payment capability gate even when a completed payment genuinely exists'
    );

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // --- V0-V2 cannot reach the V3 path even with a real, positive relationship ---
    foreach (['v0', 'v1', 'v2'] as $lowAssurance) {
        $lowAssuranceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $lowAssurance,
            $authorApiResult->identity(),
            $relationshipForRequest
        ));
        submissionDiagnosticsCheck(
            !$lowAssuranceDecision->allows('submission.diagnose_own'),
            "assurance {$lowAssurance} must not unlock submission.diagnose_own even with a real relationship present"
        );
    }

    // ================================================================
    // Part 6: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    submissionDiagnosticsCheck(str_contains($pluginSource, 'function supportSubmissionDiagnosticsRequest'), 'plugin must implement the submission-diagnostics endpoint');
    submissionDiagnosticsCheck(str_contains($pluginSource, 'submission.diagnose_own'), 'endpoint must gate on submission.diagnose_own');
    submissionDiagnosticsCheck(str_contains($pluginSource, 'submission.read_own_payment_status'), 'the payment scope must independently re-check submission.read_own_payment_status');

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    submissionDiagnosticsCheck(str_contains($handlerSource, 'function submissionDiagnostics('), 'handler must register the submissionDiagnostics operation');

    $engineSource = (string) file_get_contents($root . '/classes/v2/Diagnostics/SubmissionDiagnosticEngine.php');
    submissionDiagnosticsCheck(!str_contains($engineSource, '->evidence()'), 'engine must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Submission diagnostics tests passed\n");
}
