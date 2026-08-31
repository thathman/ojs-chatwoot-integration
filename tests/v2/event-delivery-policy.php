<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function eventDeliveryPolicyCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- v1 legacy value mapping (preserve configured event choices) ---
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(SupportEventType::SUBMISSION_CREATED, 'note') === EventDeliveryMode::PRIVATE_NOTE,
    "v1's real 'note' setting value must map to PRIVATE_NOTE"
);
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(SupportEventType::SUBMISSION_CREATED, 'open_update') === EventDeliveryMode::OPEN_UPDATE_CONVERSATION,
    "v1's real 'open_update' setting value must map to OPEN_UPDATE_CONVERSATION"
);
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(SupportEventType::SUBMISSION_CREATED, '') === EventDeliveryMode::PRIVATE_NOTE,
    "a blank/unset global mode must default to PRIVATE_NOTE, matching v1's own 'note' default"
);
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(SupportEventType::SUBMISSION_CREATED, 'garbage-unrecognized-value') === EventDeliveryMode::PRIVATE_NOTE,
    'an unrecognized global mode value must degrade to PRIVATE_NOTE rather than propagate an invalid mode'
);

// --- per-event overrides (the "per event" half EVT-010 adds beyond v1) ---
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(
        SupportEventType::PUBLICATION_PUBLISHED,
        'note',
        [SupportEventType::PUBLICATION_PUBLISHED => EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE]
    ) === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE,
    'a per-event override must take precedence over the global mode'
);
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(
        SupportEventType::SUBMISSION_CREATED,
        'note',
        [SupportEventType::PUBLICATION_PUBLISHED => EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE]
    ) === EventDeliveryMode::PRIVATE_NOTE,
    'an override for a different event type must never leak onto an unrelated event'
);
eventDeliveryPolicyCheck(
    EventDeliveryPolicy::resolve(
        SupportEventType::SUBMISSION_CREATED,
        'note',
        [SupportEventType::SUBMISSION_CREATED => 'not-a-real-mode']
    ) === EventDeliveryMode::PRIVATE_NOTE,
    'an invalid per-event override value must degrade to PRIVATE_NOTE rather than propagate an invalid mode'
);

// --- every real event type must resolve to something, never throw ---
foreach (SupportEventType::all() as $type) {
    $resolved = EventDeliveryPolicy::resolve($type, 'note');
    eventDeliveryPolicyCheck(in_array($resolved, EventDeliveryMode::all(), true), "every event type must resolve to a real delivery mode ({$type})");
}

fwrite(STDOUT, "Event delivery policy tests passed\n");
