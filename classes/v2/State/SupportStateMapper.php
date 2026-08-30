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
 * Deliberately dropped from this slice (would require deeper evidence than
 * status/stageId alone can prove):
 * - "draft" (an incomplete, still-in-wizard submission) — OJS 3.5 tracks
 *   this via `submissionProgress`, not verified/used here yet.
 * - "revision_requested" / "revision_received" — requires the current
 *   review round's decision, not merely which stage the submission sits in.
 * Both return "review_in_progress"/"submitted"/"unknown" today rather than
 * a fabricated guess; a future State Engine slice should add real support
 * for them without changing this class's fail-safe contract.
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

    public static function map(?int $status, ?int $stageId): string
    {
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
                self::WORKFLOW_STAGE_ID_INTERNAL_REVIEW, self::WORKFLOW_STAGE_ID_EXTERNAL_REVIEW => 'review_in_progress',
                self::WORKFLOW_STAGE_ID_EDITING => 'copyediting_in_progress',
                self::WORKFLOW_STAGE_ID_PRODUCTION => 'production_in_progress',
                default => 'unknown',
            };
        }

        return 'unknown';
    }
}
