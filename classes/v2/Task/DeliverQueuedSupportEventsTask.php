<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\scheduledTask\ScheduledTask;

/**
 * EVT-011/EVT-012/EVT-014: drives
 * `ChatwootIntegrationV2Plugin::deliverQueuedSupportEvents()` on the same
 * daily schedule as `PurgeExpiredSupportDataTask`/`CaptainSyncScheduledTask`
 * — the Event Bridge v2 "queued delivery" stage's consumer, reading
 * `chatwoot_support_event_queue` and actually posting to Chatwoot.
 */
final class DeliverQueuedSupportEventsTask extends ScheduledTask
{
    /**
     * TST-021: must call parent::__construct() — pkp-lib's own
     * ScheduledTask::__construct() is what initializes the typed
     * $executionLogFile property (via PrivateFileManager); skipping it
     * left every real invocation of this task fataling with "Typed
     * property ScheduledTask::$executionLogFile must not be accessed
     * before initialization" the moment addExecutionLogEntry() ran —
     * confirmed live via the real OJS web-based task scheduler on
     * ojs-demo.airixmedia.com, which reproduced on every request once the
     * 60-second task_runner_interval elapsed.
     */
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'Chatwoot Support Gateway: deliver queued Event Bridge events';
    }

    protected function executeActions(): bool
    {
        try {
            $result = $this->plugin->deliverQueuedSupportEvents();
            $this->addExecutionLogEntry(sprintf(
                'Delivered %d queued Event Bridge event(s), %d failed.',
                $result['delivered'],
                $result['failed']
            ));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('Queued event delivery failed: ' . $e->getMessage());
            return false;
        }
    }
}
