<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2;

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\CompatibilityAdapterFactory;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ContextResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\AvailableActionMapper;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityDecision;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityPolicyEngine;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\CoreJournalKnowledgeProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\DatabaseSupportSessionRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\DatabaseVerificationChallengeRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\PreparedChallenge;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationChallengeService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationSecretHasher;

final class SupportGatewayKernel
{
    private function __construct(
        private string $ojsVersion,
        private OjsCompatibilityAdapterInterface $adapter,
        private ContextResolver $contextResolver,
        private SubmissionRelationshipResolver $submissionRelationshipResolver,
        private CapabilityPolicyEngine $capabilityPolicyEngine,
        private AvailableActionMapper $availableActionMapper,
        private SupportSessionService $supportSessionService,
        private VerificationChallengeService $verificationChallengeService,
        private KnowledgeCompiler $knowledgeCompiler
    ) {
    }

    public static function forOjsVersion(string $ojsVersion): ?self
    {
        $adapter = CompatibilityAdapterFactory::forVersion($ojsVersion);
        if (!$adapter) return null;

        $knowledgeCompiler = new KnowledgeCompiler();
        $knowledgeCompiler->registerProvider(new CoreJournalKnowledgeProvider());

        return new self(
            $ojsVersion,
            $adapter,
            new ContextResolver($adapter),
            new SubmissionRelationshipResolver(new OjsSubmissionRelationshipEvidenceProvider()),
            new CapabilityPolicyEngine(),
            new AvailableActionMapper(),
            new SupportSessionService(new DatabaseSupportSessionRepository()),
            new VerificationChallengeService(new DatabaseVerificationChallengeRepository(), new VerificationSecretHasher()),
            $knowledgeCompiler
        );
    }

    public function ojsVersion(): string { return $this->ojsVersion; }
    public function resolveContext($request, string $locale = ''): ?SupportContext { return $this->contextResolver->resolve($request, $locale); }
    public function resolveContextForUser($request, int $userId, string $locale = ''): ?SupportContext { return $this->contextResolver->resolveForUser($request, $userId, $locale); }
    public function resolveSubmissionRelationship(SupportContext $context, $submission): ?ResourceRelationship { return $this->submissionRelationshipResolver->resolve($context, $submission); }
    public function loadSubmission(int $submissionId) { return $this->adapter->getSubmissionById($submissionId); }

    /** @return array<int,mixed> */
    public function listCandidateSubmissions(int $contextId, int $userId, int $candidateCap): array
    {
        return $this->adapter->listCandidateSubmissions($contextId, $userId, $candidateCap);
    }

    public function getSubmissionTitle($submission): string { return $this->adapter->getSubmissionTitle($submission); }

    /** @return array{status:?int,stageId:?int} */
    public function getSubmissionStateFields($submission): array { return $this->adapter->getSubmissionStateFields($submission); }
    public function getReviewAssignmentStatuses(int $submissionId, int $userId): array { return $this->adapter->getReviewAssignmentStatuses($submissionId, $userId); }
    public function getPublicationFields($submission): array { return $this->adapter->getPublicationFields($submission); }
    public function getIssueInfo(int $issueId): ?array { return $this->adapter->getIssueInfo($issueId); }
    public function getPublicSubmissionUrl($request, $submission): ?string { return $this->adapter->getPublicSubmissionUrl($request, $submission); }
    public function getPaymentFeeInfo($context): array { return $this->adapter->getPaymentFeeInfo($context); }
    public function hasPaidPublicationFee(int $userId, int $submissionId): bool { return $this->adapter->hasPaidPublicationFee($userId, $submissionId); }
    public function getContext($request) { return $this->adapter->getContext($request); }
    public function getUserAccountFields(int $userId): array { return $this->adapter->getUserAccountFields($userId); }
    public function getUserByEmail(string $email): ?object { return $this->adapter->getUserByEmail($email); }
    public function getVerificationLinkUrl($request, string $publicReference, string $token): ?string { return $this->adapter->getVerificationLinkUrl($request, $publicReference, $token); }
    public function getAirixSubmissionFeeProvider($context): ?PaymentSupportProviderInterface { return $this->adapter->getAirixSubmissionFeeProvider($context); }
    public function compileKnowledge($context, $request, int $contextId, string $locale): KnowledgeCompilation { return $this->knowledgeCompiler->compile($context, $request, $contextId, $locale); }
    public function evaluateCapabilities(CapabilityRequest $request): CapabilityDecision { return $this->capabilityPolicyEngine->evaluate($request); }

    public function availableActions(CapabilityDecision $decision): array { return $this->availableActionMapper->map($decision); }
    public function disabledActions(CapabilityDecision $decision): array { return $this->availableActionMapper->mapDenied($decision); }

    public function bootstrapAuthenticatedSupportSession(SupportContext $context): SupportSessionBootstrap
    {
        return $this->supportSessionService->bootstrapAuthenticated($context);
    }

    public function bindAuthenticatedSupportSession(
        string $bindingToken,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        return $this->supportSessionService->bindAuthenticatedBootstrap(
            $bindingToken,
            $contextId,
            $userId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId
        );
    }

    public function resolveBoundSupportSession(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        return $this->supportSessionService->resolveConversation(
            $contextId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId
        );
    }

    public function requestVerificationChallenge(
        int $contextId,
        int $userId,
        string $purpose,
        string $method,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $pepper
    ): ?PreparedChallenge {
        return $this->verificationChallengeService->requestChallenge(
            $contextId, $userId, $purpose, $method, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $pepper
        );
    }

    public function confirmVerificationPin(
        string $publicReference,
        string $pin,
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose,
        string $pepper
    ): ChallengeAttemptOutcome {
        return $this->verificationChallengeService->confirmPin(
            $publicReference, $pin, $contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $purpose, $pepper
        );
    }

    public function confirmVerificationLinkToken(string $publicReference, string $token, int $contextId): ChallengeAttemptOutcome
    {
        return $this->verificationChallengeService->confirmLinkToken($publicReference, $token, $contextId);
    }

    public function establishSupportSessionFromExternalVerification(
        int $contextId,
        int $userId,
        string $method,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): SupportSession {
        return $this->supportSessionService->establishFromExternalVerification(
            $contextId, $userId, $method, $chatwootAccountId, $chatwootContactId, $chatwootConversationId
        );
    }
}
