<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics;

/**
 * ojs_diagnose_submission (docs/v2/API_MCP_SPEC.md §7.10). Deliberately
 * does not create a second workflow interpreter — every scope here is a
 * thin wrapper over the existing domain services (SubmissionRelationshipResolver,
 * SupportStateMapper, RequiredActionMapper, publication/payment fields),
 * called by the endpoint with evidence it already had to gather anyway.
 * This engine only decides what DiagnosticResult that evidence produces.
 */
final class SubmissionDiagnosticEngine
{
    public const SCOPE_SUBMISSION_ACCESS = 'submission_access';
    public const SCOPE_SUBMISSION_PROGRESS = 'submission_progress';
    public const SCOPE_REQUIRED_ACTION = 'required_action';
    public const SCOPE_REVIEW_ACCESS = 'review_access';
    public const SCOPE_PUBLICATION = 'publication';
    public const SCOPE_PAYMENT = 'payment';
    public const SCOPE_REQUIRED_FILES = 'required_files';

    public const SCOPES = [
        self::SCOPE_SUBMISSION_ACCESS,
        self::SCOPE_SUBMISSION_PROGRESS,
        self::SCOPE_REQUIRED_ACTION,
        self::SCOPE_REVIEW_ACCESS,
        self::SCOPE_PUBLICATION,
        self::SCOPE_PAYMENT,
        self::SCOPE_REQUIRED_FILES,
    ];

    /**
     * Only ever invoked after the endpoint has already established a real,
     * verified relationship (see supportSubmissionDiagnosticsRequest) — so
     * this always confirms the access that was just proven, never guesses.
     *
     * @param string[] $relationshipTypes
     */
    public static function diagnoseSubmissionAccess(array $relationshipTypes): DiagnosticResult
    {
        if ($relationshipTypes === []) {
            return DiagnosticResult::unknown('NO_RELATIONSHIP_EVIDENCE', 'No author or reviewer relationship evidence was found for this submission.');
        }
        sort($relationshipTypes);
        $evidenceCodes = array_map(static fn (string $type): string => 'RELATIONSHIP_' . strtoupper($type), $relationshipTypes);
        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'SUBMISSION_ACCESS_CONFIRMED',
            'Access to this submission is confirmed via: ' . implode(', ', $relationshipTypes) . '.',
            $evidenceCodes
        );
    }

    /**
     * Wraps the same SupportStateMapper state ojs_get_submission_support
     * uses, mapped to a targeted diagnostic code rather than just echoing
     * the state name — e.g. revision_requested becomes the actionable
     * REVISION_REQUIRED rather than a generic "here is your state".
     */
    public static function diagnoseSubmissionProgress(string $supportState): DiagnosticResult
    {
        $evidenceCode = 'SUPPORT_STATE_' . strtoupper($supportState);

        return match ($supportState) {
            'draft' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'SUBMISSION_INCOMPLETE', 'This submission has not been completed yet.', [$evidenceCode], ['complete_submission']),
            'submitted' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'SUBMISSION_SUBMITTED', 'This submission has been received and is awaiting an editorial decision.', [$evidenceCode]),
            'review_in_progress' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'REVIEW_IN_PROGRESS', 'This submission is currently under review.', [$evidenceCode]),
            'revision_requested' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'REVISION_REQUIRED', 'A revision is currently required for this submission.', [$evidenceCode], ['submit_revisions']),
            'copyediting_in_progress' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'COPYEDITING_IN_PROGRESS', 'This submission is in copyediting.', [$evidenceCode]),
            'production_in_progress' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PRODUCTION_IN_PROGRESS', 'This submission is in production.', [$evidenceCode]),
            'published' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PUBLISHED', 'This submission has been published.', [$evidenceCode]),
            'declined' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'DECLINED', 'A decision has been made not to proceed with this submission.', [$evidenceCode]),
            'scheduled_for_publication' => new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'SCHEDULED_FOR_PUBLICATION', 'This submission has been scheduled for an upcoming publication.', [$evidenceCode]),
            default => DiagnosticResult::unknown('SUBMISSION_PROGRESS_UNKNOWN', "This submission's current progress could not be determined safely.", [$evidenceCode]),
        };
    }

    /** @param string[] $requiredActions */
    public static function diagnoseRequiredAction(array $requiredActions): DiagnosticResult
    {
        if ($requiredActions === []) {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'NO_ACTION_REQUIRED', 'No action is currently required from you for this submission.', ['REQUIRED_ACTIONS_EMPTY']);
        }
        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'ACTION_REQUIRED',
            'At least one action is currently required from you for this submission.',
            ['REQUIRED_ACTIONS_NON_EMPTY'],
            $requiredActions
        );
    }

    /**
     * DIA-006: distinct from `diagnoseRequiredAction()` — this checks
     * specifically for missing required-genre uploads (Airix
     * `RequiredSubmissionFilesPlugin`), not the generic pending-actions
     * list. `$missingGenreNames` comes from
     * `Ojs35CompatibilityAdapter::getMissingRequiredSubmissionFileGenreNames()`,
     * which is empty both when the feature is disabled/absent AND when
     * every required file is present — deterministic by construction, so
     * this never needs to guess which case applies.
     *
     * @param string[] $missingGenreNames
     */
    public static function diagnoseRequiredFiles(array $missingGenreNames): DiagnosticResult
    {
        if ($missingGenreNames === []) {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'REQUIRED_FILES_COMPLETE',
                'All required submission files have been uploaded (or none are configured for this journal).',
                ['REQUIRED_FILE_GENRES_SATISFIED']
            );
        }

        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'REQUIRED_FILES_MISSING',
            'At least one required submission file is still missing: ' . implode(', ', $missingGenreNames) . '.',
            ['REQUIRED_FILE_GENRES_MISSING'],
            ['upload_required_files']
        );
    }

    /**
     * Only meaningful for a reviewer relationship — an author with no
     * reviewer relationship genuinely has no reviewer evidence to report,
     * which is a different fact than "no action needed" and must not be
     * conflated with it.
     *
     * @param int[] $reviewAssignmentStatuses
     */
    public static function diagnoseReviewAccess(bool $hasReviewerRelationship, array $reviewAssignmentStatuses): DiagnosticResult
    {
        if (!$hasReviewerRelationship) {
            return DiagnosticResult::unknown('NOT_A_REVIEWER', 'This identity has no reviewer relationship to this submission.');
        }
        if ($reviewAssignmentStatuses === []) {
            // Should not normally happen if hasReviewerRelationship is true
            // (the resolver only grants that relationship when a real
            // assignment exists) — handled defensively, not asserted away.
            return DiagnosticResult::unknown('REVIEWER_ASSIGNMENT_EVIDENCE_MISSING', 'A reviewer relationship was found, but no assignment evidence could be read.');
        }
        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'REVIEWER_ASSIGNMENT_FOUND',
            'A review assignment for this submission was found for you.',
            ['REVIEW_ASSIGNMENT_STATUSES_PRESENT']
        );
    }

    /** Reuses the exact same 3-way split ojs_get_publication_status uses. */
    public static function diagnosePublication(string $supportState): DiagnosticResult
    {
        $evidenceCode = 'SUPPORT_STATE_' . strtoupper($supportState);
        if ($supportState === 'published') {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PUBLICATION_PUBLISHED', 'This submission has been published.', [$evidenceCode]);
        }
        if ($supportState === 'scheduled_for_publication') {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PUBLICATION_SCHEDULED', 'This submission has been scheduled for an upcoming publication.', [$evidenceCode]);
        }
        return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PUBLICATION_NOT_YET_PUBLISHED', 'This submission has not been published yet.', [$evidenceCode]);
    }

    /**
     * Must never reveal more than ojs_get_payment_status itself would in
     * the current configuration — if the same submission.read_own_payment_status
     * capability the dedicated endpoint enforces is denied (e.g. because
     * the payment_support journal policy defaults off), this scope reports
     * the same absence of specific status, not a workaround.
     */
    public static function diagnosePayment(bool $paymentCapabilityAllowed, bool $feeEnabled, ?bool $paid): DiagnosticResult
    {
        if (!$paymentCapabilityAllowed) {
            return DiagnosticResult::unknown('PAYMENT_STATUS_UNAVAILABLE', 'Personalized payment status is not available for this submission through this channel yet.', ['PAYMENT_CAPABILITY_DENIED']);
        }
        if (!$feeEnabled) {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PAYMENT_NOT_APPLICABLE', 'No payment is required for this submission.', ['FEE_NOT_ENABLED']);
        }
        if ($paid === true) {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PAYMENT_PAID', 'The required payment for this submission has been completed.', ['COMPLETED_PAYMENT_FOUND']);
        }
        if ($paid === false) {
            return new DiagnosticResult(DiagnosticResult::STATUS_CONFIRMED, 'PAYMENT_UNPAID', 'The required payment for this submission has not been completed yet.', ['COMPLETED_PAYMENT_NOT_FOUND']);
        }
        return DiagnosticResult::unknown('PAYMENT_STATUS_UNKNOWN', "This submission's payment completion could not be determined.");
    }
}
