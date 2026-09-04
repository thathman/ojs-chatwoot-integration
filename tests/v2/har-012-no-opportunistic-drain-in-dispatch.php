<?php

declare(strict_types=1);

namespace PKP\plugins {
    /** Same minimal in-memory GenericPlugin double established by tests/v2/settings-small-002-export-import-completeness.php. */
    class GenericPlugin
    {
        /** @var array<int,array<string,mixed>> */
        public array $settings = [];

        public function getSetting($contextId, $key)
        {
            return $this->settings[(int) $contextId][(string) $key] ?? null;
        }

        public function updateSetting($contextId, $key, $value, $type = null)
        {
            $this->settings[(int) $contextId][(string) $key] = $value;
        }

        public function getEnabled($contextId = null)
        {
            return true;
        }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;

    function har012Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-012: all eight real event types are now live-owned by v2
     * (isLiveDeliveryOwnedByV2() returns true for every one of them),
     * so dispatchEvent()'s only remaining live caller is the deliberate,
     * rare Send Test Message admin action — never a real event
     * occurrence. It used to still opportunistically drain the legacy
     * queue on every call (processApiQueue($contextId, 4)); this
     * proves that call is gone, leaving ProcessLegacyRetryQueueScheduledTask
     * as the sole reliable drain path, and that a job dispatchEvent()
     * itself queues is untouched by the same call (no self-drain of
     * the job it just enqueued either).
     */
    $source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $dispatchStart = strpos($source, 'private function dispatchEvent(');
    har012Check($dispatchStart !== false, 'dispatchEvent() must exist');
    $dispatchBody = substr($source, $dispatchStart, (int) strpos($source, "\n    }\n", $dispatchStart) - $dispatchStart);
    har012Check(!str_contains($dispatchBody, 'processApiQueue('), 'dispatchEvent() must no longer opportunistically drain the queue on every call — that drain site is retired, the scheduled task is now sole reliable drain path');

    $plugin = new ChatwootIntegrationBasePlugin();
    $contextId = 11;
    $plugin->updateSetting($contextId, 'retryQueueEnabled', true, 'bool');
    $plugin->updateSetting($contextId, 'maxRetryAttempts', 5, 'int');

    $dispatchEvent = new \ReflectionMethod($plugin, 'dispatchEvent');
    $getApiQueue = new \ReflectionMethod($plugin, 'getApiQueue');

    // A failed dispatch (no connection settings configured) enqueues a
    // job; a second, unrelated failed dispatch must not touch the
    // first job's attempts/runAfter — proving dispatchEvent() no longer
    // drains anything as a side effect of being called again.
    $dispatchEvent->invoke($plugin, $contextId, ['email' => 'first@example.com', 'message' => 'one']);
    $queueAfterFirst = $getApiQueue->invoke($plugin, $contextId);
    har012Check(count($queueAfterFirst) === 1 && (int) $queueAfterFirst[0]['attempts'] === 0, 'the first enqueued job must start untouched at zero attempts');

    $dispatchEvent->invoke($plugin, $contextId, ['email' => 'second@example.com', 'message' => 'two']);
    $queueAfterSecond = $getApiQueue->invoke($plugin, $contextId);
    har012Check(count($queueAfterSecond) === 2, 'a second dispatch must enqueue its own job, not merge/replace the first');
    har012Check((int) $queueAfterSecond[0]['attempts'] === 0, 'the first job must remain at zero attempts after a second, unrelated dispatchEvent() call — no more opportunistic self-drain');

    /**
     * HAR-012/HAR-013: syncEmailTemplates() also opportunistically
     * drained the shared apiQueue (processApiQueue($contextId, 8))
     * before running its own sync — but that queue mixes job types
     * (canned_response_sync from this action, conversation_event from
     * Send Test Message), so clicking "Sync Email Templates" could as
     * a side effect redeliver an unrelated queued Test Message. Proves
     * that call is gone; this action only ever enqueues its own jobs.
     */
    $syncStart = strpos($source, 'function syncEmailTemplates(');
    har012Check($syncStart !== false, 'syncEmailTemplates() must exist');
    $syncBody = substr($source, $syncStart, (int) strpos($source, "\n    }\n", $syncStart) - $syncStart);
    har012Check(!str_contains($syncBody, 'processApiQueue('), 'syncEmailTemplates() must no longer opportunistically drain the shared apiQueue — it mixes unrelated job types, so this was a real "drain an unrelated queue as a side effect" bug');

    fwrite(STDOUT, "HAR-012 no-opportunistic-drain-in-dispatch tests passed\n");
}
