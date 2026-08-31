<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\PublicationStatusEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SubmissionStatusChangedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function statusPublicationEventCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeSubmissionForStatusEvent
{
    public function __construct(private int $id)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
}

final class FakePublicationForStatusEvent
{
    public function __construct(private int $id, private int $status)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getData(string $key): mixed
    {
        return $key === 'status' ? $this->status : null;
    }
}

const STATUS_QUEUED = 1;
const STATUS_PUBLISHED = 3;
const STATUS_DECLINED = 4;
const STATUS_SCHEDULED = 5;

// ================================================================
// SubmissionStatusChangedEventAdapter
// ================================================================
$submission = new FakeSubmissionForStatusEvent(900);

$rejected = SubmissionStatusChangedEventAdapter::fromStatusChange($submission, STATUS_QUEUED, STATUS_DECLINED, 7, 'Test');
statusPublicationEventCheck($rejected !== null && $rejected->type() === SupportEventType::SUBMISSION_REJECTED, 'a transition to STATUS_DECLINED must produce submission.rejected');

$accepted = SubmissionStatusChangedEventAdapter::fromStatusChange($submission, STATUS_QUEUED, STATUS_PUBLISHED, 7, 'Test');
statusPublicationEventCheck($accepted !== null && $accepted->type() === SupportEventType::SUBMISSION_ACCEPTED, 'a transition to STATUS_PUBLISHED must produce submission.accepted');

statusPublicationEventCheck(
    SubmissionStatusChangedEventAdapter::fromStatusChange($submission, STATUS_QUEUED, STATUS_SCHEDULED, 7, 'Test') === null,
    'a transition to any other status must not produce an event'
);
statusPublicationEventCheck(
    SubmissionStatusChangedEventAdapter::fromStatusChange($submission, STATUS_DECLINED, STATUS_DECLINED, 7, 'Test') === null,
    'a non-transition (old status === new status) must not produce an event'
);

$rejectedJson = (string) json_encode($rejected->toArray());
foreach (['email', 'author', 'identifier', 'name'] as $forbidden) {
    statusPublicationEventCheck(!str_contains($rejectedJson, $forbidden), "the event must never carry a delivery-target identity field ({$forbidden})");
}

$rejectedDuplicate = SubmissionStatusChangedEventAdapter::fromStatusChange($submission, STATUS_QUEUED, STATUS_DECLINED, 7, 'Test');
statusPublicationEventCheck($rejected->idempotencyKey() === $rejectedDuplicate->idempotencyKey(), 'the same transition converted twice must derive the same idempotency key');
statusPublicationEventCheck($rejected->idempotencyKey() !== $accepted->idempotencyKey(), 'a different transition on the same submission must never collide');

// ================================================================
// PublicationStatusEventAdapter
// ================================================================
$scheduledPublication = new FakePublicationForStatusEvent(501, STATUS_SCHEDULED);
$scheduledEvent = PublicationStatusEventAdapter::fromPublication($scheduledPublication, $submission, 7, 'Test');
statusPublicationEventCheck($scheduledEvent !== null && $scheduledEvent->type() === SupportEventType::PUBLICATION_SCHEDULED, 'STATUS_SCHEDULED must produce publication.scheduled');
statusPublicationEventCheck($scheduledEvent->resourceType() === 'submission' && $scheduledEvent->resourceId() === 900, 'the event resource must be the submission, not the publication');

$publishedPublication = new FakePublicationForStatusEvent(501, STATUS_PUBLISHED);
$publishedEvent = PublicationStatusEventAdapter::fromPublication($publishedPublication, $submission, 7, 'Test');
statusPublicationEventCheck($publishedEvent !== null && $publishedEvent->type() === SupportEventType::PUBLICATION_PUBLISHED, 'STATUS_PUBLISHED must produce publication.published');

// The same publication id transitioning scheduled -> published must be two
// distinct events, not a collision, since idempotency also hashes the type.
statusPublicationEventCheck($scheduledEvent->idempotencyKey() !== $publishedEvent->idempotencyKey(), 'the same publication moving from scheduled to published must be two distinct events');

// A different submission's publication with the same publication id must
// never collide either (cross-submission isolation).
$otherSubmission = new FakeSubmissionForStatusEvent(901);
$otherSubmissionEvent = PublicationStatusEventAdapter::fromPublication($scheduledPublication, $otherSubmission, 7, 'Test');
statusPublicationEventCheck($scheduledEvent->idempotencyKey() !== $otherSubmissionEvent->idempotencyKey(), 'the same publication id under a different submission must never collide');

statusPublicationEventCheck(
    PublicationStatusEventAdapter::fromPublication(new FakePublicationForStatusEvent(501, STATUS_QUEUED), $submission, 7, 'Test') === null,
    'any other publication status must not produce an event'
);
statusPublicationEventCheck(PublicationStatusEventAdapter::fromPublication(null, $submission, 7, 'Test') === null, 'a non-object publication must return null');
statusPublicationEventCheck(PublicationStatusEventAdapter::fromPublication($scheduledPublication, null, 7, 'Test') === null, 'a non-object submission must return null');

fwrite(STDOUT, "Status/publication event adapter tests passed\n");
