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

        // All 8 known event types are now transferred/allowlisted.
        $threw = false;
        try {
            $method->invoke($plugin, null, $row);
        } catch (\Throwable $e) {
            $threw = true;
        }
        evt016Check(
            $threw,
            "event type '{$eventType}' must now actually attempt live delivery (reach the null \$bridge and fatal), proving it passed the allowlist gate instead of staying a silent no-op"
        );
    }

    // The allowlist must currently contain exactly all 8 known types —
    // the moment any further type is added (a 9th event kind is
    // invented), it must be a deliberate, reviewed decision (a real code
    // change to this constant), never silently reintroduced.
    $pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
    $allowlistStart = strpos($pluginSource, 'LIVE_DELIVERY_ALLOWLIST = [');
    evt016Check($allowlistStart !== false, 'the plugin must declare a real LIVE_DELIVERY_ALLOWLIST constant');
    $allowlistBlock = substr($pluginSource, $allowlistStart, (int) strpos($pluginSource, '];', $allowlistStart) - $allowlistStart);
    $allowlistNormalized = trim(str_replace(['LIVE_DELIVERY_ALLOWLIST = [', "\n", ' ', ','], ['', '', '', ''], $allowlistBlock));
    evt016Check(
        $allowlistNormalized === 'SupportEventType::SUBMISSION_CREATEDSupportEventType::SUBMISSION_REVIEW_SUBMITTEDSupportEventType::SUBMISSION_DECISION_RECORDEDSupportEventType::SUBMISSION_REVISION_REQUESTEDSupportEventType::SUBMISSION_ACCEPTEDSupportEventType::SUBMISSION_REJECTEDSupportEventType::PUBLICATION_SCHEDULEDSupportEventType::PUBLICATION_PUBLISHED',
        'LIVE_DELIVERY_ALLOWLIST must currently contain exactly all 8 known SupportEventTypes and nothing else, got: ' . $allowlistNormalized
    );

    // Ownership-switch parity: the base plugin's per-type gate must agree
    // with the allowlist exactly — all 8 real event-setting keys
    // transferred, none left v1-owned.
    $ownershipMethod = new \ReflectionMethod($plugin, 'isLiveDeliveryOwnedByV2');
    foreach (['eventSubmissionCreated', 'eventDecisionRecorded', 'eventRevisionRequested', 'eventAccepted', 'eventRejected', 'eventPublicationScheduled', 'eventPublicationPublished'] as $transferred) {
        evt016Check(
            $ownershipMethod->invoke($plugin, $transferred) === true,
            "isLiveDeliveryOwnedByV2() must return true for '{$transferred}' now that it has been transferred"
        );
    }

    // ================================================================
    // EVT-020: handleEditorDecision()'s real ownership-transfer guard,
    // structurally verified (a behavioral poison-stub test like EVT-017's
    // is not feasible here — the real method calls Repo::submission()->get()
    // before this guard is even reachable, which requires a live OJS DB
    // this environment does not have). The guard must exist, must run
    // strictly before dispatchEvent(), and — a real regression this exact
    // gap caused once, live — must key on the SAME specific event type
    // v2's DecisionRecordedEventAdapter::mapDecisionEventType() would
    // actually enqueue this decision code as ($eventKey when one exists —
    // eventAccepted/eventRejected/eventRevisionRequested — falling back
    // to the bare 'eventDecisionRecorded' only when $eventKey is null),
    // never the bare key unconditionally. Keying on the bare key
    // unconditionally silently blocked v1's delivery for every decision
    // type (including still-v1-owned ones like a real "Accept
    // Submission"), not only the one type actually transferred.
    // ================================================================
    $basePluginSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $decisionHandlerStart = strpos($basePluginSource, 'public function handleEditorDecision($hookName, $args) {');
    evt016Check($decisionHandlerStart !== false, 'ChatwootIntegrationBasePlugin::handleEditorDecision() must still exist with its real signature');
    $decisionHandlerEnd = strpos($basePluginSource, "\n    }\n", $decisionHandlerStart);
    $decisionHandlerBody = substr($basePluginSource, $decisionHandlerStart, $decisionHandlerEnd - $decisionHandlerStart);
    evt016Check(
        str_contains($decisionHandlerBody, "isLiveDeliveryOwnedByV2(\$eventKey ?? 'eventDecisionRecorded')"),
        'handleEditorDecision() must check isLiveDeliveryOwnedByV2($eventKey ?? \'eventDecisionRecorded\') — the SAME specific per-decision-code key v2\'s adapter would enqueue as — never the bare \'eventDecisionRecorded\' key unconditionally, which would wrongly block v1 delivery for still-v1-owned decision types too'
    );
    evt016Check(
        !preg_match('/isLiveDeliveryOwnedByV2\(\'eventDecisionRecorded\'\)/', $decisionHandlerBody),
        'the ownership check must never be a bare isLiveDeliveryOwnedByV2(\'eventDecisionRecorded\') call — that unconditionally blocks v1 for every decision code, not only the one type actually transferred'
    );
    $decisionOwnershipCheckPos = strpos($decisionHandlerBody, "isLiveDeliveryOwnedByV2(\$eventKey ?? 'eventDecisionRecorded')");
    $decisionDispatchPos = strpos($decisionHandlerBody, 'dispatchEvent(');
    $decisionEventKeyPos = strpos($decisionHandlerBody, '$eventKey = $this->mapDecisionEventKey(');
    evt016Check(
        $decisionDispatchPos === false || $decisionOwnershipCheckPos < $decisionDispatchPos,
        'the ownership-transfer check must run BEFORE dispatchEvent(), not after — otherwise a real decision could still double-deliver'
    );
    evt016Check(
        $decisionEventKeyPos !== false && $decisionEventKeyPos < $decisionOwnershipCheckPos,
        'the ownership-transfer check must run AFTER $eventKey is computed, so it can key on the real specific event type, not before'
    );

    // ================================================================
    // EVT-020 (CRITICAL, found by post-merge security review after
    // #186 transferred eventAccepted/eventRejected): those two setting
    // keys are shared between handleEditorDecision() (a decision) and
    // handleSubmissionStatusUpdated() (a status change) — two separate
    // real hooks that can each independently produce the exact same
    // SupportEventType (SUBMISSION_ACCEPTED/SUBMISSION_REJECTED) via
    // two different adapters (DecisionRecordedEventAdapter/
    // SubmissionStatusChangedEventAdapter). Transferring the setting
    // key without gating BOTH real call sites left
    // handleSubmissionStatusUpdated() still unconditionally calling
    // dispatchEvent() for a real status change to Published/Declined —
    // meaning v1 (here) and v2 (the queue's own now-allowlisted type)
    // would both deliver live for the same real occurrence. Every real
    // v1 hook that can produce a transferred event-setting key must
    // carry this same guard, not just the first one a given transfer
    // happened to touch.
    // ================================================================
    foreach ([
        ['handleSubmissionStatusUpdated($hookName, $args) {', 'eventKey = $newStatus === PKPSubmission::STATUS_DECLINED'],
        ['handlePublicationPublished($hookName, $args) {', 'eventKey = $status === PKPSubmission::STATUS_SCHEDULED'],
    ] as [$signature, $eventKeyNeedle]) {
        $handlerStart = strpos($basePluginSource, "public function {$signature}");
        evt016Check($handlerStart !== false, "ChatwootIntegrationBasePlugin::{$signature} must still exist with its real signature");
        $handlerEnd = strpos($basePluginSource, "\n    }\n", $handlerStart);
        $handlerBody = substr($basePluginSource, $handlerStart, $handlerEnd - $handlerStart);

        evt016Check(str_contains($handlerBody, $eventKeyNeedle), "{$signature} must still compute \$eventKey the same real way");
        evt016Check(
            str_contains($handlerBody, 'isLiveDeliveryOwnedByV2($eventKey)'),
            "{$signature} must check isLiveDeliveryOwnedByV2(\$eventKey) before dispatchEvent() — the same ownership-transfer guard handleEditorDecision() has, since this hook can produce the same transferable event-setting keys via a completely separate real occurrence"
        );
        $ownershipCheckPos = strpos($handlerBody, 'isLiveDeliveryOwnedByV2($eventKey)');
        $dispatchPos = strpos($handlerBody, '$this->dispatchEvent(');
        $eventKeyPos = strpos($handlerBody, $eventKeyNeedle);
        evt016Check(
            $dispatchPos === false || $ownershipCheckPos < $dispatchPos,
            "{$signature}'s ownership-transfer check must run BEFORE dispatchEvent(), not after"
        );
        evt016Check(
            $eventKeyPos !== false && $eventKeyPos < $ownershipCheckPos,
            "{$signature}'s ownership-transfer check must run AFTER \$eventKey is computed"
        );
    }

    fwrite(STDOUT, "PASS: evt-016-no-double-delivery\n");
}
