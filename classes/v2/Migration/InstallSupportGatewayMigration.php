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

    public function up(): void
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

    public function down(): void
    {
        Schema::dropIfExists(self::SESSION_TABLE);
    }
}
