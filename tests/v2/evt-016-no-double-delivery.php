<?php

declare(strict_types=1);

// Minimal mock scaffold matching tests/v2/live-plugin.php's real
// pattern — only what's needed to construct a real
// ChatwootIntegrationV2Plugin instance and reflect into its private
// v2DeliverQueuedEventRow() without a live OJS runtime/DB/Chatwoot.

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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

    function evt016Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // EVT-016 (CRITICAL, hostile completion audit): v1's own
    // dispatchEvent()/sendChatwootEvent() already delivers every real
    // submission.created/decision_recorded/*_requested/accepted/rejected/
    // publication.* event synchronously and unconditionally the instant
    // its real OJS hook fires — the SAME occurrence
    // handleSubmissionCreated()/handleEditorDecision()/
    // handleSubmissionStatusUpdated()/handlePublicationPublished() (v2
    // overrides) also enqueue into the v2 durable queue via
    // v2EnqueueEvent(). Before this fix, deliverQueuedSupportEvents()'s
    // scheduled consumer would then ALSO post the same event to the live
    // Chatwoot API — a real, active double-delivery defect confirmed by
    // reading the source, not theoretical.
    //
    // This test proves, by actually calling the real private
    // v2DeliverQueuedEventRow() via reflection (not just asserting source
    // strings), that every one of the 8 known SupportEventType values is
    // currently blocked from live re-delivery — it must return true
    // (delivered/no-op) WITHOUT ever needing a real $bridge/DB/Chatwoot
    // call, proven by passing null for $bridge: if the guard didn't
    // short-circuit before touching $bridge, this would fatal instead of
    // returning true.
    // ================================================================

    $plugin = new ChatwootIntegrationV2Plugin();
    $method = new \ReflectionMethod($plugin, 'v2DeliverQueuedEventRow');

    foreach (SupportEventType::all() as $eventType) {
        $row = [
            'event_type' => $eventType,
            'delivery_mode' => EventDeliveryMode::PRIVATE_NOTE, // deliberately NOT audit_only — proves the type-allowlist gate, not just the mode gate
            'context_id' => 7,
            'resource_id' => 101,
        ];

        $result = $method->invoke($plugin, null, $row);
        evt016Check(
            $result === true,
            "event type '{$eventType}' must be blocked from live re-delivery (return true / no-op) until its own EVT-0xx task confirms v1 no longer also delivers it — got a non-true result, meaning it attempted to actually call the (null) bridge and would have fataled or, worse in production, double-posted to Chatwoot"
        );
    }

    // The allowlist itself must currently be empty — the moment any type
    // is added, it must be a deliberate, reviewed decision (a real code
    // change to this constant), never silently reintroduced.
    $pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    $allowlistStart = strpos($pluginSource, 'LIVE_DELIVERY_ALLOWLIST = [');
    evt016Check($allowlistStart !== false, 'the plugin must declare a real LIVE_DELIVERY_ALLOWLIST constant');
    $allowlistBlock = substr($pluginSource, $allowlistStart, (int) strpos($pluginSource, '];', $allowlistStart) - $allowlistStart);
    evt016Check(
        trim(str_replace(['LIVE_DELIVERY_ALLOWLIST = [', "\n", ' '], '', $allowlistBlock)) === '',
        'LIVE_DELIVERY_ALLOWLIST must currently be empty — no event type has yet had its v1 duplication deliberately retired'
    );

    fwrite(STDOUT, "PASS: evt-016-no-double-delivery\n");
}
