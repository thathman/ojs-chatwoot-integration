<?php

declare(strict_types=1);

// ================================================================
// TST-021: real acceptance testing on ojs-demo.airixmedia.com found that
// the built-in OJS web-based task scheduler ([schedule] task_runner=On,
// task_runner_interval=60 — the real config on this instance) fataled on
// every request once 60 seconds had elapsed, with:
//
//   Typed property PKP\scheduledTask\ScheduledTask::$executionLogFile
//   must not be accessed before initialization
//
// Root cause: DeliverQueuedSupportEventsTask and CaptainSyncScheduledTask
// both declare their own __construct() (to inject the plugin instance)
// without ever calling parent::__construct() — the real pkp-lib
// ScheduledTask::__construct() is what initializes $executionLogFile
// (via PrivateFileManager). Skipping it left the property permanently
// uninitialized, so the very first addExecutionLogEntry() call inside
// executeActions() fataled — meaning neither task has ever completed a
// real run via the actual OJS scheduler in this project's history.
//
// This was invisible to every existing unit test (captain-sync-task.php,
// purge-expired-support-data-task.php) because their fake
// `PKP\scheduledTask\ScheduledTask` mock has no constructor and no typed
// $executionLogFile property at all — calling parent::__construct() or
// not makes zero difference against the mock. Only a real pkp-lib class
// (or, as here, a real acceptance run against the live scheduler) can
// catch this class of bug.
//
// This test asserts, against the real source tree, that both real task
// classes call parent::__construct().
// ================================================================

function tst021Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

foreach (['DeliverQueuedSupportEventsTask', 'CaptainSyncScheduledTask'] as $class) {
    $source = (string) file_get_contents("{$root}/classes/v2/Task/{$class}.php");
    $ctorStart = strpos($source, 'function __construct(');
    tst021Check($ctorStart !== false, "{$class} must be able to locate its __construct() for the source-level check below");
    $nextMethodStart = preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $ctorStart + 1)
        ? $m[0][1]
        : strlen($source);
    $ctorBody = substr($source, $ctorStart, $nextMethodStart - $ctorStart);
    tst021Check(
        str_contains($ctorBody, 'parent::__construct()'),
        "{$class}'s constructor must call parent::__construct() — pkp-lib's real ScheduledTask::__construct() initializes the typed \$executionLogFile property, and skipping it fatals the first time addExecutionLogEntry() runs against the real scheduler"
    );
}

fwrite(STDOUT, "PASS: tst-021-scheduled-task-parent-constructor\n");
