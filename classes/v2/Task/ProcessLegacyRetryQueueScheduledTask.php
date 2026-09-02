<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\scheduledTask\ScheduledTask;

/**
 * EVT-018: v1's legacy `apiQueue` retry queue used to be opportunistically
 * drained inside `addChatwootWidget()` on every single
 * `TemplateManager::display`/`fetch` call site-wide — a real network/queue
 * side effect during template rendering, including anonymous frontend
 * pages, admin pages, and component/AJAX renders unrelated to Chatwoot at
 * all. That drain is removed; this scheduled task is now the queue's
 * reliable, bounded consumer, exactly like
 * `DeliverQueuedSupportEventsTask` is for the v2 durable queue.
 *
 * Runs across every real journal (same `Application::getContextDAO()->
 * getAll(true)` pattern as `CaptainSyncScheduledTask`) — `processApiQueue()`
 * itself already no-ops per journal when `retryQueueEnabled` is false or
 * the queue is empty, so this is safe to run unconditionally for all of
 * them. dispatchEvent()'s own opportunistic drain on a genuinely new event,
 * and the explicit "Sync Email Templates" admin action, remain as
 * additional real-but-incidental drain points — this task is what makes
 * retry delivery reliable for low-traffic journals between those.
 */
final class ProcessLegacyRetryQueueScheduledTask extends ScheduledTask
{
    /** @see DeliverQueuedSupportEventsTask::__construct() for why this must call parent::__construct() (TST-021). */
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'Chatwoot Support Gateway: process legacy retry queue';
    }

    protected function executeActions(): bool
    {
        try {
            $journals = Application::getContextDAO()->getAll(true);
            $processed = 0;

            while ($context = $journals->next()) {
                $this->plugin->processQueuedApiJobsForContext((int) $context->getId(), 20);
                $processed++;
            }

            $this->addExecutionLogEntry(sprintf('Processed the legacy retry queue for %d journal(s).', $processed));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('Legacy retry queue processing failed: ' . $e->getMessage());
            return false;
        }
    }
}
