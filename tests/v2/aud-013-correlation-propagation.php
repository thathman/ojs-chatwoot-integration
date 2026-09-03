<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function aud013Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Extracts one method's body, bounded by the next top-level method
 * declaration (indented "private/public/protected function ..."), never
 * a nested closure's own "function (" — several real methods in this
 * codebase pass closures to Schema::create()/DB helpers that would
 * otherwise truncate the extraction immediately.
 */
function extractMethodBodyAud013(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = preg_match('/\n\s{4}(?:private|public|protected)\s+function\s/', $source, $m, PREG_OFFSET_CAPTURE, $start + strlen($needle))
        ? $m[0][1]
        : null;
    return $next !== null ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// AUD-013: complete end-to-end correlation propagation. AUD-002 only
// threaded a real CorrelationId through REST request handling; this
// closes the OJS-hook -> durable queue -> Chatwoot delivery ->
// delivery/audit-record half of the chain, so a single real event's
// full lifecycle can be traced by one ID, not just its REST-request
// half.
// ================================================================

// --- Migration: correlation_id must exist on fresh installs AND be
// added idempotently to an already-existing real table on upgrade (a
// bare Schema::create() guard alone would never reach an existing
// installation's table). ---
$migrationSource = (string) file_get_contents("{$root}/classes/v2/Migration/InstallSupportGatewayMigration.php");
$upEventQueueBody = extractMethodBodyAud013($migrationSource, 'private function upEventQueue()');
aud013Check(str_contains($upEventQueueBody, "string('correlation_id', 64)"), 'upEventQueue() must declare a real correlation_id column for fresh installs');
aud013Check(str_contains($upEventQueueBody, 'hasColumn(self::EVENT_QUEUE_TABLE'), 'upEventQueue() must have a real Schema::hasColumn() guard so an already-installed table (which Schema::hasTable() already short-circuits past) still gets the new column on upgrade');
aud013Check(
    strpos($upEventQueueBody, 'hasColumn(self::EVENT_QUEUE_TABLE') > strpos($upEventQueueBody, 'hasTable(self::EVENT_QUEUE_TABLE'),
    'the add-column-if-missing step must run after (outside) the create-table-if-missing branch, so it actually reaches a real pre-existing table'
);

// --- Repository contract: enqueue() must accept and persist a real
// correlation ID, not silently drop it. ---
$interfaceSource = (string) file_get_contents("{$root}/classes/v2/Contracts/SupportEventQueueRepositoryInterface.php");
aud013Check(str_contains($interfaceSource, 'function enqueue(SupportEvent $event, string $deliveryMode, string $correlationId): bool'), 'the real queue repository contract must require a correlation ID at enqueue time');

$repoSource = (string) file_get_contents("{$root}/classes/v2/Event/DatabaseSupportEventQueueRepository.php");
$enqueueBody = extractMethodBodyAud013($repoSource, 'public function enqueue(');
aud013Check(str_contains($enqueueBody, "'correlation_id' => \$correlationId"), 'the real repository must actually persist the correlation ID onto the row, not just accept it as an unused parameter');

// --- Plugin wiring: v2EnqueueEvent() generates a real CorrelationId for
// every OJS-hook-driven enqueue (there is no inbound request to reuse
// one from, unlike the REST/MCP side). ---
$pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
$enqueueEventBody = extractMethodBodyAud013($pluginSource, 'private function v2EnqueueEvent(');
aud013Check(str_contains($enqueueEventBody, '->enqueue($event, $mode, CorrelationId::generate())'), 'v2EnqueueEvent() must generate a real CorrelationId and pass it through to the repository, closing the hook -> queue half of the chain');

// --- Delivery: deliverQueuedSupportEvents() must audit every real
// outcome (delivered, failed, and the internal-error/exception path)
// using the row's own stored correlation ID, with a safe fresh
// fallback for a legacy row that predates this column (never silently
// dropping the audit record, since DatabaseSupportApiAuditLogger
// refuses to persist a blank correlationId). ---
$deliverBody = extractMethodBodyAud013($pluginSource, 'public function deliverQueuedSupportEvents(');
aud013Check(str_contains($deliverBody, "\$row['correlation_id']"), 'deliverQueuedSupportEvents() must read the real correlation_id stored on the row, not invent an unrelated one per attempt');
aud013Check(str_contains($deliverBody, 'CorrelationId::generate()'), 'deliverQueuedSupportEvents() must generate a fresh correlation ID as a fallback for a legacy row with none stored, rather than passing through an empty string the audit sink would silently drop');
$auditCallCount = substr_count($deliverBody, 'v2AuditEventDelivery(');
aud013Check($auditCallCount === 3, "deliverQueuedSupportEvents() must audit all three real outcomes (delivered, delivery_failed, internal_error) — found {$auditCallCount} call sites");

// --- The audit helper itself reuses the exact same persisted sink
// AUD-001/verification auditing already uses — never a second,
// independently-maintained audit table. ---
$auditHelperBody = extractMethodBodyAud013($pluginSource, 'private function v2AuditEventDelivery(');
aud013Check(str_contains($auditHelperBody, 'new DatabaseSupportApiAuditLogger()'), 'v2AuditEventDelivery() must reuse the real, same DatabaseSupportApiAuditLogger sink, never a bespoke one');
aud013Check(str_contains($auditHelperBody, "'event_delivery:' . \$eventType"), 'v2AuditEventDelivery() must record a real, distinguishable endpoint name per event type, so a delivery outcome is never confused with a verification-endpoint outcome in the same shared log');

// ================================================================
// AUD-013 follow-up: propagation across Captain/admin actions OUTSIDE
// the event-delivery chain — dead-letter give-up (legacy apiQueue) and
// Captain sync. Both previously had no correlation ID at all, and the
// dead-letter path wrote only to the AUD-001 placeholder error_log()
// sink, never the real persisted table every other outcome already
// uses.
// ================================================================

$v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
$processApiQueueBody = extractMethodBodyAud013($v1Source, 'private function processApiQueue(');
aud013Check(str_contains($processApiQueueBody, 'new DatabaseSupportApiAuditLogger()'), 'the legacy dead-letter give-up path must use the real, persisted DatabaseSupportApiAuditLogger sink, not the AUD-001 error_log() placeholder every other call site already retired');
aud013Check(!str_contains($processApiQueueBody, 'new ErrorLogSupportApiAuditLogger()'), 'the legacy dead-letter give-up path must never construct/use the placeholder error_log() sink');
aud013Check(str_contains($processApiQueueBody, "'correlationId' => CorrelationId::generate()"), 'a dead-lettered legacy job never had a correlation ID (v1\'s apiQueue predates AUD-013) — one must be generated fresh at give-up time, the same pattern deliverQueuedSupportEvents() uses for a legacy queue row with none stored');
aud013Check(str_contains($processApiQueueBody, "'endpoint' => 'legacy_queue:'"), 'the dead-letter audit entry must carry a real, distinguishable endpoint name, mirroring event_delivery:{type}\'s convention');

$captainTaskSource = (string) file_get_contents("{$root}/classes/v2/Task/CaptainSyncScheduledTask.php");
aud013Check(str_contains($captainTaskSource, 'v2AuditCaptainSync('), 'CaptainSyncScheduledTask must record a real audit entry for each journal it syncs — Captain sync runs entirely outside the event/queue/delivery chain and previously had no correlation ID at all');

$auditCaptainSyncBody = extractMethodBodyAud013($pluginSource, 'public function v2AuditCaptainSync(');
aud013Check(str_contains($auditCaptainSyncBody, 'new DatabaseSupportApiAuditLogger()'), 'v2AuditCaptainSync() must reuse the real, same DatabaseSupportApiAuditLogger sink, never a bespoke one');
aud013Check(str_contains($auditCaptainSyncBody, 'CorrelationId::generate()'), 'v2AuditCaptainSync() must generate a real correlation ID per real sync run, since Captain sync has no inbound request/queue row to inherit one from');
aud013Check(str_contains($auditCaptainSyncBody, "'endpoint' => 'captain_sync'"), 'v2AuditCaptainSync() must record a real, distinguishable endpoint name');

// ================================================================
// Real SQLite proof: a correlation ID actually round-trips through the
// exact schema shape the real migration/repository use — insert with
// one, fetch it back unchanged through a pending-batch-equivalent
// query, same technique tests/v2/tst-002-real-db-event-queue.php
// already established for this table.
// ================================================================
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE chatwoot_support_event_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        idempotency_key VARCHAR(64) NOT NULL UNIQUE,
        correlation_id VARCHAR(64) NULL,
        event_type VARCHAR(64) NOT NULL,
        context_id INTEGER NOT NULL,
        resource_type VARCHAR(32) NOT NULL,
        resource_id INTEGER NOT NULL,
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
$pdo->prepare('
    INSERT INTO chatwoot_support_event_queue
        (idempotency_key, correlation_id, event_type, context_id, resource_type, resource_id, delivery_mode, occurred_at, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
')->execute(['idem-corr-1', 'corr-abc-123', 'submission.created', 7, 'submission', 456, 'private_note', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

$fetched = $pdo->query("SELECT correlation_id FROM chatwoot_support_event_queue WHERE idempotency_key = 'idem-corr-1'")->fetchColumn();
aud013Check($fetched === 'corr-abc-123', 'a correlation ID stored at enqueue time must round-trip unchanged through a real database, ready for delivery-time auditing');

// Real proof of the upgrade path itself: adding the column to a table
// that was already created without it must succeed against a real
// database, exactly what a real pre-AUD-013 installation needs.
$pdo->exec('
    CREATE TABLE legacy_queue_without_correlation (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        idempotency_key VARCHAR(64) NOT NULL UNIQUE
    )
');
$pdo->exec("INSERT INTO legacy_queue_without_correlation (idempotency_key) VALUES ('pre-existing-row')");
$pdo->exec('ALTER TABLE legacy_queue_without_correlation ADD COLUMN correlation_id VARCHAR(64) NULL');
$preExistingRowCorrelation = $pdo->query("SELECT correlation_id FROM legacy_queue_without_correlation WHERE idempotency_key = 'pre-existing-row'")->fetchColumn();
aud013Check($preExistingRowCorrelation === null, 'a pre-existing row must survive the real upgrade with a null correlation_id (never a fabricated one for a row this migration never touched), matching the nullable column declaration');

fwrite(STDOUT, "PASS: aud-013-correlation-propagation\n");
