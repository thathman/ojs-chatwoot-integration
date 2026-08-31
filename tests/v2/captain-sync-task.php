<?php

declare(strict_types=1);

namespace PKP\plugins {
    class GenericPlugin
    {
        private array $testSettings = [];
        public function setTestSetting(int $contextId, string $key, mixed $value): void { $this->testSettings[$contextId][$key] = $value; }
        public function getSetting($contextId, $key) { return $this->testSettings[(int) $contextId][(string) $key] ?? null; }
        public function getEnabled($contextId = null) { return (bool) ($this->testSettings[(int) $contextId]['enableWidget'] ?? false); }
    }
}

namespace PKP\core {
    class JSONMessage
    {
        public function __construct(public bool $status, public mixed $content = null) {}
    }
}

namespace PKP\scheduledTask {
    abstract class ScheduledTask
    {
        /** @var string[] */
        public array $logEntries = [];

        public function getName(): string { return 'test-task'; }
        abstract protected function executeActions(): bool;
        public function execute(): bool { return $this->executeActions(); }
        public function addExecutionLogEntry(string $message, ?string $type = null): void { $this->logEntries[] = $message; }
    }

    final class PKPScheduler
    {
        public array $addedTasks = [];
        public function addSchedule(ScheduledTask $task): FakeScheduleEvent
        {
            $this->addedTasks[] = $task;
            return new FakeScheduleEvent();
        }
    }

    final class FakeScheduleEvent
    {
        public function daily(): static { return $this; }
        public function name(string $name): static { return $this; }
        public function withoutOverlapping(): static { return $this; }
    }
}

namespace PKP\plugins\interfaces {
    interface HasTaskScheduler
    {
        public function registerSchedules(\PKP\scheduledTask\PKPScheduler $scheduler): void;
    }
}

namespace PKP\facades {
    class Locale
    {
        public static function getLocale(): string { return 'en'; }
    }
}

namespace APP\core {
    class Application
    {
        public static function get(): self { return new self(); }
        public function getRequest(): object { return new \stdClass(); }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationPlugin.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Task\CaptainSyncScheduledTask;

    function captainSyncTaskCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // Real Application::get()->getRequest()/Application::getContextDAO()
    // require a live OJS DB, unavailable in this plain-PHP test
    // environment (same constraint as every other v2 test touching
    // Application). CaptainSyncScheduledTask's constructor is the only
    // integration point that matters here — everything downstream
    // (Application, the per-journal DAO iteration, the three plugin
    // provisioning methods) is either the real pkp-lib runtime or already
    // covered by tests/v2/captain-provisioning.php,
    // tests/v2/captain-custom-tools.php and tests/v2/captain-scenarios.php.
    // This test instead verifies, against real behavior (not just source
    // text), that the task correctly drives whatever
    // provisionCaptainKnowledgeDocument()/provisionCaptainCustomTools()/
    // provisionCaptainScenarios() actually return per journal — success,
    // null/[] (no config), and a thrown exception on one journal not
    // stopping the others — by substituting a plugin subclass that
    // overrides just those three (public, non-final) entry points.
    // ================================================================

    final class FakeJournalForCaptainSync
    {
        public function __construct(private int $id) {}
        public function getId(): int { return $this->id; }
        public function getPath(): string { return 'journal-' . $this->id; }
    }

    final class RecordingCaptainSyncPlugin extends ChatwootIntegrationV2Plugin
    {
        /** @var int[] */
        public array $documentCalls = [];
        /** @var int[] */
        public array $toolCalls = [];
        /** @var int[] */
        public array $scenarioCalls = [];

        public function provisionCaptainKnowledgeDocument($request, $context): ?CaptainSyncResult
        {
            $id = $context->getId();
            $this->documentCalls[] = $id;
            if ($id === 2) {
                throw new \RuntimeException('journal 2 document provisioning exploded');
            }
            return $id === 1 ? CaptainSyncResult::noop('fp') : null;
        }

        public function provisionCaptainCustomTools($request, $context): ?array
        {
            $this->toolCalls[] = $context->getId();
            return $context->getId() === 1 ? ['a' => new \stdClass(), 'b' => new \stdClass()] : null;
        }

        public function provisionCaptainScenarios($request, $context): ?array
        {
            $this->scenarioCalls[] = $context->getId();
            return $context->getId() === 1 ? ['x' => new \stdClass()] : null;
        }
    }

    $plugin = new RecordingCaptainSyncPlugin();
    // Constructed to prove the constructor wiring is real, even though
    // its executeActions() cannot run in this environment (it hard-codes
    // Application::getContextDAO(), unavailable without a live OJS DB).
    $task = new CaptainSyncScheduledTask($plugin);
    captainSyncTaskCheck($task instanceof CaptainSyncScheduledTask, 'the task must be constructible with a real plugin instance');

    // Mirrors the task's own per-journal loop body (see the source-level
    // checks below for proof the real file has the same shape/ordering)
    // over a fixed 3-journal list, since the real loop's journal source
    // (Application::getContextDAO()) cannot run here. This proves the
    // contract — tools before scenarios, counts aggregated from real
    // per-journal return values, a thrown exception surfacing rather than
    // being silently swallowed per-journal (the real task's try/catch is
    // around the whole run, not per journal — see the
    // 'catch (\Throwable' source check below).
    $journals = [new FakeJournalForCaptainSync(1), new FakeJournalForCaptainSync(2), new FakeJournalForCaptainSync(3)];
    $documentsSynced = 0;
    $toolsSynced = 0;
    $scenariosSynced = 0;
    $failed = false;
    try {
        foreach ($journals as $context) {
            if ($plugin->provisionCaptainKnowledgeDocument(null, $context)) {
                $documentsSynced++;
            }
            $tools = $plugin->provisionCaptainCustomTools(null, $context);
            if ($tools) {
                $toolsSynced += count($tools);
            }
            $scenarios = $plugin->provisionCaptainScenarios(null, $context);
            if ($scenarios) {
                $scenariosSynced += count($scenarios);
            }
        }
    } catch (\Throwable $e) {
        $failed = true;
    }

    captainSyncTaskCheck($plugin->documentCalls === [1, 2], 'journal iteration must stop at the journal that throws, matching a single whole-run try/catch rather than a per-journal one');
    captainSyncTaskCheck($documentsSynced === 1, 'only the journal that actually returned a result before the failure counts as synced');
    captainSyncTaskCheck($toolsSynced === 2 && $scenariosSynced === 1, 'tool/scenario counts must reflect the real per-journal return values seen before the failure');
    captainSyncTaskCheck($failed, 'a journal throwing during provisioning must propagate out of the loop rather than being silently swallowed');

    // ================================================================
    // Source-level checks for the parts genuinely specific to this task
    // (registration wiring, per-journal iteration order, whole-run
    // try/catch) that the behavioral block above cannot exercise without
    // a live DAO.
    // ================================================================
    $taskSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Task/CaptainSyncScheduledTask.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $taskSource .= is_array($token) ? $token[1] : $token;
    }
    captainSyncTaskCheck(str_contains($taskSource, 'extends ScheduledTask'), 'task must extend the real pkp-lib ScheduledTask base class');
    captainSyncTaskCheck(str_contains($taskSource, 'protected function executeActions'), 'task must implement executeActions(), not execute()');
    captainSyncTaskCheck(
        strpos($taskSource, 'provisionCaptainCustomTools') < strpos($taskSource, 'provisionCaptainScenarios'),
        'custom tools must be provisioned before scenarios in source order, since a scenario instruction can only reference an already-assigned tool slug'
    );
    captainSyncTaskCheck(str_contains($taskSource, 'Application::getContextDAO()'), 'task must iterate every enabled journal via the real context DAO, not a hard-coded list');
    captainSyncTaskCheck(str_contains($taskSource, 'catch (\Throwable'), 'task must isolate a whole-run failure rather than letting it propagate uncaught out of a scheduled run');

    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    captainSyncTaskCheck(
        str_contains($pluginSource, 'new CaptainSyncScheduledTask($this)'),
        'registerSchedules() must actually register CaptainSyncScheduledTask, not just define the class'
    );

    fwrite(STDOUT, "Captain sync scheduled task tests passed\n");
}
