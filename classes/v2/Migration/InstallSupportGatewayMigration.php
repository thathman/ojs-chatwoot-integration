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

    public function up(): void
    {
        $this->upSessions();
        $this->upChallenges();
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

    public function down(): void
    {
        Schema::dropIfExists(self::CHALLENGE_TABLE);
        Schema::dropIfExists(self::SESSION_TABLE);
    }
}
