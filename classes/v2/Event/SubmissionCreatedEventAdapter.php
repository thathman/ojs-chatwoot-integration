<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS submission into a normalized `submission.created`
 * SupportEvent (docs/v2/TASKLIST.md EVT-003 — first of the v1 event
 * migrations).
 *
 * Deliberately excludes the author's identity (email/name) that v1's
 * `ChatwootIntegrationPlugin::handleSubmissionCreated()` bundles into the
 * same payload it uses for Chatwoot contact lookup — this class only
 * describes the event fact itself (docs/v2/ARCHITECTURE.md §3.9's
 * `SupportEvent`), never the delivery target. Resolving who to notify is a
 * queued-delivery-stage concern (EVT-010/EVT-011), not something baked
 * into the event.
 *
 * A `submission.created` event can only happen once per submission, so
 * `naturalKey` is the empty string — the (type, contextId, resourceType,
 * resourceId) tuple alone is already unique.
 *
 * Not yet wired to any real OJS hook — that wiring, and the decision of
 * whether it replaces or runs alongside v1's existing
 * `handleSubmissionCreated()`, is a separate, higher-risk slice.
 */
final class SubmissionCreatedEventAdapter
{
    public static function fromSubmission($submission, int $contextId, string $title): ?SupportEvent
    {
        if (!is_object($submission) || !method_exists($submission, 'getId')) {
            return null;
        }

        $submissionId = (int) $submission->getId();
        if ($submissionId <= 0 || $contextId <= 0) {
            return null;
        }

        $stageId = method_exists($submission, 'getData') ? (int) $submission->getData('stageId') : 0;

        return SupportEvent::create(
            SupportEventType::SUBMISSION_CREATED,
            $contextId,
            'submission',
            $submissionId,
            '',
            [
                'title' => $title,
                'stageId' => $stageId,
            ]
        );
    }
}
