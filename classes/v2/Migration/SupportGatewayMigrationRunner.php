<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Migration;

use Illuminate\Database\Migrations\Migration;

/**
 * HAR-016/MIG-003: what `ChatwootIntegrationV2Plugin::getInstallMigration()`
 * actually returns. PKP's real plugin install/upgrade hook
 * (`Plugin::updateSchema()`, `lib/pkp/classes/plugins/Plugin.php`)
 * supports exactly one `Migration` per plugin, invoked via `->up()` on
 * every install AND every version-bump upgrade (`installPluginVersion.php`)
 * — there is no per-version migration-chain hook in the real OJS 3.5
 * plugin API. This class is the additive-migration architecture that
 * fact allows: a fixed, append-only ordered list of small, independently
 * idempotent `Migration` steps, run in sequence. Adding new schema means
 * adding a new step class and appending it here — never editing an
 * earlier step's already-shipped `up()`/`down()` body.
 *
 * `InstallSupportGatewayMigration` is the exact `2.0.0.0` baseline and
 * MUST remain the first step, never removed or reordered — every
 * already-installed real database has already run it.
 */
final class SupportGatewayMigrationRunner extends Migration
{
    /** @return Migration[] In the exact order they must run. */
    private function steps(): array
    {
        return [
            new InstallSupportGatewayMigration(),
            new AddFaqCacheTableMigration(),
        ];
    }

    public function up(): void
    {
        foreach ($this->steps() as $step) {
            $step->up();
        }
    }

    /** Reverse order, mirroring each step's own drop-dependencies-first discipline. */
    public function down(): void
    {
        foreach (array_reverse($this->steps()) as $step) {
            $step->down();
        }
    }
}
