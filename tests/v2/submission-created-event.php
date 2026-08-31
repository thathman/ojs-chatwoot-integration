<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SubmissionCreatedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function submissionCreatedEventCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeSubmissionForCreatedEvent
{
    public function __construct(private int $id, private int $stageId)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getData(string $key): mixed
    {
        return $key === 'stageId' ? $this->stageId : null;
    }
}

$submission = new FakeSubmissionForCreatedEvent(900, 1);
$event = SubmissionCreatedEventAdapter::fromSubmission($submission, 7, 'Test Submission');

submissionCreatedEventCheck($event !== null, 'a valid submission must produce an event');
submissionCreatedEventCheck($event->type() === SupportEventType::SUBMISSION_CREATED, 'type must be submission.created');
submissionCreatedEventCheck($event->contextId() === 7, 'contextId must round-trip');
submissionCreatedEventCheck($event->resourceType() === 'submission' && $event->resourceId() === 900, 'resource must round-trip');
submissionCreatedEventCheck($event->attributes() === ['title' => 'Test Submission', 'stageId' => 1], 'attributes must contain only the safe, already-known fields');

$jsonEvent = (string) json_encode($event->toArray());
foreach (['email', 'author', 'identifier', 'name'] as $forbidden) {
    submissionCreatedEventCheck(!str_contains($jsonEvent, $forbidden), "the event must never carry a delivery-target identity field ({$forbidden})");
}

// --- idempotency: the same submission created "twice" (e.g. a duplicate
// hook firing) must always derive the same key ---
$duplicate = SubmissionCreatedEventAdapter::fromSubmission($submission, 7, 'Test Submission');
submissionCreatedEventCheck($event->idempotencyKey() === $duplicate->idempotencyKey(), 'the same submission must always derive the same idempotency key, regardless of when the hook fires');

// --- a different submission must never collide ---
$otherSubmission = new FakeSubmissionForCreatedEvent(901, 1);
$otherEvent = SubmissionCreatedEventAdapter::fromSubmission($otherSubmission, 7, 'Another Submission');
submissionCreatedEventCheck($event->idempotencyKey() !== $otherEvent->idempotencyKey(), 'a different submission must never share an idempotency key');

// --- invalid input degrades to null rather than a fatal ---
submissionCreatedEventCheck(SubmissionCreatedEventAdapter::fromSubmission(null, 7, 'x') === null, 'a non-object submission must return null');
submissionCreatedEventCheck(SubmissionCreatedEventAdapter::fromSubmission(new FakeSubmissionForCreatedEvent(0, 1), 7, 'x') === null, 'a zero/invalid submission id must return null');
submissionCreatedEventCheck(SubmissionCreatedEventAdapter::fromSubmission($submission, 0, 'x') === null, 'a zero/invalid context id must return null');

fwrite(STDOUT, "Submission-created event adapter tests passed\n");
