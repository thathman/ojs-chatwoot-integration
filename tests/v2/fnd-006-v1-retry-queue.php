<?php

declare(strict_types=1);

namespace PKP\plugins {
    /**
     * Real getSetting/updateSetting semantics backed by an in-memory array,
     * matching how PKP\plugins\Plugin actually persists per-context plugin
     * settings — close enough for ChatwootIntegrationBasePlugin's own
     * getEffectiveSetting()/getSetting()/updateSetting() calls, which are
     * the only base-plugin surface FND-006's real retry-queue code path
     * (dispatchEvent -> sendChatwootEvent/enqueueApiJob/processApiQueue)
     * touches.
     */
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
    require_once $root . '/classes/v2/bootstrap.php'; // real production always loads this first (index.php) before any plugin class runs; EVT-017's give-up audit logging now needs it too
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;

    function fnd006RetryQueueCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * FND-006: baseline regression coverage for v1's own event-sync/
     * retry-queue behavior — the real path `handlePublicationPublished()`/
     * `handleSubmissionCreated()`/`handleEditorDecision()`/
     * `handleSubmissionStatusUpdated()` all funnel through
     * (`dispatchEvent()` -> `sendChatwootEvent()`/`enqueueApiJob()`/
     * `processApiQueue()`), never previously covered by any test.
     *
     * Scoping decision, same character as TST-002/003: `ChatwootApiService`
     * cannot be instantiated in this environment (its constructor builds a
     * real `GuzzleHttp\Client` immediately; no Composer/vendor tree here).
     * `sendChatwootEvent()` only reaches that constructor once past an
     * early guard (`$baseUrl === '' || $apiToken === '' || empty(...email)`)
     * — so this test exercises the REAL, unmodified `dispatchEvent()` via
     * reflection with the connection intentionally left unconfigured,
     * which makes `sendChatwootEvent()` return false *before* ever
     * touching `ChatwootApiService`. That real, deterministic failure path
     * is enough to prove every real retry-queue bookkeeping guarantee
     * end-to-end: enqueue-as-real-JSON, exponential backoff timing,
     * eventual give-up, and per-call processing limits. A real "success"
     * path additionally exercising `ChatwootApiService` itself would need
     * the same real-HTTP-server technique TST-003 already established for
     * that class specifically — a follow-up, not duplicated here.
     */

    $plugin = new ChatwootIntegrationBasePlugin();
    $contextId = 7;

    $dispatchEvent = new \ReflectionMethod($plugin, 'dispatchEvent');
    $getApiQueue = new \ReflectionMethod($plugin, 'getApiQueue');
    $processApiQueue = new \ReflectionMethod($plugin, 'processApiQueue');

    // Real settings: connection deliberately left blank (chatwootBaseUrl/
    // chatwootApiAccessToken unset) so sendChatwootEvent() fails before
    // ever reaching ChatwootApiService; retry queue enabled with a real,
    // small maxRetryAttempts so give-up is reachable in this test.
    $plugin->updateSetting($contextId, 'retryQueueEnabled', true, 'bool');
    $plugin->updateSetting($contextId, 'maxRetryAttempts', 3, 'int');

    // --- A failed dispatch must be queued as real, real JSON-round-tripped state ---
    $result = $dispatchEvent->invoke($plugin, $contextId, ['email' => 'author@example.com', 'message' => 'Submission created'], false);
    fnd006RetryQueueCheck($result === true, 'a failed send with the retry queue enabled must still report success (queued for later), matching v1\'s real always-accept contract');

    $queue = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck(count($queue) === 1, 'exactly one real job must be enqueued after one failed dispatch');
    fnd006RetryQueueCheck($queue[0]['type'] === 'conversation_event', 'the real enqueued job must be typed conversation_event, matching what executeApiJob() dispatches on');
    fnd006RetryQueueCheck($queue[0]['payload']['email'] === 'author@example.com', 'the real payload must be preserved unchanged through the enqueue/JSON round trip');
    fnd006RetryQueueCheck((int) $queue[0]['attempts'] === 0, 'a freshly-queued job must start at zero real attempts');

    $rawSetting = $plugin->getSetting($contextId, 'apiQueue');
    fnd006RetryQueueCheck(is_string($rawSetting) && json_decode($rawSetting, true) !== null, 'the real persisted apiQueue setting must be genuine, parseable JSON, not a PHP-serialized or opaque blob');

    // --- A freshly-enqueued job (real runAfter = time() at enqueue time) is immediately eligible; processing it once must apply real exponential backoff before it's eligible again ---
    $processApiQueue->invoke($plugin, $contextId, 5);
    $queueAfterAttempt1 = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck(count($queueAfterAttempt1) === 1, 'a job that failed again but has not hit maxRetryAttempts must remain queued, not silently dropped');
    fnd006RetryQueueCheck((int) $queueAfterAttempt1[0]['attempts'] === 1, 'a real failed retry must increment the real attempt counter by exactly one');
    fnd006RetryQueueCheck((int) $queueAfterAttempt1[0]['runAfter'] > time(), 'a retried-and-failed job must have a real future runAfter (exponential backoff), never immediately eligible again');

    // --- Processing again immediately (before the real backoff window elapses) must skip it untouched ---
    $processApiQueue->invoke($plugin, $contextId, 5);
    $queueStillBackedOff = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck((int) $queueStillBackedOff[0]['attempts'] === 1, 'processApiQueue must never touch a job whose real backoff window has not elapsed yet, even when called again immediately');

    // --- Force the backoff window elapsed, and drive the job through the rest of the real backoff sequence to real give-up ---
    $forceDue = function () use ($plugin, $contextId, $getApiQueue): void {
        $ref = new \ReflectionMethod($plugin, 'saveApiQueue');
        $q = $getApiQueue->invoke($plugin, $contextId);
        $q[0]['runAfter'] = time() - 1;
        $ref->invoke($plugin, $contextId, $q);
    };

    $forceDue();
    $processApiQueue->invoke($plugin, $contextId, 5);
    $queueAfterAttempt2 = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck((int) $queueAfterAttempt2[0]['attempts'] === 2, 'a second real retry must increment attempts to exactly two');

    $forceDue();
    $processApiQueue->invoke($plugin, $contextId, 5);
    $queueAfterGiveUp = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck($queueAfterGiveUp === [], 'a job that has now failed maxRetryAttempts (3) real times must be dropped from the queue for good — a queued item really does eventually give up, never retried forever');

    // --- The limit parameter must cap real per-call processing ---
    // Seeded directly via the real enqueueApiJob() (not dispatchEvent()):
    // HAR-012 removed dispatchEvent()'s own opportunistic queue drain
    // (it used to unconditionally call processApiQueue($contextId, 4)
    // on every call) — ProcessLegacyRetryQueueScheduledTask is now the
    // sole reliable drain path, so seeding via enqueueApiJob() directly
    // keeps this assertion isolated to the $limit parameter alone.
    $enqueueApiJob = new \ReflectionMethod($plugin, 'enqueueApiJob');
    $plugin->updateSetting($contextId, 'apiQueue', '', 'string');
    $enqueueApiJob->invoke($plugin, $contextId, 'conversation_event', ['email' => 'a@example.com']);
    $enqueueApiJob->invoke($plugin, $contextId, 'conversation_event', ['email' => 'b@example.com']);
    $enqueueApiJob->invoke($plugin, $contextId, 'conversation_event', ['email' => 'c@example.com']);
    $threeJobQueue = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck(count($threeJobQueue) === 3, 'three real enqueueApiJob() calls must persist three distinct jobs');

    $processApiQueue->invoke($plugin, $contextId, 2);
    $queueAfterLimitedProcess = $getApiQueue->invoke($plugin, $contextId);
    fnd006RetryQueueCheck(count($queueAfterLimitedProcess) === 3, 'a real limit=2 call must still leave all 3 jobs queued (each retried, none given up yet after one real attempt) — proving the limit genuinely caps how many jobs one call touches, not how many remain queued');
    $attemptedCount = count(array_filter($queueAfterLimitedProcess, fn (array $j): bool => (int) $j['attempts'] === 1));
    fnd006RetryQueueCheck($attemptedCount === 2, 'exactly 2 of the 3 real jobs must have been attempted this call, matching the real limit parameter — the 3rd is real evidence the cap was enforced, not coincidental');

    fwrite(STDOUT, "FND-006 v1 retry-queue regression tests passed\n");
}
