<?php

declare(strict_types=1);

namespace PKP\db {
    final class DAORegistry
    {
        /** @var array<string,bool> keyed "userId:submissionId" */
        public static array $completedPublicationPayments = [];

        public static function getDAO(string $name): object
        {
            if ($name === 'OJSCompletedPaymentDAO') {
                return new class {
                    public function hasPaidPublication($userId, $articleId): bool
                    {
                        return \PKP\db\DAORegistry::$completedPublicationPayments["{$userId}:{$articleId}"] ?? false;
                    }
                };
            }

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
    /**
     * Mirrors only the two real OJSPaymentManager methods this adapter
     * calls (isConfigured()/publicationEnabled()) — the real class's own
     * logic is verified against pkp-lib/ojs stable-3_5_0 source directly,
     * not re-tested here; this fake only proves our adapter wires to it
     * correctly.
     */
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaymentStatusSerializer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

    function paymentStatusCheck(bool $condition, string $message): void
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
        public function __construct(private bool $paymentsEnabled = false, private float $publicationFee = 0.0, private string $currency = 'USD') {}
        public function getId(): int { return 7; }
        public function getPath(): string { return 'journal-a'; }
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
        public function __construct(private FakeContext $context) {}
        public function getContext(): object { return $this->context; }
        public function getUser(): ?object { return null; }
        public function getRequestedPage(): string { return 'ojsSupportGateway'; }
        public function getRequestedOp(): string { return 'paymentStatus'; }
    }

    // ================================================================
    // Part 1: PaymentStatusSerializer — allowlist shape and leak checks.
    // ================================================================
    $provider = new OjsSubmissionRelationshipEvidenceProvider();
    $resolver = new SubmissionRelationshipResolver($provider);

    \APP\facades\Repo::$submissionsById[456] = new FakeSubmission(456, 7);
    \APP\facades\Repo::$workflowStagesByUserId['42:456'] = [[65538]];
    $authorContext = new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $authorRelationship = $resolver->resolve($authorContext, \APP\facades\Repo::$submissionsById[456]);
    paymentStatusCheck($authorRelationship !== null && $authorRelationship->has('author'), 'test fixture: author must resolve a relationship to their own submission');

    $verifiedPayload = PaymentStatusSerializer::verified($authorRelationship, ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'], 'paid', ['view_payment_status']);
    paymentStatusCheck($verifiedPayload['verified'] === true, 'verified payload must say verified=true');
    paymentStatusCheck($verifiedPayload['resourceVerified'] === true, 'verified payload must say resourceVerified=true');
    paymentStatusCheck($verifiedPayload['assurance'] === 'v3', 'verified payload must carry v3 resource assurance');
    paymentStatusCheck($verifiedPayload['resource'] === ['type' => 'submission', 'id' => 456], 'verified payload must expose resource type/id');
    paymentStatusCheck($verifiedPayload['feeEnabled'] === true, 'verified payload must expose feeEnabled');
    paymentStatusCheck($verifiedPayload['amount'] === 50.0, 'verified payload must expose the amount');
    paymentStatusCheck($verifiedPayload['currency'] === 'USD', 'verified payload must expose the currency');
    paymentStatusCheck($verifiedPayload['status'] === 'paid', 'verified payload must expose the submission-specific status');
    paymentStatusCheck(!array_key_exists('evidence', $verifiedPayload), 'verified payload must never expose the internal evidence array');

    $verifiedJson = json_encode($verifiedPayload);
    foreach (['email', 'reviewer_id', 'reviewerName', 'card', 'cvv', 'account_number', 'gateway', 'secret', 'token'] as $forbidden) {
        paymentStatusCheck(
            $verifiedJson !== false && !str_contains(strtolower($verifiedJson), strtolower($forbidden)),
            "verified payload must never contain the substring '{$forbidden}'"
        );
    }

    // ================================================================
    // Part 1b: PTF-011 safe `pay` action descriptor — only when a real
    // provider-returned payUrl exists and the balance is genuinely still owed.
    // ================================================================
    $unpaidWithUrl = PaymentStatusSerializer::verified(
        $authorRelationship,
        ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'],
        'unpaid',
        ['view_payment_status'],
        [['producer' => 'airix.submission_fee', 'feeKey' => 'submission_fee', 'status' => 'unpaid', 'amount' => 50.0, 'payableAmount' => 50.0, 'currency' => 'USD', 'payUrl' => 'https://example.test/journal-a/submissionFee/pay/456']]
    );
    paymentStatusCheck($unpaidWithUrl['payUrl'] === 'https://example.test/journal-a/submissionFee/pay/456', 'an unpaid obligation with a real payUrl must surface it at the top level');
    paymentStatusCheck(in_array('pay', $unpaidWithUrl['availableActions'], true), 'an unpaid obligation with a real payUrl must add the "pay" action descriptor');

    $partiallyWaivedWithUrl = PaymentStatusSerializer::verified(
        $authorRelationship,
        ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'],
        'partially_waived',
        ['view_payment_status'],
        [['producer' => 'airix.submission_fee', 'feeKey' => 'submission_fee', 'status' => 'partially_waived', 'amount' => 50.0, 'payableAmount' => 25.0, 'currency' => 'USD', 'payUrl' => 'https://example.test/journal-a/submissionFee/pay/456']]
    );
    paymentStatusCheck(in_array('pay', $partiallyWaivedWithUrl['availableActions'], true), 'a partially-waived obligation with a real payUrl must still add the "pay" action — a discounted balance is still owed');

    foreach (['paid', 'waived', 'refunded', 'refund_review', 'unknown', 'not_applicable'] as $nonPayableStatus) {
        $nonPayablePayload = PaymentStatusSerializer::verified(
            $authorRelationship,
            ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'],
            $nonPayableStatus,
            ['view_payment_status'],
            [['producer' => 'airix.submission_fee', 'feeKey' => 'submission_fee', 'status' => $nonPayableStatus, 'amount' => 50.0, 'payableAmount' => 0.0, 'currency' => 'USD', 'payUrl' => 'https://example.test/journal-a/submissionFee/pay/456']]
        );
        paymentStatusCheck($nonPayablePayload['payUrl'] === null, "status \"{$nonPayableStatus}\" must never surface a payUrl even if a provider returned one — paying again would be wrong/meaningless");
        paymentStatusCheck(!in_array('pay', $nonPayablePayload['availableActions'], true), "status \"{$nonPayableStatus}\" must never add the \"pay\" action descriptor");
    }

    $unpaidNoUrl = PaymentStatusSerializer::verified($authorRelationship, ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'], 'unpaid', ['view_payment_status']);
    paymentStatusCheck($unpaidNoUrl['payUrl'] === null, 'an unpaid status with no obligation-provided payUrl must never fabricate one (e.g. the native-only producer path)');
    paymentStatusCheck(!in_array('pay', $unpaidNoUrl['availableActions'], true), 'no real payUrl must mean no "pay" action descriptor — never a dead link');

    $unverifiedIdentity = new SupportContext(7, 'journal-a', 46, [], 'index', 'index', 'en');
    $unverifiedApiContext = SupportApiRequestContext::unverified('corr-x', 7, $unverifiedIdentity);
    $unverifiedPayload = PaymentStatusSerializer::unverified($unverifiedApiContext, ['enabled' => true, 'amount' => 50.0, 'currency' => 'USD'], ['list_my_submissions']);
    paymentStatusCheck($unverifiedPayload['resourceVerified'] === false, 'unverified payload must say resourceVerified=false');
    paymentStatusCheck(!array_key_exists('resource', $unverifiedPayload), 'unverified payload must not confirm a resource type/id');
    paymentStatusCheck(!array_key_exists('status', $unverifiedPayload), 'unverified payload must never expose the submission-specific status');
    paymentStatusCheck($unverifiedPayload['feeEnabled'] === true, 'unverified payload must still expose public fee facts — they are journal-level, not submission-specific');
    paymentStatusCheck($unverifiedPayload['amount'] === 50.0, 'unverified payload must still expose the public fee amount');

    // ================================================================
    // Part 2: catalog contract — this capability requires an additional
    // journal policy beyond feature/relationship/assurance, and that
    // policy must default to false (no admin toggle exists yet).
    // ================================================================
    $catalogDefinition = CapabilityCatalog::definition('submission.read_own_payment_status');
    paymentStatusCheck(($catalogDefinition['feature'] ?? null) === 'payment_status', 'payment capability must be gated on the payment_status feature flag');
    paymentStatusCheck(($catalogDefinition['policy'] ?? null) === 'payment_support', 'payment capability must be gated on the payment_support journal policy');
    paymentStatusCheck(($catalogDefinition['policyDefault'] ?? true) === false, 'payment_support must default to false — no admin toggle exists yet to opt a journal in');
    paymentStatusCheck(($catalogDefinition['relationships'] ?? []) === ['author'], 'payment capability must only ever be available to authors, never reviewers');

    // ================================================================
    // Part 3: end-to-end through the bridge + real adapter, proving the
    // feature flag is derived live from OJS's own payment configuration.
    // ================================================================
    \PKP\user\Repo::$usersById[42] = new FakeOjsUser(42, [65538]);

    final class InMemorySupportSessionRepositoryForPayment implements SupportSessionRepositoryInterface
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
    $repo = new InMemorySupportSessionRepositoryForPayment();
    $service = new SupportSessionService($repo, static fn (): int => $now);
    $bootstrap = $service->bootstrapAuthenticated(new SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en'));
    $bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
    paymentStatusCheck($bound !== null, 'test fixture: authenticated bootstrap should bind');

    $enabledContext = new FakeContext(paymentsEnabled: true, publicationFee: 75.5, currency: 'GBP');
    $bridge = new RuntimeContextBridge();
    $baseContext = $bridge->resolve(new FakeRequest($enabledContext), 'en');
    paymentStatusCheck($baseContext !== null, 'kernel should initialize for supported OJS version');

    $reflectionKernel = new \ReflectionProperty($bridge, 'kernel');
    $kernel = $reflectionKernel->getValue($bridge);
    $reflectionService = new \ReflectionProperty($kernel, 'supportSessionService');
    $reflectionService->setValue($kernel, $service);

    // --- Fee enabled, live-read from OJS context via OJSPaymentManager ---
    $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext(new FakeRequest($enabledContext)));
    paymentStatusCheck($feeInfo['enabled'] === true, 'end-to-end: bridge must read the real fee-enabled state through OJSPaymentManager');
    paymentStatusCheck($feeInfo['amount'] === 75.5, 'end-to-end: bridge must read the real publicationFee amount');
    paymentStatusCheck($feeInfo['currency'] === 'GBP', 'end-to-end: bridge must read the real currency');

    // --- Fee disabled: amount/currency must be null even if set on context ---
    $disabledContext = new FakeContext(paymentsEnabled: false, publicationFee: 75.5, currency: 'GBP');
    $disabledFeeInfo = $bridge->getPaymentFeeInfo($disabledContext);
    paymentStatusCheck($disabledFeeInfo['enabled'] === false, 'end-to-end: paymentsEnabled=false must report the fee as disabled regardless of a set amount');
    paymentStatusCheck($disabledFeeInfo['amount'] === null, 'end-to-end: amount must be null when the fee is disabled, never a stale leftover value');
    paymentStatusCheck($disabledFeeInfo['currency'] === null, 'end-to-end: currency must be null when the fee is disabled');

    // --- Zero fee: publicationEnabled() must be false (fee > 0 required) ---
    $zeroFeeContext = new FakeContext(paymentsEnabled: true, publicationFee: 0.0, currency: 'GBP');
    $zeroFeeInfo = $bridge->getPaymentFeeInfo($zeroFeeContext);
    paymentStatusCheck($zeroFeeInfo['enabled'] === false, 'end-to-end: a zero-amount publicationFee must not report the fee as enabled');

    // --- hasPaidPublicationFee reads the real DAO ---
    \PKP\db\DAORegistry::$completedPublicationPayments['42:456'] = true;
    paymentStatusCheck($bridge->hasPaidPublicationFee(42, 456) === true, 'end-to-end: bridge must read a real completed payment through the compatibility adapter');
    paymentStatusCheck($bridge->hasPaidPublicationFee(42, 999) === false, 'end-to-end: an unrelated submission must not report as paid');
    paymentStatusCheck($bridge->hasPaidPublicationFee(0, 456) === false, 'end-to-end: an invalid userId must fail closed, not throw');

    // --- Full resolver path: capability denied by default (payment_support policy is off) ---
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer service-secret';
    $apiResolver = new SupportApiRequestResolver($bridge);
    $authorApiResult = $apiResolver->resolve(new FakeRequest($enabledContext), 'corr-1', 7, 'service-secret', '1', '100', '500', 'paymentStatus');
    paymentStatusCheck(!($authorApiResult instanceof SupportApiFailure) && $authorApiResult->verified(), 'the resolver must verify the V2 conversation identity for paymentStatus');

    $submissionForRequest = $bridge->loadSubmission(456);
    $relationshipForRequest = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $submissionForRequest);
    paymentStatusCheck($relationshipForRequest !== null && $relationshipForRequest->has('author'), 'end-to-end: author must resolve a relationship for their own submission');

    $deniedByPolicyDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest,
        ['payment_status' => true] // feature enabled, but payment_support policy still defaults false
    ));
    paymentStatusCheck(
        !$deniedByPolicyDecision->allows('submission.read_own_payment_status'),
        'even with the feature flag enabled and a real author relationship at v3, the payment_support policy default must still deny — no admin toggle exists yet'
    );

    // --- Sanity check: explicitly granting the journal policy does unlock it (proves the denial above is the policy gate, not a broken test) ---
    $grantedDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $authorApiResult->identity(),
        $relationshipForRequest,
        ['payment_status' => true],
        ['payment_support' => true]
    ));
    paymentStatusCheck(
        $grantedDecision->allows('submission.read_own_payment_status'),
        'sanity check: explicitly granting the payment_support policy must unlock the capability, proving the default-false denial above is real'
    );

    // --- Reviewer relationship must never unlock this capability, even with policy granted ---
    \APP\facades\Repo::$workflowStagesByUserId['43:456'] = [];
    \APP\facades\Repo::$reviewAssignments['456:43'] = true;
    $reviewerIdentity = new SupportContext(7, 'journal-a', 43, [65536], 'index', 'index', 'en');
    $reviewerRelationship = $resolver->resolve($reviewerIdentity, \APP\facades\Repo::$submissionsById[456]);
    paymentStatusCheck($reviewerRelationship !== null && $reviewerRelationship->has('reviewer'), 'test fixture: reviewer must resolve a relationship to their assignment');
    $reviewerDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $reviewerIdentity,
        $reviewerRelationship,
        ['payment_status' => true],
        ['payment_support' => true]
    ));
    paymentStatusCheck(
        !$reviewerDecision->allows('submission.read_own_payment_status'),
        'a reviewer relationship must never unlock submission.read_own_payment_status, even with every other gate open'
    );

    // --- V0-V2 cannot reach the V3 path even with every other gate open ---
    foreach (['v0', 'v1', 'v2'] as $lowAssurance) {
        $lowAssuranceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $lowAssurance,
            $authorApiResult->identity(),
            $relationshipForRequest,
            ['payment_status' => true],
            ['payment_support' => true]
        ));
        paymentStatusCheck(
            !$lowAssuranceDecision->allows('submission.read_own_payment_status'),
            "assurance {$lowAssurance} must not unlock submission.read_own_payment_status even with every other gate open"
        );
    }

    // --- Guessed submission ID: exists, but this user has no relationship to it ---
    \APP\facades\Repo::$submissionsById[777] = new FakeSubmission(777, 7);
    $guessedSubmission = $bridge->loadSubmission(777);
    $guessedRelationship = $bridge->resolveSubmissionRelationship($authorApiResult->identity(), $guessedSubmission);
    paymentStatusCheck(
        $guessedRelationship !== null && $guessedRelationship->isEmpty(),
        'a guessed submission ID belonging to someone else must resolve to an empty relationship, not an error'
    );

    // --- Expired/unbound support session: resolver itself must return unverified ---
    $unknownConversation = $apiResolver->resolve(new FakeRequest($enabledContext), 'corr-2', 7, 'service-secret', '1', '100', '999', 'paymentStatus');
    paymentStatusCheck(
        !($unknownConversation instanceof SupportApiFailure) && $unknownConversation->verified() === false,
        'an unbound/expired conversation must resolve as unverified before any submission is even loaded'
    );

    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTPS']);

    // ================================================================
    // Part 4: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    paymentStatusCheck(str_contains($pluginSource, 'function supportPaymentStatusRequest'), 'plugin must implement the payment-status endpoint');
    paymentStatusCheck(str_contains($pluginSource, 'PaymentStatusSerializer'), 'endpoint must use the dedicated allowlist serializer');
    paymentStatusCheck(str_contains($pluginSource, 'submission.read_own_payment_status'), 'endpoint must gate on submission.read_own_payment_status');
    paymentStatusCheck(str_contains($pluginSource, "'payment_status' => \$feeInfo['enabled']"), 'endpoint must derive the payment_status feature flag live from OJS payment config, not a static true');

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    paymentStatusCheck(str_contains($handlerSource, 'function paymentStatus('), 'handler must register the paymentStatus operation');

    $serializerSource = (string) file_get_contents($root . '/classes/v2/Api/PaymentStatusSerializer.php');
    paymentStatusCheck(!str_contains($serializerSource, '->evidence()'), 'serializer must never read ResourceRelationship::evidence()');

    fwrite(STDOUT, "Payment status tests passed\n");
}
