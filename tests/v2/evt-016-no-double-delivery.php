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
    // strings), the current real per-type ownership split: every type
    // still owned by v1 must return true (delivered/no-op) WITHOUT ever
    // needing a real $bridge/DB/Chatwoot call, proven by passing null for
    // $bridge — if the guard didn't short-circuit before touching
    // $bridge, this would fatal instead of returning true. EVT-017
    // transferred SUBMISSION_CREATED to v2: that one type must now
    // actually ATTEMPT live delivery (fatal on the null bridge, proving
    // it passed the allowlist gate and reached real bridge/API code),
    // while every other type remains blocked exactly as before.
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

        if (in_array($eventType, [SupportEventType::SUBMISSION_CREATED, SupportEventType::SUBMISSION_REVIEW_SUBMITTED], true)) {
            $threw = false;
            try {
                $method->invoke($plugin, null, $row);
            } catch (\Throwable $e) {
                $threw = true;
            }
            evt016Check(
                $threw,
                "event type '{$eventType}' is one of the two transferred/allowlisted types — it must now actually attempt live delivery (reach the null \$bridge and fatal), proving it passed the allowlist gate instead of staying a silent no-op"
            );
            continue;
        }

        $result = $method->invoke($plugin, null, $row);
        evt016Check(
            $result === true,
            "event type '{$eventType}' must be blocked from live re-delivery (return true / no-op) until its own EVT-0xx task confirms v1 no longer also delivers it — got a non-true result, meaning it attempted to actually call the (null) bridge and would have fataled or, worse in production, double-posted to Chatwoot"
        );
    }

    // The allowlist must currently contain exactly these two types — the
    // moment any further type is added, it must be a deliberate,
    // reviewed decision (a real code change to this constant), never
    // silently reintroduced.
    $pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    $allowlistStart = strpos($pluginSource, 'LIVE_DELIVERY_ALLOWLIST = [');
    evt016Check($allowlistStart !== false, 'the plugin must declare a real LIVE_DELIVERY_ALLOWLIST constant');
    $allowlistBlock = substr($pluginSource, $allowlistStart, (int) strpos($pluginSource, '];', $allowlistStart) - $allowlistStart);
    $allowlistNormalized = trim(str_replace(['LIVE_DELIVERY_ALLOWLIST = [', "\n", ' ', ','], ['', '', '', ''], $allowlistBlock));
    evt016Check(
        $allowlistNormalized === 'SupportEventType::SUBMISSION_CREATEDSupportEventType::SUBMISSION_REVIEW_SUBMITTED',
        'LIVE_DELIVERY_ALLOWLIST must currently contain exactly SUBMISSION_CREATED (EVT-017) and SUBMISSION_REVIEW_SUBMITTED (EVT-020) and nothing else, got: ' . $allowlistNormalized
    );

    // Ownership-switch parity: the base plugin's per-type gate must agree
    // with the allowlist exactly — SUBMISSION_CREATED transferred, every
    // other real event-setting key still v1-owned.
    $ownershipMethod = new \ReflectionMethod($plugin, 'isLiveDeliveryOwnedByV2');
    evt016Check(
        $ownershipMethod->invoke($plugin, 'eventSubmissionCreated') === true,
        'isLiveDeliveryOwnedByV2() must return true for eventSubmissionCreated now that EVT-017 transferred it'
    );
    foreach (['eventRevisionRequested', 'eventAccepted', 'eventRejected', 'eventDecisionRecorded', 'eventPublicationScheduled', 'eventPublicationPublished'] as $stillV1Owned) {
        evt016Check(
            $ownershipMethod->invoke($plugin, $stillV1Owned) === false,
            "isLiveDeliveryOwnedByV2() must still return false for '{$stillV1Owned}' — only eventSubmissionCreated has been transferred so far"
        );
    }

    fwrite(STDOUT, "PASS: evt-016-no-double-delivery\n");
}
