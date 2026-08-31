<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * EVT-011: builds the human-readable message for a queued event at
 * delivery time, reusing v1's exact real locale strings
 * (`locale/en/locale.po`'s `plugins.generic.chatwootIntegration.note.*`
 * keys) rather than inventing new copy.
 *
 * `SUBMISSION_ACCEPTED`/`SUBMISSION_REJECTED` are produced by two
 * different real v1 hooks with two different real v1 messages —
 * `DecisionRecordedEventAdapter` (mirrors `handleEditorDecision()`,
 * v1's `note.editorDecision`) and `SubmissionStatusChangedEventAdapter`
 * (mirrors `handleSubmissionStatusUpdated()`, v1's `note.statusChanged`).
 * The queued event's own `attributes` disambiguate which adapter produced
 * it: only the decision adapter's payload carries `decisionCode`; only
 * the status adapter's carries `newStatus`.
 *
 * `__()` is PKP's global translation function — unavailable outside a
 * real OJS runtime, so this degrades to a plain, still-informative
 * English fallback when it doesn't exist (this plain-PHP test
 * environment, or a genuinely broken locale registry) rather than
 * throwing.
 */
final class SupportEventMessageBuilder
{
    public static function build(SupportEvent $event): string
    {
        return self::buildFromFields($event->type(), $event->resourceId(), $event->attributes());
    }

    /**
     * Same as `build()`, but from primitive fields — used at delivery
     * time, where the queue only ever hands back a plain DB row (type +
     * resourceId + a decoded attributes array), not a rehydrated
     * `SupportEvent` object.
     *
     * @param array<string,mixed> $attributes
     */
    public static function buildFromFields(string $type, int $resourceId, array $attributes): string
    {
        $submissionId = $resourceId;
        $title = (string) ($attributes['title'] ?? '');

        if (array_key_exists('newStatus', $attributes)) {
            return self::translate(
                'plugins.generic.chatwootIntegration.note.statusChanged',
                ['submissionId' => $submissionId, 'status' => (int) $attributes['newStatus']],
                "OJS Notification: Submission #{$submissionId} status changed."
            );
        }

        return match ($type) {
            SupportEventType::SUBMISSION_CREATED => self::translate(
                'plugins.generic.chatwootIntegration.note.submissionCreated',
                ['submissionId' => $submissionId, 'title' => $title],
                "OJS Notification: Submission #{$submissionId} ({$title}) was created."
            ),
            SupportEventType::SUBMISSION_DECISION_RECORDED,
            SupportEventType::SUBMISSION_REVISION_REQUESTED,
            SupportEventType::SUBMISSION_ACCEPTED,
            SupportEventType::SUBMISSION_REJECTED => self::translate(
                'plugins.generic.chatwootIntegration.note.editorDecision',
                ['submissionId' => $submissionId, 'title' => $title, 'decisionCode' => (int) ($attributes['decisionCode'] ?? 0)],
                "OJS Notification: Editor decision recorded for submission #{$submissionId} ({$title})."
            ),
            SupportEventType::PUBLICATION_SCHEDULED,
            SupportEventType::PUBLICATION_PUBLISHED => self::translate(
                'plugins.generic.chatwootIntegration.note.publicationEvent',
                ['submissionId' => $submissionId, 'status' => (int) ($attributes['publicationStatus'] ?? 0)],
                "OJS Notification: Submission #{$submissionId} publication status changed."
            ),
            SupportEventType::SUBMISSION_REVIEW_SUBMITTED => "OJS Notification: A review was submitted for submission #{$submissionId}.",
            default => "OJS Notification: submission #{$submissionId} event ({$type}).",
        };
    }

    /** @param array<string,mixed> $params */
    private static function translate(string $key, array $params, string $fallback): string
    {
        if (!function_exists('__')) {
            return $fallback;
        }

        return (string) __($key, $params);
    }
}
