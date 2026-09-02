<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function evt019Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Extracts one method's body, bounded by the next method/property declaration. */
function extractMethodBodyEvt019(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, 'function ', $start + strlen($needle));
    return $next !== false ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// EVT-019: closes EVT-007's adapter-only slice. ReviewSubmittedEventAdapter
// was built and unit-tested (tests/v2/review-submitted-event.php) but
// never connected to any real hook — Sync/Repair-style "built but
// unwired" gap the hostile completion audit register calls out
// elsewhere. This wires it to the real, verified `ReviewAssignment::edit`
// hook (confirmed against the real pkp-lib stable_3_5_0 source:
// PKP\submission\reviewAssignment\Repository::edit() calls
// `Hook::call('ReviewAssignment::edit', [$newReviewAssignment,
// $reviewAssignment, $params])` before its own DB write).
//
// Per the directive's "move new event types directly to v2" instruction
// (v1 never had a review event at all — there is no v1 handler this
// could parallel or duplicate), this goes straight into the v2 pipeline:
// no dispatchEvent()/apiQueue involvement, and no live delivery either
// (v2's LIVE_DELIVERY_ALLOWLIST stays empty — this event is recorded,
// not yet actually delivered to Chatwoot, matching EVT-016A's existing
// posture for all 8 known types).
// ================================================================

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');

evt019Check(
    str_contains($pluginSource, "Hook::add('ReviewAssignment::edit', [\$this, 'handleReviewAssignmentEdit'])"),
    'register() must actually register the real ReviewAssignment::edit hook, not just define a handler nobody calls'
);

$body = extractMethodBodyEvt019($pluginSource, 'function handleReviewAssignmentEdit');
evt019Check($body !== '', 'plugin must define handleReviewAssignmentEdit()');

evt019Check(
    str_contains($body, 'ReviewSubmittedEventAdapter::fromReviewAssignmentEdit'),
    'handleReviewAssignmentEdit() must convert via the real, already-unit-tested ReviewSubmittedEventAdapter, not a bespoke inline conversion'
);
evt019Check(
    str_contains($body, 'v2EnqueueEvent'),
    'handleReviewAssignmentEdit() must enqueue through the shared v2EnqueueEvent() resolve+enqueue step, matching every other v2-native event wiring'
);
evt019Check(
    (bool) preg_match('/catch \(\\\\Throwable \$e\) \{[^}]*\}\s*return false;/s', $body),
    'a v2 enqueue failure must never break a real review-assignment edit — must be wrapped in its own try/catch'
);
foreach (['createConversation', 'sendChatwootEvent', 'ChatwootApiService', 'Mail::send', 'dispatchEvent(', 'enqueueApiJob'] as $forbidden) {
    evt019Check(!str_contains($body, $forbidden), "handleReviewAssignmentEdit() must never touch '{$forbidden}' — no v1/dispatchEvent()/apiQueue involvement for a v2-native event, and no direct delivery (only enqueue)");
}

// Blind-review discipline: never resolve or reference the reviewer's own
// identity when building the event.
foreach (['getReviewerId', 'reviewer_id', 'reviewerEmail', 'reviewerName'] as $forbiddenIdentity) {
    evt019Check(!str_contains($body, $forbiddenIdentity), "handleReviewAssignmentEdit() must never reference '{$forbiddenIdentity}' — POL-009/010 blind-review discipline: this event describes only the submission-facing fact that a review came in, never who submitted it");
}

fwrite(STDOUT, "PASS: evt-019-review-submitted-hook-wiring\n");
