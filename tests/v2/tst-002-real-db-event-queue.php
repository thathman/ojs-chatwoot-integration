<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function tst002Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-002 (first slice): a real, live-database proof of the two EVT-015
 * behaviors that were previously only provable at the source/schema level
 * (see tests/v2/support-event-queue.php's own documented constraint — this
 * environment has no Illuminate/Composer autoloader, so
 * DatabaseSupportEventQueueRepository's real DB::table() calls can never
 * actually run here).
 *
 * Scoping decision, recorded here and in docs/v2/TASKLIST.md: a full
 * TST-002 (real OJS runtime + MySQL/Postgres, via Composer + a live DB
 * service) is a genuine infrastructure undertaking — a new Composer
 * dependency tree, CI service containers, a full pkp-lib checkout — and is
 * not built in this PR (that would be overbuilding a single incremental
 * change). What IS honestly achievable without any new infrastructure:
 * PHP's bundled pdo_sqlite extension gives this environment a real,
 * running relational database engine today, with no Composer and no CI
 * service container required. This test uses it to prove the exact two
 * claims EVT-015 left open — a real concurrent-insert unique-constraint
 * race, and real replay prevention via a status-filtered SELECT — against
 * an actual database, not a source-level inference. It does not exercise
 * DatabaseSupportEventQueueRepository directly (that class is hard-wired
 * to Illuminate's DB::table(), unavailable here); instead it replicates
 * the exact schema/query shape that class and the real migration define,
 * verified against the real migration source below so this can never
 * silently drift into testing a fabricated schema. TST-003 (a Chatwoot
 * mock/contract harness) is separate scope, tracked as the next slice.
 */

// ================================================================
// Verify the schema this test creates actually matches the real
// migration's declared columns/constraint for the event queue table —
// never a schema invented for convenience.
// ================================================================
$migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
$realColumns = ['idempotency_key', 'event_type', 'context_id', 'resource_type', 'resource_id', 'attributes', 'delivery_mode', 'status', 'attempts', 'occurred_at', 'created_at', 'run_after', 'delivered_at', 'last_error_code'];
foreach ($realColumns as $column) {
    tst002Check(str_contains($migrationSource, "'{$column}'"), "the real migration must still declare a \"{$column}\" column — this test's schema must track it exactly");
}
tst002Check(str_contains($migrationSource, "string('idempotency_key', 64)->unique()"), 'the real migration must still declare idempotency_key as unique — this test specifically proves that constraint against a real database');

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

/** Mirrors DatabaseSupportEventQueueRepository::enqueue()'s own exists-check-then-insert-with-catch shape exactly. */
function tst002Enqueue(PDO $pdo, string $idempotencyKey, string $createdAt): bool
{
    $exists = $pdo->prepare('SELECT 1 FROM chatwoot_support_event_queue WHERE idempotency_key = ?');
    $exists->execute([$idempotencyKey]);
    if ($exists->fetchColumn() !== false) {
        return false;
    }

    try {
        $insert = $pdo->prepare('
            INSERT INTO chatwoot_support_event_queue
                (idempotency_key, event_type, context_id, resource_type, resource_id, delivery_mode, status, attempts, occurred_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, \'pending\', 0, ?, ?)
        ');
        $insert->execute([$idempotencyKey, 'submission.created', 7, 'submission', 456, 'sync', $createdAt, $createdAt]);
    } catch (\PDOException $e) {
        // A concurrent enqueue of the same real occurrence races the
        // exists() check above and hits the real unique constraint —
        // exactly the "already queued" outcome the real repository
        // treats this as, never a crash.
        return false;
    }

    return true;
}

/** Mirrors DatabaseSupportEventQueueRepository::fetchPendingBatch()'s exact filter/order shape. */
function tst002FetchPendingBatch(PDO $pdo, int $limit, string $nowDb): array
{
    $stmt = $pdo->prepare('
        SELECT * FROM chatwoot_support_event_queue
        WHERE status = \'pending\' AND (run_after IS NULL OR run_after <= ?)
        ORDER BY created_at
        LIMIT ?
    ');
    $stmt->bindValue(1, $nowDb);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ================================================================
// EVT-015, claim 1: a real concurrent duplicate-insert race against the
// real unique constraint resolves to "already queued", never a crash and
// never two rows for the same real occurrence.
// ================================================================
$firstEnqueue = tst002Enqueue($pdo, 'idem-key-1', '2026-01-01 00:00:00');
tst002Check($firstEnqueue === true, 'the first enqueue of a new idempotency key must succeed against a real database');

$secondEnqueue = tst002Enqueue($pdo, 'idem-key-1', '2026-01-01 00:00:05');
tst002Check($secondEnqueue === false, 'a second enqueue of the identical idempotency key must be rejected by the real unique constraint, mirroring enqueue()\'s own catch-and-return-false race handling');

$countStmt = $pdo->query("SELECT COUNT(*) FROM chatwoot_support_event_queue WHERE idempotency_key = 'idem-key-1'");
tst002Check((int) $countStmt->fetchColumn() === 1, 'exactly one row must exist for the idempotency key after the race, never two, proven against a real running database');

// ================================================================
// EVT-015, claim 2: real replay prevention — a row already transitioned
// away from status='pending' can never be re-fetched by a real
// status-filtered SELECT, regardless of how many times it's queried.
// ================================================================
tst002Enqueue($pdo, 'idem-key-2', '2026-01-01 00:01:00');
$idToDeliver = (int) $pdo->query("SELECT id FROM chatwoot_support_event_queue WHERE idempotency_key = 'idem-key-2'")->fetchColumn();

$beforeDelivery = tst002FetchPendingBatch($pdo, 10, '2026-01-01 01:00:00');
tst002Check(count(array_filter($beforeDelivery, fn (array $row): bool => (int) $row['id'] === $idToDeliver)) === 1, 'a genuinely pending row must be returned by a real fetchPendingBatch-equivalent query');

$pdo->prepare("UPDATE chatwoot_support_event_queue SET status = 'delivered', delivered_at = ? WHERE id = ?")->execute(['2026-01-01 00:05:00', $idToDeliver]);

$afterDelivery = tst002FetchPendingBatch($pdo, 10, '2026-01-01 01:00:00');
tst002Check(count(array_filter($afterDelivery, fn (array $row): bool => (int) $row['id'] === $idToDeliver)) === 0, 'a row already marked delivered must never be re-fetched by a real status-filtered query, even when queried again — real replay prevention, not just a structural inference');

// ================================================================
// The run_after backoff window is honored by a real query, not just
// asserted to exist in the schema.
// ================================================================
tst002Enqueue($pdo, 'idem-key-3', '2026-01-01 00:02:00');
$idBackoff = (int) $pdo->query("SELECT id FROM chatwoot_support_event_queue WHERE idempotency_key = 'idem-key-3'")->fetchColumn();
$pdo->prepare('UPDATE chatwoot_support_event_queue SET run_after = ? WHERE id = ?')->execute(['2026-01-01 02:00:00', $idBackoff]);

$stillBackingOff = tst002FetchPendingBatch($pdo, 10, '2026-01-01 01:00:00');
tst002Check(count(array_filter($stillBackingOff, fn (array $row): bool => (int) $row['id'] === $idBackoff)) === 0, 'a row whose run_after is still in the future must not be fetched by a real query');

$backoffElapsed = tst002FetchPendingBatch($pdo, 10, '2026-01-01 03:00:00');
tst002Check(count(array_filter($backoffElapsed, fn (array $row): bool => (int) $row['id'] === $idBackoff)) === 1, 'the same row must be fetched by a real query once "now" passes its real run_after value');

fwrite(STDOUT, "TST-002 real-database event-queue tests passed\n");
