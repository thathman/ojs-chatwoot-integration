<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\State;

/**
 * First, deliberately narrow slice of the future Support State Engine
 * (docs/v2/PRODUCT_BIBLE.md §11). Maps only what is provable from a
 * submission's own `status` and `stageId` fields — no review-round,
 * decision, or revision-file evidence yet, since that requires the fuller
 * workflow interpreter this slice explicitly does not attempt.
 *
 * "unknown" is the correct answer whenever evidence is ambiguous — never
 * guess a specific state from incomplete data (see PRODUCT_BIBLE.md's
 * Support State Engine principle, echoed in this class's own contract).
 *
 * "revision_requested" is now supported for review-stage submissions,
 * driven by the current review round's own `status` column (maintained
 * live by ReviewRoundDAO — never recomputed here). Only the single
 * REVISIONS_REQUESTED round status maps to it; other round statuses
 * (resubmit-for-review, pending recommendations, etc.) still fall back to
 * "review_in_progress" rather than guessing a finer distinction.
 *
 * "draft" is now supported: verified against pkp-lib stable-3_5_0
 * (classes/submission/Repository.php `add()`/`submit()`, and the API's
 * PKPSubmissionController::add(), which creates the author's
 * StageAssignment immediately at submission creation — before the wizard
 * completes). A draft is therefore already reachable through the same
 * assignedTo()-based candidate discovery this endpoint uses; the earlier
 * assumption that it wasn't was wrong and is corrected here. The signal
 * is `submissionProgress`: OJS sets it to a non-empty wizard-step value on
 * creation and clears it to '' in `submit()` at the exact moment a
 * submission is genuinely complete — checked first, ahead of status/stageId,
 * since a submission mid-wizard already carries STATUS_QUEUED + stage
 * SUBMISSION, which would otherwise be indistinguishable from "submitted".
 *
 * Still deliberately dropped from this slice:
 * - "revision_received" — requires revision-file evidence this slice does
 *   not read.
 */
final class SupportStateMapper
{
    // Verified against pkp-lib stable-3_5_0 classes/submission/PKPSubmission.php
    private const STATUS_QUEUED = 1;
    private const STATUS_PUBLISHED = 3;
    private const STATUS_DECLINED = 4;
    private const STATUS_SCHEDULED = 5;

    // Verified against pkp-lib stable-3_5_0 classes/core/PKPApplication.php
    private const WORKFLOW_STAGE_ID_SUBMISSION = 1;
    private const WORKFLOW_STAGE_ID_INTERNAL_REVIEW = 2;
    private const WORKFLOW_STAGE_ID_EXTERNAL_REVIEW = 3;
    private const WORKFLOW_STAGE_ID_EDITING = 4;
    private const WORKFLOW_STAGE_ID_PRODUCTION = 5;

    // Verified against pkp-lib stable-3_5_0 classes/submission/reviewRound/ReviewRound.php
    private const REVIEW_ROUND_STATUS_REVISIONS_REQUESTED = 1;

    public static function map(?int $status, ?int $stageId, ?int $reviewRoundStatus = null, ?string $submissionProgress = null): string
    {
        if ($submissionProgress !== null && $submissionProgress !== '') {
            return 'draft';
        }

        if ($status === self::STATUS_DECLINED) {
            return 'declined';
        }
        if ($status === self::STATUS_PUBLISHED) {
            return 'published';
        }
        if ($status === self::STATUS_SCHEDULED) {
            return 'scheduled_for_publication';
        }

        if ($status === self::STATUS_QUEUED) {
            return match ($stageId) {
                self::WORKFLOW_STAGE_ID_SUBMISSION => 'submitted',
                self::WORKFLOW_STAGE_ID_INTERNAL_REVIEW, self::WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => $reviewRoundStatus === self::REVIEW_ROUND_STATUS_REVISIONS_REQUESTED
                    ? 'revision_requested'
                    : 'review_in_progress',
                self::WORKFLOW_STAGE_ID_EDITING => 'copyediting_in_progress',
                self::WORKFLOW_STAGE_ID_PRODUCTION => 'production_in_progress',
                default => 'unknown',
            };
        }

        return 'unknown';
    }
}
