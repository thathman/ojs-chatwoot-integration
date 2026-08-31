<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportEventQueueRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\DatabaseSupportEventQueueRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEvent;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function supportEventQueueCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

supportEventQueueCheck(
    in_array(SupportEventQueueRepositoryInterface::class, class_implements(DatabaseSupportEventQueueRepository::class)),
    'DatabaseSupportEventQueueRepository must implement the real queue repository contract'
);

// ================================================================
// This test environment has no Illuminate/composer autoloader
// (deliberately — see tests/v2/audit-logger.php's own comment on the
// same constraint), so DatabaseSupportEventQueueRepository's real
// DB::table() calls can never actually run here. Every method touches
// the DB unconditionally (unlike DatabaseSupportApiAuditLogger's
// record(), which only degrades gracefully because that class was
// specifically designed to never break the request it audits) — this is
// deliberate: a future dispatcher built on top of this repository, not
// the repository itself, owns deciding whether a DB failure is
// recoverable. This test proves that failure mode is real and
// consistent (every method throws the same class-not-found error),
// rather than asserting nothing.
// ================================================================
$repo = new DatabaseSupportEventQueueRepository();
$event = SupportEvent::create(SupportEventType::SUBMISSION_CREATED, 7, 'submission', 900, '');

foreach ([
    'enqueue' => fn () => $repo->enqueue($event, 'private_note'),
    'fetchPendingBatch' => fn () => $repo->fetchPendingBatch(10, time()),
    'markDelivered' => fn () => $repo->markDelivered(1, time()),
    'markFailed' => fn () => $repo->markFailed(1, 'delivery_failed', 1, 5, time()),
] as $methodName => $call) {
    $threw = false;
    try {
        $call();
    } catch (\Throwable $e) {
        $threw = true;
        supportEventQueueCheck(
            str_contains($e->getMessage(), 'DB') || str_contains($e->getMessage(), 'Illuminate'),
            "{$methodName}() must fail specifically because it reaches the real DB layer, not for an unrelated reason: " . $e->getMessage()
        );
    }
    supportEventQueueCheck($threw, "{$methodName}() must reach the real DB layer rather than silently no-op in an environment where it's unavailable");
}

// ================================================================
// Source-level checks for the schema/behavior this environment can't
// exercise directly.
// ================================================================
$migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
supportEventQueueCheck(str_contains($migrationSource, "'chatwoot_support_event_queue'"), 'the event queue table name must be a real, defined constant value');
supportEventQueueCheck(str_contains($migrationSource, 'unique()'), 'sanity: the migration must define at least one unique constraint (idempotency_key)');
supportEventQueueCheck(
    (bool) preg_match('/idempotency_key.*unique\(\)/', $migrationSource) || (bool) preg_match('/idempotency_key.*\n.*unique\(\)/', $migrationSource),
    'idempotency_key must carry a real database-level unique constraint, not just an application-level check'
);
foreach (['attempts', 'run_after', 'last_error_code', 'delivered_at', 'status'] as $column) {
    supportEventQueueCheck(str_contains($migrationSource, "'{$column}'"), "the event queue table must define a real '{$column}' column for retry/dead-letter bookkeeping (EVT-014)");
}

$repoSource = (string) file_get_contents($root . '/classes/v2/Event/DatabaseSupportEventQueueRepository.php');
supportEventQueueCheck(str_contains($repoSource, 'idempotency_key'), 'enqueue() must check the real idempotency key column before inserting');
supportEventQueueCheck(str_contains($repoSource, '$event->attributes()'), 'enqueue() must persist only the event\'s own already-safe attributes, never a wider payload');

// EVT-015 (replay/duplicate, the half provable without a live DB — see
// EVT-002 in tests/v2/support-event.php for the deterministic idempotency
// key derivation this enforcement actually depends on):
// fetchPendingBatch() must only ever select rows still in 'pending' status,
// so a row markDelivered() already transitioned away from 'pending' can
// never be fetched and delivered a second time.
$fetchMethodStart = strpos($repoSource, 'function fetchPendingBatch');
supportEventQueueCheck($fetchMethodStart !== false, 'fetchPendingBatch() must exist');
$fetchMethodEnd = strpos($repoSource, 'function ', $fetchMethodStart + 1);
$fetchMethodBody = $fetchMethodEnd !== false ? substr($repoSource, $fetchMethodStart, $fetchMethodEnd - $fetchMethodStart) : substr($repoSource, $fetchMethodStart);
supportEventQueueCheck(
    (bool) preg_match('/where\(\s*[\'"]status[\'"]\s*,\s*[\'"]pending[\'"]\s*\)/', $fetchMethodBody),
    'fetchPendingBatch() must filter on status=pending — a delivered row must structurally never be fetched again, preventing replay/double-delivery'
);
foreach (['email', 'orcid', 'affiliation', 'getUserVar'] as $forbidden) {
    supportEventQueueCheck(!str_contains($repoSource, $forbidden), "the queue repository must never reference '{$forbidden}' — it only ever persists what SupportEvent already carries");
}

fwrite(STDOUT, "Support event queue repository tests passed\n");
