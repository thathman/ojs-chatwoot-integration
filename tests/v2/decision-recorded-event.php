<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\DecisionRecordedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function decisionRecordedEventCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeSubmissionForDecisionEvent
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

final class FakeDecisionForDecisionEvent
{
    /** @param array<string,mixed> $data */
    public function __construct(private int $id, private array $data)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}

const ACCEPT = 2;
const DECLINE = 6;

$submission = new FakeSubmissionForDecisionEvent(900, 3);
$decision = new FakeDecisionForDecisionEvent(501, ['decision' => ACCEPT, 'submissionId' => 900]);
$event = DecisionRecordedEventAdapter::fromDecision($decision, $submission, 7, 'Test Submission');

decisionRecordedEventCheck($event !== null, 'a valid decision must produce an event');
decisionRecordedEventCheck($event->type() === SupportEventType::SUBMISSION_DECISION_RECORDED, 'type must be submission.decision_recorded');
decisionRecordedEventCheck($event->resourceType() === 'submission' && $event->resourceId() === 900, 'the event resource must be the submission, not the decision');
decisionRecordedEventCheck($event->attributes() === ['title' => 'Test Submission', 'decisionCode' => ACCEPT, 'stageId' => 3], 'attributes must contain only the safe, already-known fields');

$jsonEvent = (string) json_encode($event->toArray());
foreach (['email', 'author', 'identifier', 'name'] as $forbidden) {
    decisionRecordedEventCheck(!str_contains($jsonEvent, $forbidden), "the event must never carry a delivery-target identity field ({$forbidden})");
}

// --- EVT-002: a submission can receive many decisions; each must be a
// distinct occurrence, not collapsed into one key by resource id alone ---
$secondDecisionSameSubmission = new FakeDecisionForDecisionEvent(502, ['decision' => DECLINE, 'submissionId' => 900]);
$secondEvent = DecisionRecordedEventAdapter::fromDecision($secondDecisionSameSubmission, $submission, 7, 'Test Submission');
decisionRecordedEventCheck(
    $event->idempotencyKey() !== $secondEvent->idempotencyKey(),
    'two distinct decisions on the same submission must never share an idempotency key'
);

// --- a duplicate hook firing for the *same* decision must collide ---
$duplicateEvent = DecisionRecordedEventAdapter::fromDecision($decision, $submission, 7, 'Test Submission');
decisionRecordedEventCheck(
    $event->idempotencyKey() === $duplicateEvent->idempotencyKey(),
    'the same decision, converted twice, must always derive the same idempotency key'
);

// --- invalid input degrades to null rather than a fatal ---
decisionRecordedEventCheck(DecisionRecordedEventAdapter::fromDecision(null, $submission, 7, 'x') === null, 'a non-object decision must return null');
decisionRecordedEventCheck(DecisionRecordedEventAdapter::fromDecision($decision, null, 7, 'x') === null, 'a non-object submission must return null');
decisionRecordedEventCheck(
    DecisionRecordedEventAdapter::fromDecision(new FakeDecisionForDecisionEvent(0, []), $submission, 7, 'x') === null,
    'a zero/invalid decision id must return null'
);
decisionRecordedEventCheck(DecisionRecordedEventAdapter::fromDecision($decision, $submission, 0, 'x') === null, 'a zero/invalid context id must return null');

fwrite(STDOUT, "Decision-recorded event adapter tests passed\n");
