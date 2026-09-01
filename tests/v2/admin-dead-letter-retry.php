<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function adminDeadLetterRetryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * EVT-014 (dead-letter/retry UI, first slice): a real database proof of
 * the retry action, using the same pdo_sqlite technique TST-002/015
 * already established (DatabaseSupportEventQueueRepository is hard-wired
 * to Illuminate's DB::table(), unavailable in this environment), plus
 * source-level wiring assertions for the plugin/form/template glue.
 */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE chatwoot_support_event_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        idempotency_key VARCHAR(64) NOT NULL UNIQUE,
        event_type VARCHAR(64) NOT NULL,
        context_id INTEGER NOT NULL,
        resource_type VARCHAR(32) NOT NULL,
        resource_id INTEGER NOT NULL,
        attributes TEXT NULL,
        delivery_mode VARCHAR(32) NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'pending\',
        attempts INTEGER NOT NULL DEFAULT 0,
        occurred_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        run_after DATETIME NULL,
        delivered_at DATETIME NULL,
        last_error_code VARCHAR(64) NULL
    )
');

function seedRow(PDO $pdo, string $key, int $contextId, string $status, int $attempts, ?string $lastErrorCode, ?string $runAfter, string $createdAt): void
{
    $pdo->prepare('
        INSERT INTO chatwoot_support_event_queue
            (idempotency_key, event_type, context_id, resource_type, resource_id, delivery_mode, status, attempts, occurred_at, created_at, run_after, last_error_code)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ')->execute([$key, 'submission.created', $contextId, 'submission', 456, 'sync', $status, $attempts, $createdAt, $createdAt, $runAfter, $lastErrorCode]);
}

/** Mirrors DatabaseSupportEventQueueRepository::retryDeadLetters() exactly: select-then-update, scoped to one journal, capped at $limit. */
function retryDeadLetters(PDO $pdo, int $contextId, int $limit): int
{
    $stmt = $pdo->prepare('SELECT id FROM chatwoot_support_event_queue WHERE context_id = ? AND status = ? ORDER BY created_at LIMIT ?');
    $stmt->bindValue(1, $contextId, PDO::PARAM_INT);
    $stmt->bindValue(2, 'failed');
    $stmt->bindValue(3, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($ids === []) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $update = $pdo->prepare("UPDATE chatwoot_support_event_queue SET status = 'pending', attempts = 0, run_after = NULL WHERE id IN ({$placeholders})");
    $update->execute($ids);
    return $update->rowCount();
}

// Seed: two failed rows for journal 7, one failed row for journal 99, one already-pending row for journal 7.
seedRow($pdo, 'key-1', 7, 'failed', 5, 'delivery_failed', null, '2026-01-01 00:00:00');
seedRow($pdo, 'key-2', 7, 'failed', 5, 'internal_error', null, '2026-01-01 00:01:00');
seedRow($pdo, 'key-3', 99, 'failed', 5, 'delivery_failed', null, '2026-01-01 00:02:00');
seedRow($pdo, 'key-4', 7, 'pending', 0, null, null, '2026-01-01 00:03:00');

// ================================================================
// A real retry must reset only this journal's failed rows, never
// another journal's, and never a row that wasn't actually a dead
// letter.
// ================================================================
$retried = retryDeadLetters($pdo, 7, 50);
adminDeadLetterRetryCheck($retried === 2, 'retryDeadLetters() must report exactly the real number of rows it reset for this journal, on a real database');

$journal7Statuses = $pdo->query('SELECT idempotency_key, status, attempts, run_after FROM chatwoot_support_event_queue WHERE context_id = 7 ORDER BY idempotency_key')->fetchAll(PDO::FETCH_ASSOC);
foreach ($journal7Statuses as $row) {
    if (in_array($row['idempotency_key'], ['key-1', 'key-2'], true)) {
        adminDeadLetterRetryCheck($row['status'] === 'pending' && (int) $row['attempts'] === 0 && $row['run_after'] === null, "a real retried dead letter ({$row['idempotency_key']}) must be reset to pending with a fresh attempts budget and no delivery delay");
    }
}

$journal99Status = $pdo->query("SELECT status FROM chatwoot_support_event_queue WHERE idempotency_key = 'key-3'")->fetchColumn();
adminDeadLetterRetryCheck($journal99Status === 'failed', 'a real retry for one journal must never touch another journal\'s dead letters');

$alreadyPendingAttempts = $pdo->query("SELECT attempts FROM chatwoot_support_event_queue WHERE idempotency_key = 'key-4'")->fetchColumn();
adminDeadLetterRetryCheck((int) $alreadyPendingAttempts === 0, 'a row that was never a dead letter must be completely untouched by the retry action');

// A second retry with nothing left to retry must be a real, safe no-op.
$secondRetry = retryDeadLetters($pdo, 7, 50);
adminDeadLetterRetryCheck($secondRetry === 0, 'retrying again with no remaining dead letters must report zero, never error or double-count');

// ================================================================
// Wiring: the real repository/interface must declare retryDeadLetters(),
// the real plugin method must exist, delegate to it, expose only a
// count (never row content), and the verb/URL/button must be wired.
// ================================================================
$interfaceSource = (string) file_get_contents($root . '/classes/v2/Contracts/SupportEventQueueRepositoryInterface.php');
adminDeadLetterRetryCheck(str_contains($interfaceSource, 'public function retryDeadLetters(int $contextId, int $limit): int;'), 'the interface must declare the real retryDeadLetters() method');

$implSource = (string) file_get_contents($root . '/classes/v2/Event/DatabaseSupportEventQueueRepository.php');
adminDeadLetterRetryCheck(str_contains($implSource, "->update(['status' => 'pending', 'attempts' => 0, 'run_after' => null])"), 'the real repository must reset status/attempts/run_after via a real update, never a fabricated/hardcoded count');
adminDeadLetterRetryCheck(str_contains($implSource, "where('context_id', \$contextId)"), 'the real repository must scope the retry to one journal, never every journal at once');

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function retryDeadLetterEvents(');
adminDeadLetterRetryCheck($methodStart !== false, 'the plugin must implement a real retryDeadLetterEvents() method');
$methodBody = substr($pluginSource, $methodStart, (int) strpos($pluginSource, "\n    }\n", $methodStart) - $methodStart);
adminDeadLetterRetryCheck(str_contains($methodBody, '->retryDeadLetters(') && str_contains($methodBody, "['retried' => \$retried]"), 'the plugin method must delegate to the real repository method and return only a count, never raw row content');
adminDeadLetterRetryCheck(!str_contains($methodBody, 'last_error_code') && !str_contains($methodBody, 'attributes'), 'the plugin method must never read/expose a dead-letter row\'s error code or attributes content, per the explicit no-raw-exception-text/no-secret-bearing-body constraint');
adminDeadLetterRetryCheck(str_contains($pluginSource, "if (\$request->getUserVar('verb') === 'retryDeadLetterEvents')"), 'the plugin must route a real retryDeadLetterEvents verb to the real method');

$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
adminDeadLetterRetryCheck(str_contains($formSource, "'verb' => 'retryDeadLetterEvents'"), 'the settings form must build a real URL for the retryDeadLetterEvents verb');

$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
adminDeadLetterRetryCheck(str_contains($templateSource, 'chatwootRetryDeadLettersBtn') && str_contains($templateSource, '$retryDeadLetterEventsUrl'), 'the template must render a real, wired Retry Dead Letters button');
adminDeadLetterRetryCheck(str_contains($templateSource, 'supportGatewayHealth.deadLetterCount > 0'), 'the retry button must only be shown when there is actually something to retry, never unconditionally');

fwrite(STDOUT, "Admin dead-letter retry tests passed\n");
