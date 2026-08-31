<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Runtime;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityDecision;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\SupportGatewayKernel;

final class RuntimeContextBridge
{
    private OjsVersionResolver $versionResolver;
    private string $resolvedVersion = '';
    private ?SupportGatewayKernel $kernel = null;

    public function __construct(?OjsVersionResolver $versionResolver = null)
    {
        $this->versionResolver = $versionResolver ?? new OjsVersionResolver();
    }

    public function resolve($request, string $locale = ''): ?SupportContext
    {
        $version = $this->versionResolver->resolve();
        if ($version === '') {
            return null;
        }

        if ($version !== $this->resolvedVersion) {
            $this->resolvedVersion = $version;
            $this->kernel = SupportGatewayKernel::forOjsVersion($version);
        }
        if (!$this->kernel) {
            return null;
        }

        try {
            return $this->kernel->resolveContext($request, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bootstrapAuthenticatedSupportSession(SupportContext $context): ?SupportSessionBootstrap
    {
        if (!$this->kernel || !$context->isAuthenticated()) {
            return null;
        }
        try {
            return $this->kernel->bootstrapAuthenticatedSupportSession($context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bindAuthenticatedSupportSession(
        string $bindingToken,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        if (!$this->kernel || $contextId <= 0 || $userId <= 0) {
            return null;
        }

        try {
            return $this->kernel->bindAuthenticatedSupportSession(
                $bindingToken,
                $contextId,
                $userId,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolveContextForUser($request, int $userId, string $locale = ''): ?SupportContext
    {
        if (!$this->kernel || $userId <= 0) {
            return null;
        }
        try {
            return $this->kernel->resolveContextForUser($request, $userId, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolveBoundSupportSession(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        if (!$this->kernel || $contextId <= 0) {
            return null;
        }
        try {
            return $this->kernel->resolveBoundSupportSession(
                $contextId,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function evaluateCapabilities(CapabilityRequest $request): ?CapabilityDecision
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->evaluateCapabilities($request);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return string[] */
    public function availableActions(CapabilityDecision $decision): array
    {
        if (!$this->kernel) {
            return [];
        }
        try {
            return $this->kernel->availableActions($decision);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array{action:string,reason:string}> */
    public function disabledActions(CapabilityDecision $decision): array
    {
        if (!$this->kernel) {
            return [];
        }
        try {
            return $this->kernel->disabledActions($decision);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Loads a submission server-side by ID only. The ID is merely a request
     * parameter — it is never proof of access. Callers must still resolve a
     * resource relationship (resolveSubmissionRelationship()) before
     * treating any part of the result as authorization.
     */
    public function loadSubmission(int $submissionId)
    {
        if (!$this->kernel || $submissionId <= 0) {
            return null;
        }
        try {
            return $this->kernel->loadSubmission($submissionId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolveSubmissionRelationship(SupportContext $context, $submission): ?ResourceRelationship
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->resolveSubmissionRelationship($context, $submission);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Broad, unauthorized candidate discovery only — see
     * OjsCompatibilityAdapterInterface::listCandidateSubmissions().
     *
     * @return array<int,mixed>
     */
    public function listCandidateSubmissions(int $contextId, int $userId, int $candidateCap): array
    {
        if (!$this->kernel || $contextId <= 0 || $userId <= 0 || $candidateCap <= 0) {
            return [];
        }
        try {
            return $this->kernel->listCandidateSubmissions($contextId, $userId, $candidateCap);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getSubmissionTitle($submission): string
    {
        if (!$this->kernel) {
            return '';
        }
        try {
            return $this->kernel->getSubmissionTitle($submission);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** @return array{status:?int,stageId:?int} */
    public function getSubmissionStateFields($submission): array
    {
        $fallback = ['status' => null, 'stageId' => null, 'reviewRoundStatus' => null, 'submissionProgress' => null];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getSubmissionStateFields($submission);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** @return int[] */
    public function getReviewAssignmentStatuses(int $submissionId, int $userId): array
    {
        if (!$this->kernel) {
            return [];
        }
        try {
            return $this->kernel->getReviewAssignmentStatuses($submissionId, $userId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return string[] */
    public function getMissingRequiredSubmissionFileGenreNames($context, $submission): array
    {
        if (!$this->kernel) {
            return [];
        }
        try {
            return $this->kernel->getMissingRequiredSubmissionFileGenreNames($context, $submission);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array{uploadMaxFilesizeBytes:int,postMaxSizeBytes:int} */
    public function getUploadLimits(): array
    {
        $fallback = ['uploadMaxFilesizeBytes' => 0, 'postMaxSizeBytes' => 0];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getUploadLimits();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** @return array{driver:string,sandboxForced:bool,smtpHostConfigured:bool} */
    public function getMailTransportConfiguration(): array
    {
        $fallback = ['driver' => '', 'sandboxForced' => false, 'smtpHostConfigured' => false];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getMailTransportConfiguration();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** @return array{email:string,name:string,userId:int}|null */
    public function getPrimarySubmissionAuthor($submission): ?array
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getPrimarySubmissionAuthor($submission);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{doi:?string,issueId:?int} */
    public function getPublicationFields($submission): array
    {
        $fallback = ['doi' => null, 'issueId' => null];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getPublicationFields($submission);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /** @return array{volume:?int,number:?int,year:?int,published:bool}|null */
    public function getIssueInfo(int $issueId): ?array
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getIssueInfo($issueId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getPublicSubmissionUrl($request, $submission): ?string
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getPublicSubmissionUrl($request, $submission);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{enabled:bool,amount:?float,currency:?string} */
    public function getPaymentFeeInfo($context): array
    {
        $fallback = ['enabled' => false, 'amount' => null, 'currency' => null];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getPaymentFeeInfo($context);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    public function hasPaidPublicationFee(int $userId, int $submissionId): bool
    {
        if (!$this->kernel) {
            return false;
        }
        try {
            return $this->kernel->hasPaidPublicationFee($userId, $submissionId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getContext($request)
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getContext($request);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return array{disabled:?bool,dateValidated:?string} */
    public function getUserAccountFields(int $userId): array
    {
        $fallback = ['disabled' => null, 'dateValidated' => null];
        if (!$this->kernel) {
            return $fallback;
        }
        try {
            return $this->kernel->getUserAccountFields($userId);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    public function getUserByEmail(string $email): ?object
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getUserByEmail($email);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getVerificationLinkUrl($request, string $publicReference, string $token): ?string
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getVerificationLinkUrl($request, $publicReference, $token);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getAirixSubmissionFeeProvider($context): ?PaymentSupportProviderInterface
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->getAirixSubmissionFeeProvider($context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function compileKnowledge($context, $request, int $contextId, string $locale): ?KnowledgeCompilation
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->compileKnowledge($context, $request, $contextId, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function buildKnowledgeHealthReport($context, $request, int $contextId, string $locale): ?KnowledgeHealthReport
    {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->buildKnowledgeHealthReport($context, $request, $contextId, $locale);
        } catch (\Throwable $e) {
            return null;
        }
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
    ): ?\APP\plugins\generic\chatwootIntegration\classes\v2\Verification\PreparedChallenge {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->requestVerificationChallenge(
                $contextId,
                $userId,
                $purpose,
                $method,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId,
                $pepper
            );
        } catch (\Throwable $e) {
            return null;
        }
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
    ): \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome {
        $failed = \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome::failed(
            \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome::STATUS_NOT_FOUND
        );
        if (!$this->kernel) {
            return $failed;
        }
        try {
            return $this->kernel->confirmVerificationPin(
                $publicReference,
                $pin,
                $contextId,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId,
                $purpose,
                $pepper
            );
        } catch (\Throwable $e) {
            return $failed;
        }
    }

    public function confirmVerificationLinkToken(
        string $publicReference,
        string $token,
        int $contextId
    ): \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome {
        $failed = \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome::failed(
            \APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome::STATUS_NOT_FOUND
        );
        if (!$this->kernel) {
            return $failed;
        }
        try {
            return $this->kernel->confirmVerificationLinkToken($publicReference, $token, $contextId);
        } catch (\Throwable $e) {
            return $failed;
        }
    }

    public function establishSupportSessionFromExternalVerification(
        int $contextId,
        int $userId,
        string $method,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        if (!$this->kernel) {
            return null;
        }
        try {
            return $this->kernel->establishSupportSessionFromExternalVerification(
                $contextId,
                $userId,
                $method,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolvedVersion(): string
    {
        return $this->resolvedVersion;
    }
}
