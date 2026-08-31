<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS submission status transition into a normalized
 * `submission.accepted`/`submission.rejected` SupportEvent
 * (docs/v2/TASKLIST.md EVT-005), mirroring v1's
 * `ChatwootIntegrationPlugin::handleSubmissionStatusUpdated()` — only
 * `PKPSubmission::STATUS_DECLINED`/`STATUS_PUBLISHED` transitions map to a
 * real event; every other transition is not this plugin's concern.
 *
 * Excludes author identity — same delivery-vs-fact separation as the
 * other EVT-00x adapters.
 *
 * `naturalKey` is `"{oldStatus}:{newStatus}"`. Unlike a decision or
 * publication, an OJS submission status transition has no dedicated
 * unique id of its own to key on — this is a deliberate, documented
 * simplification: a genuine repeat of the identical transition (rare, but
 * possible after a resubmission cycle) would collide and be treated as a
 * replay rather than a second distinct event. Acceptable for this v0
 * migration slice; revisit with a real transition-log id if that ever
 * proves wrong in practice.
 */
final class SubmissionStatusChangedEventAdapter
{
    private const STATUS_DECLINED = 4;
    private const STATUS_PUBLISHED = 3;

    public static function fromStatusChange(
        $submission,
        int $oldStatus,
        int $newStatus,
        int $contextId,
        string $title
    ): ?SupportEvent {
        if (!is_object($submission) || !method_exists($submission, 'getId')) {
            return null;
        }

        $submissionId = (int) $submission->getId();
        if ($submissionId <= 0 || $contextId <= 0 || $oldStatus === $newStatus) {
            return null;
        }

        $type = match ($newStatus) {
            self::STATUS_DECLINED => SupportEventType::SUBMISSION_REJECTED,
            self::STATUS_PUBLISHED => SupportEventType::SUBMISSION_ACCEPTED,
            default => null,
        };
        if ($type === null) {
            return null;
        }

        return SupportEvent::create(
            $type,
            $contextId,
            'submission',
            $submissionId,
            "{$oldStatus}:{$newStatus}",
            [
                'title' => $title,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
            ]
        );
    }
}
