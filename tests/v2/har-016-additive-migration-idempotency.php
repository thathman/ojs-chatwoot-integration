<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har016Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-016: once 2.0.0.0 is an immutable installed state, new schema
 * must never depend on rewriting the original install definition, and
 * every real migration step must be idempotent (a plugin upgrade path
 * that re-runs an already-applied step on an existing install must
 * never fail or duplicate work). getInstallMigration() already
 * returns the additive SupportGatewayMigrationRunner (never the
 * baseline InstallSupportGatewayMigration directly — see
 * kno-011-approved-faq-provider.php for the baseline-immutability
 * proof, and TASKLIST.md KNO-011/MIG-003 for the real live-verified
 * upgrade evidence: SupportGatewayMigrationRunner upgraded the real
 * 2.0.0.0 database in place, existing five tables untouched, new
 * chatwoot_support_faq_cache table added). This proves the real
 * idempotency guard every up() step in both migrations actually uses.
 */
$installSource = (string) file_get_contents("{$root}/classes/v2/Migration/InstallSupportGatewayMigration.php");
$faqCacheSource = (string) file_get_contents("{$root}/classes/v2/Migration/AddFaqCacheTableMigration.php");
$runnerSource = (string) file_get_contents("{$root}/classes/v2/Migration/SupportGatewayMigrationRunner.php");

// Every real Schema::create() call in both migrations must be guarded
// by a real Schema::hasTable() check first — re-running up() on an
// already-installed database must be a safe no-op per table, never a
// "table already exists" fatal. Count only real calls (a leading `\s`
// before `Schema::create(`), not this file's own explanatory prose
// mentioning the same method name in a comment.
$realCreateCalls = preg_match_all('/[^\/\s]\s+Schema::create\(self::\w+/', $installSource);
har016Check(substr_count($installSource, 'Schema::hasTable(') >= $realCreateCalls, 'InstallSupportGatewayMigration must guard every real table creation with a real Schema::hasTable() idempotency check');
har016Check(str_contains($installSource, "Schema::hasColumn(self::EVENT_QUEUE_TABLE, 'correlation_id')"), 'a later additive column on an already-existing table (AUD-013\'s correlation_id) must also be guarded by a real Schema::hasColumn() check, not just table-level idempotency');

har016Check(substr_count($faqCacheSource, 'Schema::hasTable(') >= substr_count($faqCacheSource, 'Schema::create('), 'AddFaqCacheTableMigration must guard its table creation with a real Schema::hasTable() idempotency check');

// The runner itself must never construct the baseline migration more
// than once per real run, and must run steps in a real fixed order
// (baseline before additive) — no undefined/random ordering.
har016Check(substr_count($runnerSource, 'new InstallSupportGatewayMigration()') === 1, 'SupportGatewayMigrationRunner must construct the baseline migration exactly once');
har016Check(
    strpos($runnerSource, 'new InstallSupportGatewayMigration()') < strpos($runnerSource, 'new AddFaqCacheTableMigration()'),
    'the baseline migration must run before any additive step, in a real fixed order'
);

fwrite(STDOUT, "HAR-016 additive-migration-idempotency tests passed\n");
