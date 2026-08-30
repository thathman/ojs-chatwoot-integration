<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

interface OjsCompatibilityAdapterInterface
{
    public function versionFamily(): string;
    public function supportsVersion(string $version): bool;
    public function getContext($request);
    public function getUser($request);
    public function getRoleIds($user, int $contextId): array;
    public function getUserById(int $userId);
    public function getSubmissionById(int $submissionId);

    /**
     * Returns up to $candidateCap submissions in $contextId that the OJS user
     * has ANY stage assignment or review assignment for (author, reviewer,
     * or editorial alike) — a broad, safe discovery net. Callers MUST NOT
     * treat membership in this list as authorization; every result must
     * still pass the resource relationship resolver, which is the only
     * authority for whether a submission is actually author/reviewer-related.
     *
     * @return array<int,mixed>
     */
    public function listCandidateSubmissions(int $contextId, int $userId, int $candidateCap): array;

    /** Never throws; returns '' if a title genuinely cannot be determined. */
    public function getSubmissionTitle($submission): string;

    /** @return array{status:?int,stageId:?int,reviewRoundStatus:?int,submissionProgress:?string} */
    public function getSubmissionStateFields($submission): array;
    public function getRequestedPage($request): string;
    public function getRequestedOperation($request): string;
}
