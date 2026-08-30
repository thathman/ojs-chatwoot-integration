<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\State;

/**
 * Deliberately narrow slice of the required-actions surface
 * (ojs_get_required_actions, docs/v2/API_MCP_SPEC.md §7.6). Only reports an
 * action when it is directly provable from evidence this codebase already
 * reads — never infers one from a state/status combination it hasn't
 * verified. An empty array is the correct, safe answer whenever nothing is
 * provably required, not a sign this mapper is incomplete.
 */
final class RequiredActionMapper
{
    /**
     * Author actions are derived from the same SupportStateMapper vocabulary
     * this endpoint's sibling (ojs_get_submission_support) already computes
     * — only the two states where an author-side action is directly provable
     * from existing evidence. Every other state (submitted, review_in_progress,
     * copyediting_in_progress, production_in_progress, published, declined,
     * scheduled_for_publication, unknown) has no provable author action from
     * status/stageId alone yet — returning [] rather than guessing.
     */
    public static function forAuthor(string $supportState): array
    {
        return match ($supportState) {
            'draft' => ['complete_submission'],
            'revision_requested' => ['submit_revisions'],
            default => [],
        };
    }

    // Verified against pkp-lib stable-3_5_0 classes/submission/reviewAssignment/ReviewAssignment.php getStatus().
    private const STATUS_AWAITING_RESPONSE = 0;
    private const STATUS_DECLINED = 1;
    private const STATUS_RESPONSE_OVERDUE = 4;
    private const STATUS_ACCEPTED = 5;
    private const STATUS_REVIEW_OVERDUE = 6;
    private const STATUS_RECEIVED = 7;
    private const STATUS_COMPLETE = 8;
    private const STATUS_THANKED = 9;
    private const STATUS_CANCELLED = 10;
    private const STATUS_REQUEST_RESEND = 11;
    private const STATUS_VIEWED = 12;

    /**
     * Reads each of this reviewer's own ReviewAssignment statuses (there may
     * be more than one across review rounds) via PKP's own getStatus()
     * computation — never re-derives its overdue-date/decline/resend logic.
     * If multiple assignments disagree, the most urgent outstanding action
     * wins (respond > submit > none) rather than averaging or guessing.
     *
     * @param int[] $reviewAssignmentStatuses
     */
    public static function forReviewer(array $reviewAssignmentStatuses): array
    {
        $needsResponse = false;
        $needsReview = false;

        foreach ($reviewAssignmentStatuses as $status) {
            if (in_array($status, [self::STATUS_AWAITING_RESPONSE, self::STATUS_RESPONSE_OVERDUE, self::STATUS_REQUEST_RESEND], true)) {
                $needsResponse = true;
            }
            if (in_array($status, [self::STATUS_ACCEPTED, self::STATUS_REVIEW_OVERDUE], true)) {
                $needsReview = true;
            }
            // STATUS_RECEIVED, STATUS_COMPLETE, STATUS_THANKED, STATUS_VIEWED,
            // STATUS_DECLINED, STATUS_CANCELLED: this assignment is settled,
            // no reviewer action outstanding for it.
        }

        if ($needsResponse) {
            return ['respond_to_review_invitation'];
        }
        if ($needsReview) {
            return ['submit_review'];
        }
        return [];
    }
}
