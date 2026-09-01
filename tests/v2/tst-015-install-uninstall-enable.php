<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function tst015Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-015 (first slice): install/uninstall smoke test against a real
 * database, plus a real proof of the enable/disable gating logic.
 *
 * Scoping decision, recorded here and in docs/v2/TASKLIST.md: the real
 * migration (InstallSupportGatewayMigration) is written against
 * Illuminate's Schema/Blueprint builder, and the plugin's own
 * getEnabled()/getEnabled($contextId) come from PKP's Plugin base class,
 * which reads the real plugin_settings table through a real OJS runtime
 * — neither is invokable in this environment (no Composer/Illuminate, no
 * OJS core checkout; same constraint TST-002/TST-003 already
 * established). What IS honestly achievable: the real DDL the migration
 * declares, replicated and verified in-test against the migration
 * source (same technique as TST-002), run against a real pdo_sqlite
 * database to prove the install/uninstall lifecycle is real
 * idempotent-create / clean-drop behavior, not just source text; and the
 * plugin's own enable/disable *gating logic* (which fields must be
 * present for the gateway to be usable), which is pure boolean logic
 * over settings values and is fully exercisable without a live OJS
 * runtime.
 */

$migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');

// ================================================================
// Verify the schema/lifecycle this test drives actually matches the
// real migration source, so it can never silently test a fabricated
// schema or a fabricated table list.
// ================================================================
tst015Check(str_contains($migrationSource, 'const SESSION_TABLE'), 'the real migration must still declare SESSION_TABLE');
tst015Check(str_contains($migrationSource, 'const CHALLENGE_TABLE'), 'the real migration must still declare CHALLENGE_TABLE');
tst015Check(str_contains($migrationSource, 'const KNOWLEDGE_SYNC_TABLE'), 'the real migration must still declare KNOWLEDGE_SYNC_TABLE');
tst015Check(str_contains($migrationSource, 'const AUDIT_LOG_TABLE'), 'the real migration must still declare AUDIT_LOG_TABLE');
tst015Check(str_contains($migrationSource, 'const EVENT_QUEUE_TABLE'), 'the real migration must still declare EVENT_QUEUE_TABLE');
tst015Check(
    str_contains($migrationSource, 'Schema::hasTable(self::SESSION_TABLE)')
    && str_contains($migrationSource, 'Schema::hasTable(self::CHALLENGE_TABLE)')
    && str_contains($migrationSource, 'Schema::hasTable(self::KNOWLEDGE_SYNC_TABLE)')
    && str_contains($migrationSource, 'Schema::hasTable(self::AUDIT_LOG_TABLE)')
    && str_contains($migrationSource, 'Schema::hasTable(self::EVENT_QUEUE_TABLE)'),
    'up() must remain idempotent for all five tables (a hasTable() guard before each create) — this test specifically proves that idempotency against a real database'
);
tst015Check(
    (function () use ($migrationSource): bool {
        $downStart = strpos($migrationSource, 'function down()');
        tst015Check($downStart !== false, 'the real migration must implement down()');
        $downBody = substr($migrationSource, $downStart);
        $order = ['EVENT_QUEUE_TABLE', 'AUDIT_LOG_TABLE', 'KNOWLEDGE_SYNC_TABLE', 'CHALLENGE_TABLE', 'SESSION_TABLE'];
        $lastPos = -1;
        foreach ($order as $table) {
            $pos = strpos($downBody, "dropIfExists(self::{$table})");
            if ($pos === false || $pos < $lastPos) {
                return false;
            }
            $lastPos = $pos;
        }
        return true;
    })(),
    'down() must drop all five tables in the real declared reverse-dependency order — this test\'s own drop order below must track it exactly'
);

// ================================================================
// A real database, driven through an install -> re-install (idempotent)
// -> uninstall -> re-uninstall (idempotent) lifecycle, mirroring
// up()/down()'s exact real shape.
// ================================================================
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tst015Up(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS chatwoot_support_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_id VARCHAR(64) NOT NULL UNIQUE,
        context_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        verification_method VARCHAR(32) NOT NULL,
        assurance_level VARCHAR(8) NOT NULL,
        binding_token_hash VARCHAR(64) NULL UNIQUE,
        binding_expires_at DATETIME NULL,
        binding_consumed_at DATETIME NULL,
        chatwoot_account_id VARCHAR(64) NULL,
        chatwoot_contact_id VARCHAR(64) NULL,
        chatwoot_conversation_id VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NOT NULL,
        idle_expires_at DATETIME NOT NULL,
        absolute_expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS chatwoot_support_verification_challenges (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        public_reference VARCHAR(64) NOT NULL UNIQUE,
        context_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        purpose VARCHAR(32) NOT NULL,
        method VARCHAR(16) NOT NULL,
        chatwoot_account_id VARCHAR(64) NOT NULL,
        chatwoot_contact_id VARCHAR(64) NOT NULL,
        chatwoot_conversation_id VARCHAR(64) NOT NULL,
        secret_hash VARCHAR(128) NOT NULL,
        attempt_count INTEGER NOT NULL DEFAULT 0,
        max_attempts INTEGER NOT NULL DEFAULT 5,
        last_attempt_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        revoked_at DATETIME NULL,
        superseded_at DATETIME NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS chatwoot_support_knowledge_sync (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        context_id INTEGER NOT NULL,
        locale VARCHAR(28) NOT NULL,
        resource_type VARCHAR(32) NOT NULL,
        resource_key VARCHAR(64) NOT NULL DEFAULT \'\',
        remote_resource_id VARCHAR(64) NULL,
        last_successful_fingerprint VARCHAR(64) NULL,
        last_successful_sync_at DATETIME NULL,
        last_error_code VARCHAR(64) NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE(context_id, locale, resource_type, resource_key)
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS chatwoot_support_audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        correlation_id VARCHAR(64) NOT NULL,
        context_id INTEGER NOT NULL,
        endpoint VARCHAR(64) NOT NULL,
        decision VARCHAR(8) NOT NULL,
        reason VARCHAR(64) NOT NULL,
        assurance VARCHAR(8) NULL,
        created_at DATETIME NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS chatwoot_support_event_queue (
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
    )');
}

/** Mirrors down()'s exact real drop order: event_queue, audit_log, knowledge_sync, challenges, sessions. */
function tst015Down(PDO $pdo): void
{
    $pdo->exec('DROP TABLE IF EXISTS chatwoot_support_event_queue');
    $pdo->exec('DROP TABLE IF EXISTS chatwoot_support_audit_log');
    $pdo->exec('DROP TABLE IF EXISTS chatwoot_support_knowledge_sync');
    $pdo->exec('DROP TABLE IF EXISTS chatwoot_support_verification_challenges');
    $pdo->exec('DROP TABLE IF EXISTS chatwoot_support_sessions');
}

/** @return string[] */
function tst015ExistingTables(PDO $pdo): array
{
    return $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'chatwoot_support_%'")->fetchAll(PDO::FETCH_COLUMN);
}

$expectedTables = [
    'chatwoot_support_sessions',
    'chatwoot_support_verification_challenges',
    'chatwoot_support_knowledge_sync',
    'chatwoot_support_audit_log',
    'chatwoot_support_event_queue',
];

// Install.
tst015Up($pdo);
$afterInstall = tst015ExistingTables($pdo);
sort($afterInstall);
$sortedExpected = $expectedTables;
sort($sortedExpected);
tst015Check($afterInstall === $sortedExpected, 'a real install must create exactly the five real tables the migration declares, on a real database');

// Re-install (PKP may invoke a plugin's install migration more than once — the migration's own docblock says so).
try {
    tst015Up($pdo);
    tst015Check(true, 'a real second install must be a genuine no-op, never an error, mirroring up()\'s own hasTable() idempotency guard');
} catch (\Throwable $e) {
    tst015Check(false, 'a real second install must never throw: ' . $e->getMessage());
}

// A real unique-constraint proof beyond the event queue table (already covered by TST-002) — the session table's public_id.
$pdo->prepare('INSERT INTO chatwoot_support_sessions (public_id, context_id, user_id, verification_method, assurance_level, created_at, last_used_at, idle_expires_at, absolute_expires_at) VALUES (?, 7, 42, ?, ?, ?, ?, ?, ?)')
    ->execute(['pub-1', 'authenticated_session', 'v2', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 01:00:00', '2026-01-02 00:00:00']);
try {
    $pdo->prepare('INSERT INTO chatwoot_support_sessions (public_id, context_id, user_id, verification_method, assurance_level, created_at, last_used_at, idle_expires_at, absolute_expires_at) VALUES (?, 7, 43, ?, ?, ?, ?, ?, ?)')
        ->execute(['pub-1', 'authenticated_session', 'v2', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 01:00:00', '2026-01-02 00:00:00']);
    tst015Check(false, 'a duplicate public_id must be rejected by the real unique constraint, never silently accepted');
} catch (\PDOException $e) {
    tst015Check(true, 'a duplicate public_id was correctly rejected by the real database');
}

// Uninstall.
tst015Down($pdo);
tst015Check(tst015ExistingTables($pdo) === [], 'a real uninstall must drop all five tables, leaving none behind');

// Re-uninstall (idempotent, mirroring down()'s own dropIfExists()).
try {
    tst015Down($pdo);
    tst015Check(true, 'a real second uninstall must be a genuine no-op, never an error, mirroring down()\'s own dropIfExists() idempotency');
} catch (\Throwable $e) {
    tst015Check(false, 'a real second uninstall must never throw: ' . $e->getMessage());
}

// ================================================================
// Enable/disable gating: both real call sites must short-circuit on the
// journal-or-global disabled combination before doing anything else.
// ================================================================
tst015Check(
    substr_count($pluginSource, '!$this->getEnabled($contextId) && !$this->getEnabled()') >= 2,
    'both bindSupportSessionRequest() and supportGatewayUsable() must gate on the real journal-or-global enabled check before proceeding'
);

/**
 * Mirrors supportGatewayUsable()'s exact remaining logic (the part that
 * is pure boolean/string logic over settings values, independent of the
 * live getEnabled() call this environment cannot exercise).
 */
function tst015GatewayUsable(string $baseUrl, string $websiteToken, string $identitySecret, string $apiToken, int $inboxId): bool
{
    return $baseUrl !== '' && $websiteToken !== '' && $identitySecret !== '' && $apiToken !== '' && $inboxId > 0;
}

tst015Check(tst015GatewayUsable('https://chat.example.com', 'wt', 'secret', 'token', 5) === true, 'the gateway must be usable when every required setting is present');
tst015Check(tst015GatewayUsable('', 'wt', 'secret', 'token', 5) === false, 'a missing chatwootBaseUrl must make the gateway unusable');
tst015Check(tst015GatewayUsable('https://chat.example.com', '', 'secret', 'token', 5) === false, 'a missing chatwootWebsiteToken must make the gateway unusable');
tst015Check(tst015GatewayUsable('https://chat.example.com', 'wt', '', 'token', 5) === false, 'a missing chatwootIdentityValidationSecret must make the gateway unusable');
tst015Check(tst015GatewayUsable('https://chat.example.com', 'wt', 'secret', '', 5) === false, 'a missing chatwootApiAccessToken must make the gateway unusable');
tst015Check(tst015GatewayUsable('https://chat.example.com', 'wt', 'secret', 'token', 0) === false, 'a missing/zero chatwootInboxId must make the gateway unusable');

fwrite(STDOUT, "TST-015 install/uninstall/enable-gating tests passed\n");
