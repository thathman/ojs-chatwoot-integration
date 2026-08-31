<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Audit;

use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

/**
 * Persisted Support API allow/deny audit sink (docs/v2/TASKLIST.md
 * AUD-001), replacing the `error_log()` placeholder
 * (`ErrorLogSupportApiAuditLogger`).
 *
 * `record()` only ever writes an explicit allowlist of fields — never
 * "whatever the caller passed." This is defense in depth (AUD-006): even
 * though no current caller passes a PIN/token/secret into an audit event,
 * a future caller mistake can never leak one through this sink, because
 * an unrecognized key is silently dropped rather than persisted.
 */
final class DatabaseSupportApiAuditLogger implements SupportApiAuditLoggerInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this class never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::AUDIT_LOG_TABLE;
    }

    public function record(array $event): void
    {
        $correlationId = (string) ($event['correlationId'] ?? '');
        $contextId = (int) ($event['contextId'] ?? 0);
        $endpoint = (string) ($event['endpoint'] ?? '');
        $decision = (string) ($event['decision'] ?? '');
        $reason = (string) ($event['reason'] ?? '');
        if ($correlationId === '' || $endpoint === '' || $decision === '' || $reason === '') {
            return;
        }

        $assurance = $event['assurance'] ?? null;

        $row = [
            'correlation_id' => $correlationId,
            'context_id' => $contextId,
            'endpoint' => $endpoint,
            'decision' => $decision,
            'reason' => $reason,
            'assurance' => is_string($assurance) && $assurance !== '' ? $assurance : null,
        ];

        try {
            DB::table(self::table())->insert($row + ['created_at' => gmdate('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // An audit-write failure must never break the request it is
            // auditing — fall back to the same error_log line the
            // placeholder sink always used, so the event is not silently
            // lost. Logs only the already-allowlisted row, never the raw
            // $event, so a future caller mistake still can't leak a secret
            // through this degraded path either.
            error_log('[chatwoot-support-api-audit] ' . json_encode($row, JSON_UNESCAPED_SLASHES));
        }
    }

    /** @return int Rows deleted. */
    public function purgeOlderThan(int $now, int $retentionSeconds): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', $now - $retentionSeconds);
        return DB::table(self::table())->where('created_at', '<', $cutoff)->delete();
    }
}
