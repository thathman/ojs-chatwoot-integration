<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KNO-011: the first genuinely NEW table added after the immutable
 * `2.0.0.0` baseline (`InstallSupportGatewayMigration`) — a local,
 * journal/locale-scoped cache of approved Chatwoot Captain FAQ content
 * (`Captain::AssistantResponse` rows — real Chatwoot source, verified
 * against the real `chatwoot/chatwoot` `develop` source: every
 * `AssistantResponse` row *is* approved by construction, `enum status:
 * { approved: 1 }` is the model's only status). Populated by a
 * periodic sync task, never by a live request — `ApprovedFaqKnowledgeProvider`
 * only ever reads this table, so an anonymous knowledge-page load
 * never makes a live Chatwoot call and never blocks on Chatwoot being
 * reachable.
 *
 * HAR-016/MIG-003: deliberately its own migration class, run by
 * `SupportGatewayMigrationRunner` after `InstallSupportGatewayMigration`,
 * rather than added by editing that class's already-shipped `2.0.0.0`
 * `up()`/`down()` bodies. Each future new table follows this same
 * pattern: a new file, appended to the runner's list — never a rewrite
 * of an earlier step's body.
 */
final class AddFaqCacheTableMigration extends Migration
{
    public const FAQ_CACHE_TABLE = 'chatwoot_support_faq_cache';

    public function up(): void
    {
        if (Schema::hasTable(self::FAQ_CACHE_TABLE)) {
            return;
        }

        Schema::create(self::FAQ_CACHE_TABLE, function (Blueprint $table): void {
            $table->comment('Local cache of approved Chatwoot Captain FAQ content, synced periodically — never read live from Chatwoot.');
            $table->bigIncrements('id');
            $table->bigInteger('context_id')->index();
            $table->string('locale', 28);
            $table->string('external_id', 64);
            $table->text('question');
            $table->text('answer');
            $table->dateTime('synced_at')->index();

            $table->unique(['context_id', 'locale', 'external_id'], 'cw_faq_cache_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::FAQ_CACHE_TABLE);
    }
}
