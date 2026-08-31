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
    /** @return int[] */
    public function getReviewAssignmentStatuses(int $submissionId, int $userId): array;

    /** @return array{doi:?string,issueId:?int} */
    public function getPublicationFields($submission): array;

    /** @return array{volume:?int,number:?int,year:?int,published:bool}|null */
    public function getIssueInfo(int $issueId): ?array;

    public function getPublicSubmissionUrl($request, $submission): ?string;

    /** @return array{enabled:bool,amount:?float,currency:?string} */
    public function getPaymentFeeInfo($context): array;

    public function hasPaidPublicationFee(int $userId, int $submissionId): bool;

    /** @return array{disabled:?bool,dateValidated:?string} */
    public function getUserAccountFields(int $userId): array;

    /** The claimed email is a lookup key only, never identity. */
    public function getUserByEmail(string $email): ?object;

    public function getVerificationLinkUrl($request, string $publicReference, string $token): ?string;

    /** Null when the sibling plugin is absent, disabled, or an incompatible version. */
    public function getAirixSubmissionFeeProvider($context): ?PaymentSupportProviderInterface;

    /** Public policy facts only (enabled/amount/currency) — never a submission's obligation state. Null when absent/disabled/incompatible. */
    public function getAirixSubmissionFeePolicy($context): ?array;

    /** Journal-manager-authored public pages from OJS core's Static Pages plugin. Empty when absent/disabled. @return array<int,array{path:string,title:string,content:string}> */
    public function getOfficialPublicPages($context, string $locale): array;

    /** Public waiver *policy* text only (never a submission's waiver decision). Null when absent/disabled. @return array{enabled:bool,title:?string,body:?string}|null */
    public function getAirixRequestWaiverPolicy($context): ?array;

    /** Public passwordless sign-in availability/request URL only (never an email-existence check). Null when absent. @return array{enabled:bool,requestUrl:?string}|null */
    public function getAirixMagicLoginAvailability($context, $request): ?array;

    /** Configured required submission-file genre names only (never a specific submission's missing files). @return string[] */
    public function getAirixRequiredSubmissionFileGenres($context): array;

    /** DIA-006: localized names of required genres this specific submission has no uploaded file for yet. @return string[] */
    public function getMissingRequiredSubmissionFileGenreNames($context, $submission): array;

    /** DIA-007: PHP's real upload_max_filesize/post_max_size ini values in bytes — the only source of truth; OJS has no separate upload-limit setting. @return array{uploadMaxFilesizeBytes:int,postMaxSizeBytes:int} */
    public function getUploadLimits(): array;

    public function getRequestedPage($request): string;
    public function getRequestedOperation($request): string;
}
