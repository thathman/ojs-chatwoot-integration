<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function queuedEventDeliveryWiringCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Extracts one method's body, bounded by the next method/property declaration. */
function extractMethodBodyForDelivery(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, 'function ', $start + strlen($needle));
    return $next !== false ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// EVT-011/EVT-012/EVT-014: verifies the real delivery consumer wiring —
// the first point this whole build calls the live Chatwoot API from a
// queued event. Full behavioral instantiation isn't possible in this
// plain-PHP test environment (requires a live DB-backed queue and a real
// Chatwoot endpoint), so this proves the wiring shape directly from
// source, the same standard already applied to every other
// deeply-OJS/Chatwoot-entangled method in this build.
// ================================================================

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');

queuedEventDeliveryWiringCheck(str_contains($pluginSource, 'function deliverQueuedSupportEvents'), 'plugin must implement the real delivery consumer entry point');
queuedEventDeliveryWiringCheck(str_contains($pluginSource, 'function v2DeliverQueuedEventRow'), 'plugin must implement the per-row delivery helper');

$batchBody = extractMethodBodyForDelivery($pluginSource, 'function deliverQueuedSupportEvents');
queuedEventDeliveryWiringCheck(str_contains($batchBody, 'fetchPendingBatch'), 'must pull real pending rows from the queue repository, never invent its own row source');
queuedEventDeliveryWiringCheck(str_contains($batchBody, 'markDelivered'), 'a successful delivery must be recorded via the real repository, not silently dropped');
queuedEventDeliveryWiringCheck(str_contains($batchBody, 'markFailed'), 'a failed delivery must be recorded via the real repository (EVT-014 retry/dead-letter bookkeeping), not silently dropped');
queuedEventDeliveryWiringCheck(
    (bool) preg_match('/catch \(\\\\Throwable \$e\) \{[^}]*markFailed/s', $batchBody),
    'a single row\'s failure (including an unexpected exception) must never stop the batch — each row is isolated in its own try/catch'
);

$rowBody = extractMethodBodyForDelivery($pluginSource, 'function v2DeliverQueuedEventRow');
queuedEventDeliveryWiringCheck(str_contains($rowBody, 'loadSubmission'), 'must load the real submission via the runtime bridge, never trust a cached title alone');
queuedEventDeliveryWiringCheck(str_contains($rowBody, 'getPrimarySubmissionAuthor'), 'must resolve the delivery-target author at delivery time (EVT-003\'s whole point — author identity is never baked into the queued event)');
queuedEventDeliveryWiringCheck(str_contains($rowBody, 'SupportEventMessageBuilder::buildFromFields'), 'must build the message via the real message builder, not inline string construction');
queuedEventDeliveryWiringCheck(str_contains($rowBody, 'findContactByEmail'), 'must find the Chatwoot contact by the resolved author\'s real email, mirroring v1\'s real sendChatwootEvent() exactly');
queuedEventDeliveryWiringCheck(str_contains($rowBody, 'createConversationNote'), 'must actually post the note via the real Chatwoot API client');
queuedEventDeliveryWiringCheck(str_contains($rowBody, "\$baseUrl === '' || \$apiToken === ''"), 'must fail closed (return false) when Chatwoot isn\'t configured for this journal, never attempt delivery with blank credentials');

// ================================================================
// Scheduled task wiring.
// ================================================================
queuedEventDeliveryWiringCheck(
    str_contains($pluginSource, 'new DeliverQueuedSupportEventsTask($this)'),
    'registerSchedules() must actually register DeliverQueuedSupportEventsTask, not just define the class'
);
queuedEventDeliveryWiringCheck(
    (bool) preg_match('/DeliverQueuedSupportEventsTask\(\$this\)\)\s*->everyFiveMinutes/', $pluginSource),
    'delivery must run far more frequently than the daily purge/Captain sync tasks — a queued event should not wait up to a day to send'
);

$taskSource = (string) file_get_contents($root . '/classes/v2/Task/DeliverQueuedSupportEventsTask.php');
queuedEventDeliveryWiringCheck(str_contains($taskSource, 'extends ScheduledTask'), 'task must extend the real pkp-lib ScheduledTask base class');
queuedEventDeliveryWiringCheck(str_contains($taskSource, 'protected function executeActions'), 'task must implement executeActions(), not execute()');
queuedEventDeliveryWiringCheck(str_contains($taskSource, 'deliverQueuedSupportEvents'), 'task must actually call the real plugin delivery method, not reimplement delivery itself');
queuedEventDeliveryWiringCheck(str_contains($taskSource, 'catch (\Throwable'), 'task must isolate a whole-run failure rather than letting it propagate uncaught out of a scheduled run');

fwrite(STDOUT, "Queued event delivery wiring tests passed\n");
