<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Normalized Event Bridge event types (docs/v2/TASKLIST.md EVT-001).
 *
 * The first 7 constants are one per real v1 event kind
 * (`ChatwootIntegrationPlugin`'s `eventSubmissionCreated`/
 * `eventRevisionRequested`/`eventAccepted`/`eventRejected`/
 * `eventPublicationScheduled`/`eventPublicationPublished`/
 * `eventDecisionRecorded` settings and their hook handlers) —
 * dot-notation naming to match this codebase's existing capability
 * namespace (`CapabilityCatalog`), not a new convention.
 *
 * `SUBMISSION_REVIEW_SUBMITTED` (EVT-007) is the first v2-native addition:
 * v1 never had a review event at all — there is no v1 setting or hook to
 * migrate here, only a real, stable pkp-lib hook
 * (`PKP\submission\reviewAssignment\Repository::edit()`'s
 * `ReviewAssignment::edit` hook) worth building an adapter for.
 *
 * Defining the type catalog is EVT-001's job. Which hook actually fires
 * which type, and what happens after, is EVT-003 onward (event
 * adapters/delivery) — deliberately not built here.
 */
final class SupportEventType
{
    public const SUBMISSION_CREATED = 'submission.created';
    public const SUBMISSION_DECISION_RECORDED = 'submission.decision_recorded';
    public const SUBMISSION_REVISION_REQUESTED = 'submission.revision_requested';
    public const SUBMISSION_ACCEPTED = 'submission.accepted';
    public const SUBMISSION_REJECTED = 'submission.rejected';
    public const PUBLICATION_SCHEDULED = 'publication.scheduled';
    public const PUBLICATION_PUBLISHED = 'publication.published';
    public const SUBMISSION_REVIEW_SUBMITTED = 'submission.review_submitted';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::SUBMISSION_CREATED,
            self::SUBMISSION_DECISION_RECORDED,
            self::SUBMISSION_REVISION_REQUESTED,
            self::SUBMISSION_ACCEPTED,
            self::SUBMISSION_REJECTED,
            self::PUBLICATION_SCHEDULED,
            self::PUBLICATION_PUBLISHED,
            self::SUBMISSION_REVIEW_SUBMITTED,
        ];
    }
}
