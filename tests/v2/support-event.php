<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEvent;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function supportEventCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- basic field population ---
$event = SupportEvent::create(
    SupportEventType::SUBMISSION_CREATED,
    7,
    'submission',
    900,
    '',
    ['title' => 'Test Submission'],
    1700000000
);
supportEventCheck($event->type() === SupportEventType::SUBMISSION_CREATED, 'type must round-trip');
supportEventCheck($event->contextId() === 7, 'contextId must round-trip');
supportEventCheck($event->resourceType() === 'submission' && $event->resourceId() === 900, 'resource must round-trip');
supportEventCheck($event->occurredAt() === 1700000000, 'an explicit occurredAt must be honored rather than always defaulting to now');
supportEventCheck($event->attributes() === ['title' => 'Test Submission'], 'attributes must be passed through unchanged');

// --- occurredAt defaults to "now" when omitted ---
$before = time();
$defaultedEvent = SupportEvent::create(SupportEventType::SUBMISSION_CREATED, 7, 'submission', 900, '');
$after = time();
supportEventCheck($defaultedEvent->occurredAt() >= $before && $defaultedEvent->occurredAt() <= $after, 'occurredAt must default to the current time when not given');

// --- EVT-002: stable idempotency keys ---
// The same real occurrence, described identically, must always derive the
// same key (replay-safety) ...
$a1 = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 7, 'submission', 900, 'decision-501');
$a2 = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 7, 'submission', 900, 'decision-501');
supportEventCheck($a1->idempotencyKey() === $a2->idempotencyKey(), 'identical (type, contextId, resource, naturalKey) must derive the identical idempotency key');

$laterRetry = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 7, 'submission', 900, 'decision-501', [], $a1->occurredAt() + 3600);
supportEventCheck($laterRetry->idempotencyKey() === $a1->idempotencyKey(), 'a retry of the same occurrence at a different time must still derive the same key');

// ... but a genuinely different occurrence must never collide:
// a second, distinct decision on the very same submission
$secondDecision = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 7, 'submission', 900, 'decision-502');
supportEventCheck($secondDecision->idempotencyKey() !== $a1->idempotencyKey(), 'two distinct decisions on the same submission must never share an idempotency key');

// a different event type on the same submission/naturalKey
$differentType = SupportEvent::create(SupportEventType::SUBMISSION_ACCEPTED, 7, 'submission', 900, 'decision-501');
supportEventCheck($differentType->idempotencyKey() !== $a1->idempotencyKey(), 'a different event type must never collide even with the same resource/naturalKey');

// a different resource id
$differentResource = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 7, 'submission', 901, 'decision-501');
supportEventCheck($differentResource->idempotencyKey() !== $a1->idempotencyKey(), 'a different resource id must never collide');

// a different journal — cross-journal isolation, consistent with every
// other multi-journal isolation guarantee in this codebase
$differentContext = SupportEvent::create(SupportEventType::SUBMISSION_DECISION_RECORDED, 8, 'submission', 900, 'decision-501');
supportEventCheck($differentContext->idempotencyKey() !== $a1->idempotencyKey(), 'a different journal must never collide, even with an identical resource/naturalKey');

// --- toArray() shape ---
$array = $event->toArray();
supportEventCheck(
    array_keys($array) === ['type', 'contextId', 'resource', 'idempotencyKey', 'occurredAt', 'attributes'],
    'toArray() must expose exactly this fixed field set, in this order'
);
supportEventCheck($array['resource'] === ['type' => 'submission', 'id' => 900], 'toArray() resource shape must match');

// --- SupportEventType catalog ---
supportEventCheck(
    SupportEventType::all() === [
        SupportEventType::SUBMISSION_CREATED,
        SupportEventType::SUBMISSION_DECISION_RECORDED,
        SupportEventType::SUBMISSION_REVISION_REQUESTED,
        SupportEventType::SUBMISSION_ACCEPTED,
        SupportEventType::SUBMISSION_REJECTED,
        SupportEventType::PUBLICATION_SCHEDULED,
        SupportEventType::PUBLICATION_PUBLISHED,
    ],
    'SupportEventType::all() must list exactly the 7 v1-derived event kinds, in a stable order'
);
foreach (SupportEventType::all() as $type) {
    supportEventCheck(str_contains($type, '.'), "every event type must use dot-notation namespacing, consistent with CapabilityCatalog ({$type})");
}

fwrite(STDOUT, "Support event model tests passed\n");
