<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Relationship;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;

/**
 * POL-011 / CWO-016: replaces role-wide reviewer masking with
 * resource/relationship-aware masking.
 *
 * The legacy widget-injection masking checked only whether the current
 * user held the journal-level Reviewer role anywhere in the journal — so
 * an author on Submission A who also happens to review Submission B was
 * masked everywhere in the journal, including on pages about their own
 * submission. This policy only relaxes masking when a real, resource-scoped
 * relationship (author/editorial/manager/site_admin — evaluated via the
 * same live OJS workflow-stage/review-assignment evidence
 * SubmissionRelationshipResolver already uses elsewhere) proves the current
 * page is about a resource the viewer is NOT reviewing.
 *
 * The direction is deliberately one-way: this policy can only ever ADD
 * confidence that unmasking is safe, never remove it. Whenever the current
 * resource cannot be determined, or resolution itself fails (including the
 * cross-journal case SubmissionRelationshipResolver already fails closed
 * on), this falls back to the original conservative journal-wide behavior
 * — mask whenever the Reviewer role exists anywhere in the journal. A
 * viewer who is not a journal-wide reviewer at all is never masked,
 * regardless of resource context.
 */
final class ReviewerMaskingPolicy
{
    public function __construct(private SubmissionRelationshipResolver $relationshipResolver)
    {
    }

    /**
     * @param bool $hasJournalWideReviewerRole whether the viewer holds
     *   Role::ROLE_ID_REVIEWER anywhere in this journal (the legacy, sole
     *   signal); real object|null $currentSubmission the resource the
     *   current page appears to be about, or null when it can't be
     *   determined (dashboard, generic frontend pages, etc.)
     */
    public function shouldMask(SupportContext $context, bool $hasJournalWideReviewerRole, $currentSubmission): bool
    {
        if (!$hasJournalWideReviewerRole) {
            // Never mask someone who isn't a reviewer anywhere in the journal.
            return false;
        }

        if ($currentSubmission === null) {
            // No resource context to evaluate against — fail closed to the
            // original, conservative journal-wide behavior.
            return true;
        }

        $relationship = $this->relationshipResolver->resolve($context, $currentSubmission);
        if ($relationship === null) {
            // Resolution failed (unauthenticated, cross-journal resource,
            // malformed submission, etc.) — fail closed.
            return true;
        }

        if ($relationship->has('author') || $relationship->has('editorial') || $relationship->has('manager') || $relationship->has('site_admin')) {
            // A proven, resource-scoped non-reviewer relationship to THIS
            // submission — safe to unmask here even though the viewer holds
            // the Reviewer role elsewhere in the journal.
            return false;
        }

        // Either a proven reviewer relationship to this exact submission, or
        // no proven relationship to it at all — both stay masked.
        return true;
    }
}
