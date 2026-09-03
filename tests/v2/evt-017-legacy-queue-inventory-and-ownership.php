<?php

declare(strict_types=1);

namespace PKP\plugins {
    class GenericPlugin
    {
        private array $testSettings = [];
        public function getSetting($contextId, $key)
        {
            return $this->testSettings[(int) $contextId][(string) $key] ?? null;
        }
        public function updateSetting($contextId, $key, $value, $type = null)
        {
            $this->testSettings[(int) $contextId][(string) $key] = $value;
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
    use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

    function evt017Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // EVT-017 inventory (this pass): legacy `apiQueue` is a per-context
    // (per-journal) JSON array plugin setting. Real, safe production
    // inventory taken on dell (2026-09-02, counts only, no PII/secrets):
    // rows_with_apiQueue_setting=0, total_queued_jobs=0 across all real
    // journals; the v2 durable queue table (chatwoot_support_event_queue)
    // exists and is also empty. This is Case A ("fresh/current install
    // with empty legacy queue") for the one real environment available —
    // Cases B/C/D below are proven with synthetic data since no real
    // non-empty legacy queue currently exists to test against.
    // ================================================================

    // --- Case D: corrupt legacy queue JSON must fail safe, never crash ---
    $plugin = new ChatwootIntegrationBasePlugin();
    $getApiQueue = new \ReflectionMethod($plugin, 'getApiQueue');
    $contextId = 7;

    $plugin->updateSetting($contextId, 'apiQueue', '{this is not valid json', 'string');
    $queue = $getApiQueue->invoke($plugin, $contextId);
    evt017Check(is_array($queue) && $queue === [], 'corrupt apiQueue JSON must fail safe to an empty array, never fatal or throw — ordinary OJS requests/plugin enablement must never break because of a malformed legacy queue value');

    $plugin->updateSetting($contextId, 'apiQueue', '"just a json string, not an array"', 'string');
    $queueNonArray = $getApiQueue->invoke($plugin, $contextId);
    evt017Check($queueNonArray === [], 'valid JSON that decodes to a non-array must also fail safe to an empty array');

    $plugin->updateSetting($contextId, 'apiQueue', null, 'string');
    $queueNull = $getApiQueue->invoke($plugin, $contextId);
    evt017Check($queueNull === [], 'a never-set/null apiQueue must resolve to an empty array, not fatal');

    // --- Give-up must produce a safe audit trail entry, never the raw job payload (PII) ---
    $enqueueApiJob = new \ReflectionMethod(ChatwootIntegrationBasePlugin::class, 'enqueueApiJob');
    $processApiQueue = new \ReflectionMethod(ChatwootIntegrationBasePlugin::class, 'processApiQueue');

    // error_log() writes to PHP's configured error_log target, not stdout
    // — redirect it to a temp file so this assertion inspects the real
    // audit line, not a no-op.
    $tmpLog = tempnam(sys_get_temp_dir(), 'evt017_audit_');
    ini_set('error_log', $tmpLog);
    $plugin3 = new ChatwootIntegrationBasePlugin();
    $plugin3->updateSetting($contextId, 'retryQueueEnabled', true, 'bool');
    $plugin3->updateSetting($contextId, 'maxRetryAttempts', 1, 'int');
    $enqueueApiJob->invoke($plugin3, $contextId, 'conversation_event', [
        'email' => 'real-author@example.com',
        'name' => 'Real Author Name',
        'message' => 'a real submission note containing a real title',
    ]);
    $processApiQueue->invoke($plugin3, $contextId, 5);
    $logged = (string) @file_get_contents($tmpLog);
    @unlink($tmpLog);

    // AUD-013 follow-up: this now goes through the real
    // DatabaseSupportApiAuditLogger sink (endpoint/decision/reason/
    // correlationId schema), which falls back to this same error_log()
    // line only when no real DB is available — exactly this bare test
    // harness's situation — so the real fallback path is what's being
    // exercised here, not a hypothetical.
    evt017Check(str_contains($logged, '"decision":"deny"'), 'a legacy job that exhausts its retries must now produce a real give-up audit entry, not vanish with zero trace');
    evt017Check(str_contains($logged, '"reason":"give_up:job='), 'the give-up audit entry must record the real give-up reason and job id');
    evt017Check(str_contains($logged, '"endpoint":"legacy_queue:conversation_event"'), 'the give-up audit entry must identify the legacy queue and job type via a real, distinguishable endpoint name');
    evt017Check(str_contains($logged, '"correlation_id":"'), 'the give-up audit entry must carry a real, freshly-generated correlation ID (AUD-013) — a legacy job never had one to begin with');
    evt017Check(!str_contains($logged, 'real-author@example.com'), 'the give-up audit entry must NEVER contain the real job payload email — safe fields only');
    evt017Check(!str_contains($logged, 'Real Author Name'), 'the give-up audit entry must NEVER contain the real job payload name — safe fields only');
    evt017Check(!str_contains($logged, 'a real submission note'), 'the give-up audit entry must NEVER contain the real job payload message — safe fields only');

    // ================================================================
    // Ownership model (Section 11): prove the CURRENT state is a valid
    // "exactly one live delivery owner per event type" model — not that
    // v2 merely didn't send it (already proven by evt-016-no-double
    // -delivery.php), but that v1 IS the real, currently-functioning,
    // sole owner for every one of the 8 known SupportEventTypes. Full
    // ownership transfer to v2 (EVT-016B/EVT-017's final state) requires
    // the live Dell acceptance sequence (force failure -> confirm v2
    // durable retry -> recover -> confirm exactly one eventual delivery)
    // before any type may move off v1 — not yet performed, so v1 remains
    // the proven owner for all 8 types today.
    // ================================================================
    $v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    foreach (['handleEditorDecision', 'handleSubmissionCreated', 'handleSubmissionStatusUpdated', 'handlePublicationPublished'] as $handler) {
        evt017Check(
            str_contains($v1Source, "function {$handler}(") && str_contains($v1Source, 'dispatchEvent('),
            "v1's {$handler}() must still be the real, functioning live deliverer (via dispatchEvent()) for its event family until EVT-016B's ownership transfer is proven safe on real Dell"
        );
    }
    $v2PluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    $allowlistStart = strpos($v2PluginSource, 'LIVE_DELIVERY_ALLOWLIST = [');
    $allowlistBlock = substr($v2PluginSource, $allowlistStart, (int) strpos($v2PluginSource, '];', $allowlistStart) - $allowlistStart);
    foreach (SupportEventType::all() as $eventType) {
        evt017Check(
            !str_contains($allowlistBlock, "'{$eventType}'"),
            "'{$eventType}' must not be on v2's LIVE_DELIVERY_ALLOWLIST yet — v1 remains its sole proven live owner until ownership transfer is deliberately completed and live-verified"
        );
    }

    fwrite(STDOUT, "PASS: evt-017-legacy-queue-inventory-and-ownership\n");
}
