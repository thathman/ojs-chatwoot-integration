<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Runtime;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
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
        if ($version === '') return null;

        if ($version !== $this->resolvedVersion) {
            $this->resolvedVersion = $version;
            $this->kernel = SupportGatewayKernel::forOjsVersion($version);
        }
        if (!$this->kernel) return null;

        try {
            return $this->kernel->resolveContext($request, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bootstrapAuthenticatedSupportSession(SupportContext $context): ?SupportSessionBootstrap
    {
        if (!$this->kernel || !$context->isAuthenticated()) return null;
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
        if (!$this->kernel || $contextId <= 0 || $userId <= 0) return null;

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
        if (!$this->kernel || $userId <= 0) return null;
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
        if (!$this->kernel || $contextId <= 0) return null;
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
        if (!$this->kernel) return null;
        try {
            return $this->kernel->evaluateCapabilities($request);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return string[] */
    public function availableActions(CapabilityDecision $decision): array
    {
        if (!$this->kernel) return [];
        try {
            return $this->kernel->availableActions($decision);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array{action:string,reason:string}> */
    public function disabledActions(CapabilityDecision $decision): array
    {
        if (!$this->kernel) return [];
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
        if (!$this->kernel || $submissionId <= 0) return null;
        try {
            return $this->kernel->loadSubmission($submissionId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolveSubmissionRelationship(SupportContext $context, $submission): ?ResourceRelationship
    {
        if (!$this->kernel) return null;
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
        if (!$this->kernel || $contextId <= 0 || $userId <= 0 || $candidateCap <= 0) return [];
        try {
            return $this->kernel->listCandidateSubmissions($contextId, $userId, $candidateCap);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getSubmissionTitle($submission): string
    {
        if (!$this->kernel) return '';
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
        if (!$this->kernel) return $fallback;
        try {
            return $this->kernel->getSubmissionStateFields($submission);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    public function resolvedVersion(): string { return $this->resolvedVersion; }
}
