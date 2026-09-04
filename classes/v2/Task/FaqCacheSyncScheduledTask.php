<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SafeExceptionMessage;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\scheduledTask\ScheduledTask;

/**
 * KNO-011: drives ChatwootIntegrationV2Plugin::syncFaqCache() once per
 * enabled journal, on the same daily schedule as CaptainSyncScheduledTask
 * — a separate task (not folded into that one) since FAQ sync is a
 * one-way Chatwoot→OJS read, the opposite direction from Captain
 * provisioning, and has its own independent failure mode.
 *
 * A single journal's sync failure (already caught and degraded to
 * null/-1 inside syncFaqCache() itself) never stops the loop for the
 * remaining journals.
 */
final class FaqCacheSyncScheduledTask extends ScheduledTask
{
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function getName(): string
    {
        return 'Chatwoot Support Gateway: sync approved FAQ cache';
    }

    protected function executeActions(): bool
    {
        try {
            $request = Application::get()->getRequest();
            $journals = Application::getContextDAO()->getAll(true);

            $journalsSynced = 0;
            $faqsSynced = 0;

            while ($context = $journals->next()) {
                $result = $this->plugin->syncFaqCache($request, $context);
                if ($result !== null && $result >= 0) {
                    $journalsSynced++;
                    $faqsSynced += $result;
                }
            }

            $this->addExecutionLogEntry(sprintf(
                'Synced approved FAQ cache for %d journal(s): %d FAQ fact(s) total.',
                $journalsSynced,
                $faqsSynced
            ));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('FAQ cache sync failed: ' . SafeExceptionMessage::describe($e));
            return false;
        }
    }
}
