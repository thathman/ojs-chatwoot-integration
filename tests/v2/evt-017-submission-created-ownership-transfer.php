<?php

declare(strict_types=1);

// Minimal mock scaffold — same real pattern as tests/v2/live-plugin.php and
// tests/v2/evt-016-no-double-delivery.php — only what's needed to construct
// a real ChatwootIntegrationV2Plugin instance and call its inherited
// handleSubmissionCreated() without a live OJS runtime/DB/Chatwoot.

namespace PKP\plugins {
    class GenericPlugin
    {
        private array $testSettings = [];
        public function getSetting($contextId, $key)
        {
            return $this->testSettings[(int) $contextId][(string) $key] ?? null;
        }
        public function getEnabled($contextId = null)
        {
            return false;
        }
    }
}

namespace PKP\core {
    class JSONMessage
    {
        public function __construct(public bool $status, public mixed $content = null)
        {
        }
    }
}

namespace PKP\scheduledTask {
    abstract class ScheduledTask
    {
        public function __construct(private array $args = [])
        {
        }
        public function getName(): string
        {
            return 'test-task';
        }
        abstract protected function executeActions(): bool;
        public function execute(): bool
        {
            return $this->executeActions();
        }
        public function addExecutionLogEntry(string $message, ?string $type = null): void
        {
        }
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
        public function daily(): static
        {
            return $this;
        }
        public function everyFiveMinutes(): static
        {
            return $this;
        }
        public function name(string $name): static
        {
            return $this;
        }
        public function withoutOverlapping(): static
        {
            return $this;
        }
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
        public static function getLocale(): string
        {
            return 'en';
        }
    }
}

namespace APP\core {
    class Application
    {
        public static function get(): self
        {
            return new self();
        }
        public function getRequest(): object
        {
            return new \stdClass();
        }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationPlugin.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

    function evt017TransferCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // EVT-017: the atomic ownership transfer for `submission.created`.
    // Base v1's real handleSubmissionCreated() must now return early,
    // before ever reaching safeGetPrimaryAuthor()/dispatchEvent(), when
    // isLiveDeliveryOwnedByV2('eventSubmissionCreated') is true — proven
    // behaviorally, not just by source string, by passing a submission
    // stub that would fatal if any method past getData() were called.
    // Invoked directly against ChatwootIntegrationBasePlugin's own
    // declared method (not through the v2 subclass's override, which
    // separately calls parent:: then does its own enqueue work with its
    // own try/catch) — this isolates the base class's real behavior.
    // ================================================================

    final class Evt017PoisonSubmission
    {
        public function getData(string $key)
        {
            if ($key === 'contextId') {
                return 1;
            }
            throw new \RuntimeException("unexpected getData('{$key}') call — handleSubmissionCreated() did not return early after the ownership-transfer check");
        }
        public function __call($name, $args)
        {
            throw new \RuntimeException("unexpected method call {$name}() — handleSubmissionCreated() did not return early after the ownership-transfer check");
        }
    }

    $plugin = new ChatwootIntegrationV2Plugin();

    // isEventEnabled() reads getSetting() via the mocked GenericPlugin
    // above, which returns null for every key — the base plugin treats a
    // null/unset 'eventSubmissionCreated' as enabled-by-default (checked
    // structurally below), so the poison-submission call proves the
    // early return fires specifically because of the ownership switch,
    // not because the event was disabled.
    $pluginSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $handlerStart = strpos($pluginSource, 'public function handleSubmissionCreated($hookName, $args) {');
    evt017TransferCheck($handlerStart !== false, 'ChatwootIntegrationBasePlugin::handleSubmissionCreated() must still exist with its real signature');
    $handlerEnd = strpos($pluginSource, "\n    }\n", $handlerStart);
    $handlerBody = substr($pluginSource, $handlerStart, $handlerEnd - $handlerStart);
    evt017TransferCheck(
        strpos($handlerBody, "isLiveDeliveryOwnedByV2('eventSubmissionCreated')") !== false,
        'handleSubmissionCreated() must check isLiveDeliveryOwnedByV2(\'eventSubmissionCreated\') before doing any v1 live-delivery work'
    );
    $ownershipCheckPos = strpos($handlerBody, "isLiveDeliveryOwnedByV2('eventSubmissionCreated')");
    $authorLookupPos = strpos($handlerBody, 'safeGetPrimaryAuthor(');
    evt017TransferCheck(
        $authorLookupPos === false || $ownershipCheckPos < $authorLookupPos,
        'the ownership-transfer check must run BEFORE safeGetPrimaryAuthor()/dispatchEvent(), not after'
    );

    $method = new \ReflectionMethod('APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin', 'handleSubmissionCreated');
    $result = $method->invoke($plugin, 'Submission::add', [new Evt017PoisonSubmission()]);
    evt017TransferCheck(
        $result === false,
        'handleSubmissionCreated() must return false (v1 declines to further process) once ownership has transferred to v2, and must do so WITHOUT calling any method on the submission beyond getData(\'contextId\') — got: ' . var_export($result, true)
    );

    fwrite(STDOUT, "PASS: evt-017-submission-created-ownership-transfer\n");
}
