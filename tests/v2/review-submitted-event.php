<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\ReviewSubmittedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function reviewSubmittedEventCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeReviewAssignmentForSubmittedEvent
{
    public function __construct(private int $id, private int $submissionId, private int $status)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getSubmissionId(): int
    {
        return $this->submissionId;
    }
    public function getStatus(): int
    {
        return $this->status;
    }
}

const STATUS_AWAITING_RESPONSE = 0;
const STATUS_ACCEPTED = 5;
const STATUS_RECEIVED = 7;
const STATUS_COMPLETE = 8;

$old = new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_ACCEPTED);
$new = new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_RECEIVED);
$event = ReviewSubmittedEventAdapter::fromReviewAssignmentEdit($new, $old, 7, 'Test Submission');

reviewSubmittedEventCheck($event !== null, 'a transition into RECEIVED must produce an event');
reviewSubmittedEventCheck($event->type() === SupportEventType::SUBMISSION_REVIEW_SUBMITTED, 'type must be submission.review_submitted');
reviewSubmittedEventCheck($event->resourceType() === 'submission' && $event->resourceId() === 900, 'the event resource must be the submission, not the review assignment');
reviewSubmittedEventCheck($event->attributes() === ['title' => 'Test Submission'], 'attributes must never include reviewer identity — only the safe fact');

$json = (string) json_encode($event->toArray());
foreach (['email', 'reviewerName', 'reviewer_id', 'name', 'identifier'] as $forbidden) {
    reviewSubmittedEventCheck(!str_contains($json, $forbidden), "the event must never carry a reviewer identity field ({$forbidden})");
}

// --- transitions that are NOT "review submitted" must never produce an event ---
reviewSubmittedEventCheck(
    ReviewSubmittedEventAdapter::fromReviewAssignmentEdit(
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_ACCEPTED),
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_AWAITING_RESPONSE),
        7,
        'x'
    ) === null,
    'a transition to ACCEPTED (not RECEIVED) must not produce an event'
);
reviewSubmittedEventCheck(
    ReviewSubmittedEventAdapter::fromReviewAssignmentEdit(
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_COMPLETE),
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_RECEIVED),
        7,
        'x'
    ) === null,
    'a transition OUT of RECEIVED (e.g. editor confirms it) must not produce a second event'
);
reviewSubmittedEventCheck(
    ReviewSubmittedEventAdapter::fromReviewAssignmentEdit(
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_RECEIVED),
        new FakeReviewAssignmentForSubmittedEvent(301, 900, STATUS_RECEIVED),
        7,
        'x'
    ) === null,
    'a non-transition (old status === new status) must not produce an event'
);

// --- idempotency: the same real occurrence, converted twice, must collide;
// a different review assignment on the same submission must never collide ---
$duplicate = ReviewSubmittedEventAdapter::fromReviewAssignmentEdit($new, $old, 7, 'Test Submission');
reviewSubmittedEventCheck($event->idempotencyKey() === $duplicate->idempotencyKey(), 'the same transition converted twice must derive the same idempotency key');

$otherReviewer = ReviewSubmittedEventAdapter::fromReviewAssignmentEdit(
    new FakeReviewAssignmentForSubmittedEvent(302, 900, STATUS_RECEIVED),
    new FakeReviewAssignmentForSubmittedEvent(302, 900, STATUS_ACCEPTED),
    7,
    'Test Submission'
);
reviewSubmittedEventCheck($event->idempotencyKey() !== $otherReviewer->idempotencyKey(), 'a different reviewer\'s assignment on the same submission must never collide');

// --- invalid input degrades to null rather than a fatal ---
reviewSubmittedEventCheck(ReviewSubmittedEventAdapter::fromReviewAssignmentEdit(null, $old, 7, 'x') === null, 'a non-object new assignment must return null');
reviewSubmittedEventCheck(ReviewSubmittedEventAdapter::fromReviewAssignmentEdit($new, null, 7, 'x') === null, 'a non-object old assignment must return null');
reviewSubmittedEventCheck(ReviewSubmittedEventAdapter::fromReviewAssignmentEdit($new, $old, 0, 'x') === null, 'a zero/invalid context id must return null');

fwrite(STDOUT, "Review-submitted event adapter tests passed\n");
