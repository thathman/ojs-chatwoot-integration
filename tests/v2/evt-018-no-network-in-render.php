<?php

declare(strict_types=1);

// ================================================================
// EVT-018 (CRITICAL, hostile completion audit): addChatwootWidget()
// fires on every TemplateManager::display/fetch call site-wide,
// including anonymous frontend pages, admin pages, and component/AJAX
// renders unrelated to Chatwoot at all. It used to unconditionally call
// processApiQueue() (a real network/queue side effect: it can execute
// queued Chatwoot API jobs) on every single one of those renders,
// before even checking whether the widget itself is enabled/configured.
//
// Real-runtime interception of the network call is out of scope for
// this plain-PHP test environment (no live OJS/DB/Chatwoot); this test
// instead proves, from the real source tree, that the render path no
// longer contains that call at all, and that a real, separate,
// scheduler-only consumer now exists in its place.
// ================================================================

function evt018Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");

$widgetStart = strpos($v1Source, 'public function addChatwootWidget(');
evt018Check($widgetStart !== false, 'addChatwootWidget() must exist');
$widgetEnd = strpos($v1Source, "\n    public function ", $widgetStart + 10);
$widgetBody = substr($v1Source, $widgetStart, ($widgetEnd !== false ? $widgetEnd : strlen($v1Source)) - $widgetStart);

evt018Check(
    !str_contains($widgetBody, 'processApiQueue('),
    'addChatwootWidget() must never call processApiQueue() (or anything else that performs Chatwoot delivery) — it fires on every page render site-wide, and queue/network work belongs to scheduler/worker paths only'
);

evt018Check(
    str_contains($v1Source, 'public function processQueuedApiJobsForContext('),
    'the plugin must expose a real public entry point for the scheduled consumer to call, since processApiQueue() itself stays private'
);

$taskSource = (string) file_get_contents("{$root}/classes/v2/Task/ProcessLegacyRetryQueueScheduledTask.php");
evt018Check(str_contains($taskSource, 'extends ScheduledTask'), 'a real ScheduledTask subclass must exist to drive the legacy retry queue');
evt018Check(str_contains($taskSource, 'processQueuedApiJobsForContext'), 'the scheduled task must actually call the real public entry point');
evt018Check(str_contains($taskSource, 'getContextDAO()->getAll(true)'), 'the scheduled task must process every real journal, not a single hard-coded context');
evt018Check(str_contains($taskSource, 'parent::__construct()'), 'must call parent::__construct() (TST-021) or every real invocation fatals on the typed $executionLogFile property');

$v2PluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
evt018Check(
    str_contains($v2PluginSource, 'new ProcessLegacyRetryQueueScheduledTask($this)'),
    'registerSchedules() must actually register the new scheduled task with the real OJS scheduler, not just define the class'
);

// Real event-occurrence-triggered and explicit-admin-action drains remain
// legitimate (bounded by real activity, not by arbitrary page views) and
// are deliberately untouched by this fix.
evt018Check(str_contains($v1Source, 'private function dispatchEvent(int $contextId, array $payload, bool $forceQueue = false): bool {'), 'dispatchEvent() must still exist unchanged — its own opportunistic drain on a genuine new event is not the EVT-018 defect');
evt018Check(str_contains($v1Source, 'public function syncEmailTemplates($request)'), 'syncEmailTemplates() must still exist unchanged — its own opportunistic drain on an explicit admin action is not the EVT-018 defect');

fwrite(STDOUT, "PASS: evt-018-no-network-in-render\n");
