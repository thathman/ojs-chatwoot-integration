<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/classes/v2/Audit/SupportApiAuditLoggerInterface.php';
    require_once $root . '/classes/v2/Audit/DatabaseSupportApiAuditLogger.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\DatabaseSupportApiAuditLogger;

    function auditLoggerCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // This test environment has no Illuminate/composer autoloader
    // (deliberately — see tests/v2/purge-expired-support-data-task.php's
    // own comment on the same constraint), so
    // `Illuminate\Support\Facades\DB` and
    // `InstallSupportGatewayMigration`'s real parent class
    // (`Illuminate\Database\Migrations\Migration`) can never actually
    // load here. Rather than skip real behavioral coverage, this test
    // uses that guaranteed-missing-class condition as a real, naturally
    // occurring "the DB layer is unavailable" fault to prove
    // DatabaseSupportApiAuditLogger's catch-and-degrade behavior for
    // real, not just by reading its source text.
    // ================================================================

    $logger = new DatabaseSupportApiAuditLogger();

    // --- required-field validation: an incomplete event must never
    // reach the DB-write attempt (and therefore never reach the
    // error_log fallback either) ---
    ob_start();
    $logger->record([]);
    $logger->record(['endpoint' => 'x', 'contextId' => 1, 'decision' => 'allow', 'reason' => 'verified']); // missing correlationId
    $logger->record(['correlationId' => 'c1', 'contextId' => 1, 'decision' => 'allow', 'reason' => 'verified']); // missing endpoint
    $logger->record(['correlationId' => 'c1', 'endpoint' => 'x', 'contextId' => 1, 'reason' => 'verified']); // missing decision
    $logger->record(['correlationId' => 'c1', 'endpoint' => 'x', 'contextId' => 1, 'decision' => 'allow']); // missing reason
    $droppedOutput = (string) ob_get_clean();
    auditLoggerCheck($droppedOutput === '', 'an event missing a required field must be dropped before any write attempt is even tried');

    // --- a complete event must attempt the write, and must fall back
    // to error_log (never throw) when the DB layer is unavailable ---
    $stderr = tmpfile();
    $stderrPath = stream_get_meta_data($stderr)['uri'];
    $restore = ini_set('error_log', $stderrPath);
    $logger->record([
        'correlationId' => 'corr-123',
        'endpoint' => 'support.status',
        'contextId' => 7,
        'decision' => 'deny',
        'reason' => 'rate_limited',
        // deliberately includes fields the allowlist must strip:
        'binding_token_hash' => 'must-never-appear',
        'secret' => 'must-never-appear',
    ]);
    ini_set('error_log', $restore === false ? '' : $restore);
    $fallbackLine = (string) file_get_contents($stderrPath);
    auditLoggerCheck(
        str_contains($fallbackLine, 'corr-123') && str_contains($fallbackLine, 'rate_limited'),
        'a complete event must reach the DB-write attempt and fall back to error_log without throwing when the DB layer is unavailable'
    );
    auditLoggerCheck(
        !str_contains($fallbackLine, 'must-never-appear'),
        'record() only ever forwards the fields it explicitly allowlists — an unrecognized key must never surface even in the degraded error_log fallback'
    );

    // --- purgeOlderThan() is not itself defensive (the scheduled task's
    // own try/catch is the safety net around it) — confirm it reaches
    // the real DB call path rather than silently no-op'ing ---
    $threw = false;
    try {
        $logger->purgeOlderThan(time(), 100);
    } catch (\Throwable $e) {
        $threw = true;
        auditLoggerCheck(
            str_contains($e->getMessage(), 'DB') || str_contains($e->getMessage(), 'Illuminate'),
            'purgeOlderThan() must fail specifically because it reaches the real DB layer, not for an unrelated reason: ' . $e->getMessage()
        );
    }
    auditLoggerCheck($threw, 'purgeOlderThan() must propagate a DB-layer failure to its caller (the scheduled task is the layer responsible for catching it)');

    fwrite(STDOUT, "Audit logger tests passed\n");
}
