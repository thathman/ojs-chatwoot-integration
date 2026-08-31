<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\scheduledTask\ScheduledTask;

/**
 * Drives the idempotent Captain provisioning entry points
 * (ChatwootIntegrationV2Plugin::provisionCaptainKnowledgeDocument()/
 * provisionCaptainCustomTools()/provisionCaptainScenarios(), see
 * docs/v2/KNOWLEDGE_DIAGNOSTICS.md §6) once per enabled journal, on the
 * same daily schedule as PurgeExpiredSupportDataTask (docs/v2/TASKLIST.md
 * KNO-019).
 *
 * Uses Application::get()->getRequest() — real, but with no HTTP-routed
 * context in a scheduled task — and passes each journal in explicitly
 * as $context, which is why those three plugin methods take $context as
 * a separate parameter rather than deriving it from $request->getContext()
 * (verified via a real local checkout of pkp-lib:
 * `classes/core/Dispatcher.php`'s `url()` only ever uses $request for base
 * configuration, never for its context — the context is always an
 * explicit argument).
 *
 * Tools are provisioned before scenarios for each journal: a scenario's
 * instruction can only reference a tool by its real assigned slug.
 *
 * A single journal's provisioning failure (already caught and degraded to
 * null/[] inside the plugin methods themselves) never stops the loop for
 * the remaining journals.
 */
final class CaptainSyncScheduledTask extends ScheduledTask
{
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
    }

    public function getName(): string
    {
        return 'Chatwoot Support Gateway: sync Captain knowledge/tools/scenarios';
    }

    protected function executeActions(): bool
    {
        try {
            $request = Application::get()->getRequest();
            $journals = Application::getContextDAO()->getAll(true);

            $documentsSynced = 0;
            $toolsSynced = 0;
            $scenariosSynced = 0;

            while ($context = $journals->next()) {
                if ($this->plugin->provisionCaptainKnowledgeDocument($request, $context)) {
                    $documentsSynced++;
                }

                $tools = $this->plugin->provisionCaptainCustomTools($request, $context);
                if ($tools) {
                    $toolsSynced += count($tools);
                }

                $scenarios = $this->plugin->provisionCaptainScenarios($request, $context);
                if ($scenarios) {
                    $scenariosSynced += count($scenarios);
                }
            }

            $this->addExecutionLogEntry(sprintf(
                'Synced Captain resources for enabled journals: %d document(s), %d custom tool(s), %d scenario(s).',
                $documentsSynced,
                $toolsSynced,
                $scenariosSynced
            ));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('Captain sync failed: ' . $e->getMessage());
            return false;
        }
    }
}
