<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Install the v2 Support Gateway session store.
 *
 * The migration is intentionally idempotent because PKP may invoke a plugin's
 * install migration during install/upgrade flows more than once.
 */
final class InstallSupportGatewayMigration extends Migration
{
    public const SESSION_TABLE = 'chatwoot_support_sessions';
    public const CHALLENGE_TABLE = 'chatwoot_support_verification_challenges';
    public const KNOWLEDGE_SYNC_TABLE = 'chatwoot_support_knowledge_sync';
    public const AUDIT_LOG_TABLE = 'chatwoot_support_audit_log';
    public const EVENT_QUEUE_TABLE = 'chatwoot_support_event_queue';

    public function up(): void
    {
        $this->upSessions();
        $this->upChallenges();
        $this->upKnowledgeSync();
        $this->upAuditLog();
        $this->upEventQueue();
    }

    private function upSessions(): void
    {
        if (Schema::hasTable(self::SESSION_TABLE)) {
            return;
        }

        Schema::create(self::SESSION_TABLE, function (Blueprint $table): void {
            $table->comment('Short-lived OJS Support Gateway identity sessions and one-time Chatwoot bindings.');
            $table->bigIncrements('id');
            $table->string('public_id', 64)->unique();
            $table->bigInteger('context_id')->index();
            $table->bigInteger('user_id')->index();
            $table->string('verification_method', 32);
            $table->string('assurance_level', 8);

            // The plaintext one-time binding token is never stored.
            $table->string('binding_token_hash', 64)->nullable()->unique();
            $table->dateTime('binding_expires_at')->nullable()->index();
            $table->dateTime('binding_consumed_at')->nullable();

            $table->string('chatwoot_account_id', 64)->nullable()->index();
            $table->string('chatwoot_contact_id', 64)->nullable()->index();
            $table->string('chatwoot_conversation_id', 64)->nullable()->index();

            $table->dateTime('created_at')->index();
            $table->dateTime('last_used_at')->index();
            $table->dateTime('idle_expires_at')->index();
            $table->dateTime('absolute_expires_at')->index();
            $table->dateTime('revoked_at')->nullable()->index();

            $table->index(['context_id', 'user_id'], 'cw_support_session_context_user');
            $table->index(
                ['context_id', 'chatwoot_account_id', 'chatwoot_contact_id', 'chatwoot_conversation_id'],
                'cw_support_session_conversation_binding'
            );
        });
    }

    /**
     * One shared table for both PIN and secure-link challenges — one
     * challenge engine, not two verification systems. Never stores a
     * plaintext PIN/token or a plaintext claimed email; once the account is
     * resolved, the OJS user ID is enough internally (see docs/v2/ADRS.md
     * ADR-005).
     */
    private function upChallenges(): void
    {
        if (Schema::hasTable(self::CHALLENGE_TABLE)) {
            return;
        }

        Schema::create(self::CHALLENGE_TABLE, function (Blueprint $table): void {
            $table->comment('One-time external verification challenges (PIN or secure link) for the v2 Support Gateway.');
            $table->bigIncrements('id');
            $table->string('public_reference', 64)->unique();
            $table->bigInteger('context_id')->index();
            $table->bigInteger('user_id')->index();
            $table->string('purpose', 32);
            $table->string('method', 16);

            $table->string('chatwoot_account_id', 64);
            $table->string('chatwoot_contact_id', 64);
            $table->string('chatwoot_conversation_id', 64);

            // Never the plaintext PIN/token — HMAC (PIN, keyed by a pepper
            // never stored in this table) or SHA-256 (high-entropy link token).
            $table->string('secret_hash', 128);

            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->dateTime('last_attempt_at')->nullable();

            $table->dateTime('created_at')->index();
            $table->dateTime('expires_at')->index();
            $table->dateTime('consumed_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('superseded_at')->nullable();

            $table->index(
                ['context_id', 'chatwoot_account_id', 'chatwoot_contact_id', 'chatwoot_conversation_id', 'purpose'],
                'cw_challenge_conversation_purpose'
            );
            $table->index(['context_id', 'user_id', 'created_at'], 'cw_challenge_identity_rate');
        });
    }

    /**
     * One row per provisioned remote Chatwoot/Captain resource, keyed by
     * (context, locale, resource type) — never keyed by name/URL, since a
     * name/URL match is never proof of plugin ownership (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
     * §6 Captain provisioning). `resource_type` is a coarse category
     * (`captain_document`, `captain_custom_tool`, ...); `resource_key`
     * disambiguates multiple resources of the same type (each canonical
     * Custom Tool has its own key, e.g. `ojs_diagnose_submission` —
     * Documents have exactly one per context/locale today, so they use
     * the empty string). Reused, not re-migrated, for future resource
     * types (Scenarios) that follow the same create-or-sync/
     * fingerprint-compare shape.
     */
    private function upKnowledgeSync(): void
    {
        if (Schema::hasTable(self::KNOWLEDGE_SYNC_TABLE)) {
            return;
        }

        Schema::create(self::KNOWLEDGE_SYNC_TABLE, function (Blueprint $table): void {
            $table->comment('Local ownership/fingerprint record for provisioned Chatwoot Captain resources (Documents, Custom Tools, Scenarios).');
            $table->bigIncrements('id');
            $table->bigInteger('context_id')->index();
            $table->string('locale', 28);
            $table->string('resource_type', 32);
            $table->string('resource_key', 64)->default('');
            $table->string('remote_resource_id', 64)->nullable();
            $table->string('last_successful_fingerprint', 64)->nullable();
            $table->dateTime('last_successful_sync_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->dateTime('updated_at')->index();

            $table->unique(['context_id', 'locale', 'resource_type', 'resource_key'], 'cw_knowledge_sync_identity');
        });
    }

    /**
     * Persisted Support API allow/deny audit trail (docs/v2/TASKLIST.md
     * AUD-001), replacing the `error_log()` placeholder sink
     * (`ErrorLogSupportApiAuditLogger`). Deliberately only the fields
     * `SupportApiRequestResolver` already safely records today
     * (`correlation_id`/`endpoint`/`context_id`/`decision`/`reason`/
     * `assurance`) — never a PIN, token, or other secret, since none of
     * those are ever passed to `SupportApiAuditLoggerInterface::record()`
     * in the first place (see `DatabaseSupportApiAuditLogger`'s own
     * allowlist, which drops any unrecognized key defensively).
     */
    private function upAuditLog(): void
    {
        if (Schema::hasTable(self::AUDIT_LOG_TABLE)) {
            return;
        }

        Schema::create(self::AUDIT_LOG_TABLE, function (Blueprint $table): void {
            $table->comment('Persisted Support API allow/deny audit trail.');
            $table->bigIncrements('id');
            $table->string('correlation_id', 64)->index();
            $table->bigInteger('context_id')->index();
            $table->string('endpoint', 64);
            $table->string('decision', 8);
            $table->string('reason', 64);
            $table->string('assurance', 8)->nullable();
            $table->dateTime('created_at')->index();
        });
    }

    /**
     * Persisted Event Bridge v2 delivery queue (docs/v2/TASKLIST.md
     * EVT-011/EVT-014) — the "queued delivery" stage of `OJS Hook ->
     * SupportEvent -> policy/filter -> queued delivery -> Chatwoot`
     * (docs/v2/ARCHITECTURE.md §3.9). `idempotency_key` is unique: a
     * `SupportEvent`'s own deterministic key (EVT-002) means a retried
     * enqueue of the same real occurrence is naturally a no-op at the DB
     * level, not something calling code needs to check first.
     *
     * `attributes` stores the event's own safe fact payload verbatim (see
     * `SupportEvent::attributes()`'s own contract — never PII, since
     * nothing upstream ever puts PII into a SupportEvent). `status`
     * lifecycle: `pending` -> `delivered` or `failed` (a `failed` row past
     * `max_attempts` is effectively the dead letter — no separate table,
     * consistent with "one status column, not two systems" already used
     * elsewhere in this schema).
     */
    private function upEventQueue(): void
    {
        if (Schema::hasTable(self::EVENT_QUEUE_TABLE)) {
            return;
        }

        Schema::create(self::EVENT_QUEUE_TABLE, function (Blueprint $table): void {
            $table->comment('Persisted Event Bridge v2 delivery queue.');
            $table->bigIncrements('id');
            $table->string('idempotency_key', 64)->unique();
            $table->string('event_type', 64)->index();
            $table->bigInteger('context_id')->index();
            $table->string('resource_type', 32);
            $table->bigInteger('resource_id');
            $table->text('attributes')->nullable();
            $table->string('delivery_mode', 32);
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('occurred_at');
            $table->dateTime('created_at')->index();
            $table->dateTime('run_after')->nullable()->index();
            $table->dateTime('delivered_at')->nullable();
            $table->string('last_error_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::EVENT_QUEUE_TABLE);
        Schema::dropIfExists(self::AUDIT_LOG_TABLE);
        Schema::dropIfExists(self::KNOWLEDGE_SYNC_TABLE);
        Schema::dropIfExists(self::CHALLENGE_TABLE);
        Schema::dropIfExists(self::SESSION_TABLE);
    }
}
