<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function eventHookWiringCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Extracts one method's body, bounded by the next method/property declaration. */
function extractMethodBody(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, 'function ', $start + strlen($needle));
    return $next !== false ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// EVT-003/004/005/011: verifies the real hook wiring for all four
// Event Bridge v2 hook overrides — the first live-hook wiring in this
// whole build. Full behavioral instantiation isn't possible in this
// plain-PHP test environment (each parent:: handler reaches deep into
// Repo::submission()/Mail::send()/etc., same constraint as
// addChatwootWidget() in tests/v2/live-plugin.php), so this proves the
// wiring shape directly from source, the same standard already applied
// to that method.
// ================================================================

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');

$hooks = [
    'handleSubmissionCreated' => 'SubmissionCreatedEventAdapter::fromSubmission',
    'handleEditorDecision' => 'DecisionRecordedEventAdapter::fromDecision',
    'handleSubmissionStatusUpdated' => 'SubmissionStatusChangedEventAdapter::fromStatusChange',
    'handlePublicationPublished' => 'PublicationStatusEventAdapter::fromPublication',
];

foreach ($hooks as $method => $adapterCall) {
    eventHookWiringCheck(str_contains($pluginSource, "function {$method}"), "plugin must override {$method}()");

    $body = extractMethodBody($pluginSource, "function {$method}");
    eventHookWiringCheck($body !== '', "must be able to locate {$method}()'s body for the checks below");

    eventHookWiringCheck(
        (bool) preg_match('/\$result\s*=\s*parent::' . $method . '\(\$hookName,\s*\$args\)/', $body),
        "v1's real {$method}() must run first and unconditionally — its return value is what this method returns"
    );
    eventHookWiringCheck(
        substr_count($body, 'return $result;') === 1,
        "{$method}() must return v1's real result exactly once, never a hardcoded value or the v2 enqueue outcome"
    );
    eventHookWiringCheck(
        (bool) preg_match('/catch \(\\\\Throwable \$e\) \{[^}]*\}\s*return \$result;/s', $body),
        "{$method}()'s v2 enqueue work must be wrapped in its own try/catch, positioned so a v2 failure can never prevent returning v1's real result"
    );
    eventHookWiringCheck(str_contains($body, $adapterCall), "{$method}() must convert via the real event adapter ({$adapterCall}), not a bespoke inline conversion");
    eventHookWiringCheck(str_contains($body, 'v2EnqueueEvent'), "{$method}() must enqueue through the shared v2EnqueueEvent() resolve+enqueue step, not duplicate that logic inline");
    foreach (['createConversation', 'sendChatwootEvent', 'ChatwootApiService', 'Mail::send'] as $forbidden) {
        eventHookWiringCheck(!str_contains($body, $forbidden), "{$method}() must never touch '{$forbidden}' directly — it only enqueues; delivery is a separate, not-yet-built consumer");
    }
}

// ================================================================
// Shared v2EnqueueEvent() resolve+enqueue step.
// ================================================================
$enqueueHelperBody = extractMethodBody($pluginSource, 'function v2EnqueueEvent');
eventHookWiringCheck($enqueueHelperBody !== '', 'v2EnqueueEvent() must exist as its own method for the checks below');
eventHookWiringCheck(str_contains($enqueueHelperBody, 'EventDeliveryPolicy::resolve'), 'v2EnqueueEvent() must resolve a real delivery mode via the real EVT-010 policy, not hardcode one');
eventHookWiringCheck(str_contains($enqueueHelperBody, "'eventSyncMode'"), 'v2EnqueueEvent() must preserve v1\'s real eventSyncMode setting as the delivery-policy global mode, per the EVT migration requirement to preserve configured event choices');
eventHookWiringCheck(str_contains($enqueueHelperBody, 'DatabaseSupportEventQueueRepository'), 'v2EnqueueEvent() must enqueue through the real EVT-011/014 persisted queue, never send directly to Chatwoot');
eventHookWiringCheck(
    (bool) preg_match('/if\s*\(\s*\$event\s*===\s*null\s*\)\s*\{\s*return;/', $enqueueHelperBody),
    'v2EnqueueEvent() must no-op when the adapter returned null (e.g. an unmapped/invalid transition), never attempt to enqueue a null event'
);

fwrite(STDOUT, "Event hook wiring tests passed\n");
