<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS review-assignment status transition into a
 * normalized `submission.review_submitted` SupportEvent (docs/v2/TASKLIST.md
 * EVT-007) — the single most support-relevant review transition: a
 * reviewer has submitted their review (status moves to
 * `REVIEW_ASSIGNMENT_STATUS_RECEIVED`).
 *
 * v1 has no review event at all; this is a v2-native addition built
 * around a real, stable pkp-lib hook:
 * `PKP\submission\reviewAssignment\Repository::edit()`'s
 * `ReviewAssignment::edit` hook fires with `($newReviewAssignment,
 * $reviewAssignment, $params)` before the DB write, giving both the
 * about-to-be-persisted new state and the current (old) state — exactly
 * what a before/after status comparison needs.
 *
 * Never includes the reviewer's identity in the event — same blind-review
 * discipline as `OjsSubmissionRelationshipEvidenceProvider`
 * (docs/v2/TASKLIST.md POL-009/010): this event only ever describes the
 * submission-facing fact "a review came in," never who submitted it.
 *
 * `naturalKey` is the review assignment's own id (a real
 * `PKP\core\DataObject`, stable and unique) — a submission can have
 * several review assignments (multiple reviewers, multiple rounds), so
 * keying on the submission id alone would collapse them.
 */
final class ReviewSubmittedEventAdapter
{
    private const STATUS_RECEIVED = 7;

    public static function fromReviewAssignmentEdit($newReviewAssignment, $oldReviewAssignment, int $contextId, string $title): ?SupportEvent
    {
        if (!is_object($newReviewAssignment) || !method_exists($newReviewAssignment, 'getId') || !method_exists($newReviewAssignment, 'getStatus')) {
            return null;
        }
        if (!is_object($oldReviewAssignment) || !method_exists($oldReviewAssignment, 'getStatus')) {
            return null;
        }
        if (!method_exists($newReviewAssignment, 'getSubmissionId')) {
            return null;
        }

        $newStatus = (int) $newReviewAssignment->getStatus();
        $oldStatus = (int) $oldReviewAssignment->getStatus();
        if ($newStatus === $oldStatus || $newStatus !== self::STATUS_RECEIVED) {
            return null;
        }

        $reviewAssignmentId = (int) $newReviewAssignment->getId();
        $submissionId = (int) $newReviewAssignment->getSubmissionId();
        if ($reviewAssignmentId <= 0 || $submissionId <= 0 || $contextId <= 0) {
            return null;
        }

        return SupportEvent::create(
            SupportEventType::SUBMISSION_REVIEW_SUBMITTED,
            $contextId,
            'submission',
            $submissionId,
            (string) $reviewAssignmentId,
            [
                'title' => $title,
            ]
        );
    }
}
