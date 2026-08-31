<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS publication into a normalized
 * `publication.scheduled`/`publication.published` SupportEvent
 * (docs/v2/TASKLIST.md EVT-005), mirroring v1's
 * `ChatwootIntegrationPlugin::handlePublicationPublished()`.
 *
 * Excludes author identity — same delivery-vs-fact separation as the
 * other EVT-00x adapters.
 *
 * `naturalKey` is the publication's own id (a real `PKP\core\DataObject`,
 * stable and unique) — a submission can have more than one publication
 * over its lifetime (each new version), and a single publication can pass
 * through `scheduled` then `published`, so keying on the submission id
 * alone (as `SubmissionCreatedEventAdapter` correctly does for its
 * once-only event) would collapse distinct occurrences.
 */
final class PublicationStatusEventAdapter
{
    private const STATUS_PUBLISHED = 3;
    private const STATUS_SCHEDULED = 5;

    public static function fromPublication($publication, $submission, int $contextId, string $title): ?SupportEvent
    {
        if (!is_object($publication) || !method_exists($publication, 'getId') || !method_exists($publication, 'getData')) {
            return null;
        }
        if (!is_object($submission) || !method_exists($submission, 'getId')) {
            return null;
        }

        $publicationId = (int) $publication->getId();
        $submissionId = (int) $submission->getId();
        if ($publicationId <= 0 || $submissionId <= 0 || $contextId <= 0) {
            return null;
        }

        $status = (int) $publication->getData('status');
        $type = match ($status) {
            self::STATUS_SCHEDULED => SupportEventType::PUBLICATION_SCHEDULED,
            self::STATUS_PUBLISHED => SupportEventType::PUBLICATION_PUBLISHED,
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
            (string) $publicationId,
            [
                'title' => $title,
                'publicationStatus' => $status,
            ]
        );
    }
}
