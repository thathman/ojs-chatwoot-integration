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

        /** @var array<int,array<int,array<int>>> keyed by userId, value is the raw "stages" shape the provider iterates */
        public static array $workflowStagesByUserId = [];

        /** @var array<string,bool> keyed by "submissionId:reviewerId" */
        public static array $reviewAssignments = [];

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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionVerificationSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SubmissionRelationshipEvidenceProviderInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

    function submissionVerifyCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeSubmission
    {
        public function __construct(private int $id, private int $contextId) {}
        public function getId(): int { return $this->id; }
        public function getData(string $key): mixed { return $key === 'contextId' ? $this->contextId : null; }
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
        public function getRequestedOp(): string { return 'submissionVerify'; }
    }

    // ================================================================
    // Part 1: SubmissionRelationshipResolver + the real OJS evidence
    // provider, against a stubbed APP\facades\Repo. This is the actual
    // production evidence path (not a fake provider), so it covers the
    // exact live-recompute guarantees the endpoint depends on.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7);
    \APP\facades\Repo::$submissionsById[999] = new FakeSubmission(999, 8); // different journal

    // Author case: workflow stage carries the Author role.
    \APP\facades\Repo::$workflowStagesByUserId["42:456"] = [[65538]]; // Role::ROLE_ID_AUTHOR
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck($authorRelationship !== null && $authorRelationship->has('author'), 'author can verify own submission');
    submissionVerifyCheck($authorRelationship->types() === ['author'], 'author-only relationship should not fabricate other types');

    // Reviewer case: an actual review assignment exists.
    \APP\facades\Repo::$workflowStagesByUserId["43:456"] = [];
    \APP\facades\Repo::$reviewAssignments['456:43'] = true;
    $reviewerContext = new SupportContext(7, 'journal-a', 43, [65536], 'index', 'index', 'en'); // journal-level Reviewer role
    $reviewerRelationship = $resolver->resolve($reviewerContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck($reviewerRelationship !== null && $reviewerRelationship->has('reviewer'), 'assigned reviewer can verify their actual assigned submission');

    // Journal-level Reviewer role ALONE (no assignment, no workflow stage) must not verify.
    $unassignedReviewerContext = new SupportContext(7, 'journal-a', 44, [65536], 'index', 'index', 'en');
    \APP\facades\Repo::$workflowStagesByUserId["44:456"] = [];
    $unassignedRelationship = $resolver->resolve($unassignedReviewerContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $unassignedRelationship !== null && $unassignedRelationship->isEmpty(),
        'journal-level Reviewer role alone must not verify a submission relationship'
    );

    // Multi-relationship: same user has both an author workflow stage AND a review assignment.
    \APP\facades\Repo::$workflowStagesByUserId["45:456"] = [[65538]];
    \APP\facades\Repo::$reviewAssignments['456:45'] = true;
    $multiContext = new SupportContext(7, 'journal-a', 45, [65538, 65536], 'index', 'index', 'en');
    $multiRelationship = $resolver->resolve($multiContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $multiRelationship !== null && $multiRelationship->has('author') && $multiRelationship->has('reviewer'),
        'a genuinely multi-role user must resolve both relationships, not a single collapsed "primary" role'
    );
    submissionVerifyCheck(
        $multiRelationship->types() === ['author', 'reviewer'],
        'relationships must be plural and sorted, never collapsed to one'
    );

    // Unrelated user: no workflow stage, no assignment.
    \APP\facades\Repo::$workflowStagesByUserId["46:456"] = [];
    $unrelatedContext = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unrelatedRelationship = $resolver->resolve($unrelatedContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $unrelatedRelationship !== null && $unrelatedRelationship->isEmpty(),
        'an unrelated user must resolve to an empty (unverified) relationship, not null/error'
    );

    // Submission in another journal: the resolver itself must fail closed.
    $crossJournalRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[999]);
    submissionVerifyCheck($crossJournalRelationship === null, 'a submission belonging to another journal must not resolve any relationship');

    // Missing submission: resolver receives null.
    $missingRelationship = $resolver->resolve($authorContext, null);
    submissionVerifyCheck($missingRelationship === null, 'a null/missing submission must not resolve any relationship');

    // Provider cannot manufacture a relationship type outside the fixed allowlist.
    final class InjectingEvidenceProvider implements SubmissionRelationshipEvidenceProviderInterface
    {
        public function evidence(SupportContext $context, $submission): array
        {
            return ['author' => false, 'reviewer' => false, 'editorial' => false, 'manager' => false, 'site_admin' => false, 'payment_admin' => true];
        }
    }
    $injectingResolver = new SubmissionRelationshipResolver(new InjectingEvidenceProvider());
    $injected = $injectingResolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $injected !== null && !$injected->has('payment_admin') && $injected->isEmpty(),
        'a provider cannot manufacture a relationship type outside the resolver\'s fixed allowlist'
    );

    // Live recompute: role change after "binding" (i.e. between two calls) is respected immediately.
    \APP\facades\Repo::$workflowStagesByUserId["47:456"] = [[65538]];
    $liveUserContext = new SupportContext(7, 'journal-a', 47, [65538], 'index', 'index', 'en');
    $beforeRoleChange = $resolver->resolve($liveUserContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck($beforeRoleChange->has('author'), 'precondition: user 47 starts as author');
    \APP\facades\Repo::$workflowStagesByUserId["47:456"] = []; // author access revoked in OJS
    $afterRoleChange = $resolver->resolve($liveUserContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $afterRoleChange->isEmpty(),
        'a role/workflow-stage change must be respected immediately on the next call, with no caching'
    );

    // Live recompute: review assignment removed after binding is respected immediately.
    \APP\facades\Repo::$reviewAssignments['456:48'] = true;
    \APP\facades\Repo::$workflowStagesByUserId["48:456"] = [];
    $liveReviewerContext = new SupportContext(7, 'journal-a', 48, [65536], 'index', 'index', 'en');
    $beforeUnassign = $resolver->resolve($liveReviewerContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck($beforeUnassign->has('reviewer'), 'precondition: user 48 starts as an assigned reviewer');
    \APP\facades\Repo::$reviewAssignments['456:48'] = false; // assignment removed in OJS
    $afterUnassign = $resolver->resolve($liveReviewerContext, \APP\facades\Repo::$submissionsById[456]);
    submissionVerifyCheck(
        $afterUnassign->isEmpty(),
        'removing a review assignment must be respected immediately, with no caching'
    );

    // ================================================================
    // Part 2: SubmissionVerificationSerializer — allowlist shape and
    // evidence-leak checks.
    // ================================================================
    $verifiedPayload = SubmissionVerificationSerializer::verified($multiRelationship, 'v3', ['get_submission_support']);
    submissionVerifyCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    submissionVerifyCheck($verifiedPayload['resourceVerified'] === true, 'verified payload must say resourceVerified=true');
    submissionVerifyCheck($verifiedPayload['assurance'] === 'v3', 'verified payload must carry v3 resource assurance');
    submissionVerifyCheck($verifiedPayload['resource'] === ['type' => 'submission', 'id' => 456], 'verified payload must expose resource type/id');
    submissionVerifyCheck($verifiedPayload['relationships'] === ['author', 'reviewer'], 'verified payload must expose plural relationships');
    submissionVerifyCheck(!array_key_exists('evidence', $verifiedPayload), 'verified payload must never expose the internal evidence array');

    $verifiedJson = json_encode($verifiedPayload);
    submissionVerifyCheck(
        $verifiedJson !== false
            && !str_contains(strtolower($verifiedJson), 'title')
            && !str_contains(strtolower($verifiedJson), 'doi')
            && !str_contains(strtolower($verifiedJson), 'abstract')
            && !str_contains(strtolower($verifiedJson), 'file'),
        'verified payload must never leak manuscript title/DOI/abstract/file fields'
    );

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = \APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = SubmissionVerificationSerializer::unverified($unverifiedApiContext, ['list_my_submissions', 'escalate_support']);
    submissionVerifyCheck($unverifiedPayload['resourceVerified'] === false, 'unverified payload must say resourceVerified=false');
    submissionVerifyCheck(!array_key_exists('resource', $unverifiedPayload), 'unverified payload must not confirm a resource type/id');
    submissionVerifyCheck(!array_key_exists('relationships', $unverifiedPayload), 'unverified payload must not expose relationships when unverified');

    // ================================================================
    // Part 3: RuntimeContextBridge/kernel wiring for loadSubmission() and
    // resolveSubmissionRelationship() (catches wiring bugs the unit tests
    // above, which bypass the bridge entirely, cannot).
    // ================================================================
    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest(), 'en');
    submissionVerifyCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $loadedExisting = $bridge->loadSubmission(456);
    submissionVerifyCheck($loadedExisting !== null && $loadedExisting->getId() === 456, 'bridge must load a submission by ID through the compatibility adapter');

    $loadedMissing = $bridge->loadSubmission(123456789);
    submissionVerifyCheck($loadedMissing === null, 'loading a nonexistent submission ID must return null, not throw');

    \APP\facades\Repo::$workflowStagesByUserId["42:456"] = [[65538]];
    $authorContextForBridge = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $bridgeRelationship = $bridge->resolveSubmissionRelationship($authorContextForBridge, $loadedExisting);
    submissionVerifyCheck($bridgeRelationship !== null && $bridgeRelationship->has('author'), 'bridge must wire through to the real relationship resolver');

    // ================================================================
    // Part 4: end-to-end through SupportApiRequestResolver, replicating
    // exactly what supportSubmissionVerifyRequest() does afterward — the
    // endpoint method itself is not called directly because it exits the
    // process via SupportApiResponse (same convention as support-status.php).
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    final class InMemorySupportSessionRepositoryForVerify implements SupportSessionRepositoryInterface
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
    $repo = new InMemorySupportSessionRepositoryForVerify();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    submissionVerifyCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);

    // --- Authorized author verifying their own submission ---
    $authorApiResult = $apiResolver->resolve(new FakeRequest(), 'corr-1', 7, 'service-secret', '1', '100', '500', 'submissionVerify');
    submissionVerifyCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must still verify the V2 conversation identity for submissionVerify');

    \APP\facades\Repo::$workflowStagesByUserId["42:456"] = [[65538]];
    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    submissionVerifyCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $endpointDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest
    ));
    $endpointActions = $bridge->availableActions($endpointDecision);
    submissionVerifyCheck(
        in_array('view_status', $endpointActions, true) && in_array('view_required_actions', $endpointActions, true),
        'a verified author relationship at v3 assurance must unlock submission-scoped actions'
    );

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    submissionVerifyCheck(
        $guessedRelationship !== null && $guessedRelationship->isEmpty(),
        'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error'
    );

    // --- Expired/unbound support session: resolver itself must return unverified, never reach submission logic ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest(), 'corr-2', 7, 'service-secret', '1', '100', '999', 'submissionVerify');
    submissionVerifyCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any submission is even loaded'
    );

    // --- Wrong conversation tuple must not resolve the bound session ---
    $wrongTuple = $apiResolver->resolve(new FakeRequest(), 'corr-3', 7, 'service-secret', '2', '100', '500', 'submissionVerify');
    submissionVerifyCheck(
        !($wrongTuple instanceof SupportApiFailure) && $wrongTuple->verified() === false,
        'a wrong conversation tuple must resolve as unverified, not error and not leak the real binding'
    );

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // --- V1/V0 cannot reach the V3 path: even a real, positive relationship
    //     match must not unlock a submission-scoped capability if the caller
    //     is only V1 (or V0). A caller can never fabricate assurance by
    //     just supplying a relationship. ---
    $catalogDefinition = CapabilityCatalog::definition('submission.read_own_support_status');
    submissionVerifyCheck(
        ($catalogDefinition['minVerification'] ?? 0) === 3,
        'submission-scoped capabilities must require v3 in the catalog, not a lower level'
    );

    foreach (['v0', 'v1', 'v2'] as $lowAssurance) {
        $lowAssuranceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $lowAssurance,
            $authorContextForBridge,
            $bridgeRelationship // a genuine, positive author relationship
        ));
        submissionVerifyCheck(
            !$lowAssuranceDecision->allows('submission.read_own_support_status'),
            "assurance {$lowAssurance} must not unlock submission-scoped capabilities even with a real relationship present"
        );
    }
    $v3Decision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorContextForBridge,
        $bridgeRelationship
    ));
    submissionVerifyCheck(
        $v3Decision->allows('submission.read_own_support_status'),
        'sanity check: v3 assurance with a real relationship must unlock the capability (proves the v0-v2 denials above are real, not a broken test)'
    );

    // ================================================================
    // Part 5: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    submissionVerifyCheck(str_contains($pluginSource, 'function supportSubmissionVerifyRequest'), 'plugin must implement the submission-verify endpoint');
    submissionVerifyCheck(str_contains($pluginSource, 'SubmissionVerificationSerializer'), 'endpoint must use the dedicated allowlist serializer');
    submissionVerifyCheck(str_contains($pluginSource, "resourceAssurance = \$relationship ? 'v3'"), 'v3 must be computed per-request, gated on an actual relationship');
    submissionVerifyCheck(
        !preg_match('/assuranceLevel\(\)\s*=\s*[\'"]v3[\'"]/', $pluginSource)
        && !str_contains($pluginSource, "session->save")
        && !str_contains($pluginSource, "'v3', \$contextId"),
        'v3 must never be written back onto the persisted support session'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    submissionVerifyCheck(str_contains($handlerSource, 'function submissionVerify('), 'handler must register the submissionVerify operation');

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/SubmissionVerificationSerializer.php');
    submissionVerifyCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    $resolverSource = (string) file_get_contents($root . '/classes/v2/Api/SupportApiRequestResolver.php');
    submissionVerifyCheck(str_contains($resolverSource, 'audit->record'), 'the shared resolver (reused by submissionVerify) must still record audit decisions with a safe reason code');

    $auditSource = (string) file_get_contents($root . '/classes/v2/Audit/ErrorLogSupportApiAuditLogger.php');
    submissionVerifyCheck(
        !str_contains($auditSource, 'title') && !str_contains($auditSource, 'abstract') && !str_contains($auditSource, 'evidence'),
        'audit sink must not reference manuscript content or raw relationship evidence'
    );

    fwrite(STDOUT, "Submission verification tests passed\n");
}
