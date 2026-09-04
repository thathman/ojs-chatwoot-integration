<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\DatabaseSupportApiAuditLogger;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SafeExceptionMessage;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\DatabaseSupportSessionRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\DatabaseVerificationChallengeRepository;
use PKP\scheduledTask\ScheduledTask;

/**
 * Purges expired support sessions/verification challenges and enforces
 * audit-log retention — wires the `purgeExpired()` methods both
 * repositories have always had into an actual scheduled lifecycle
 * (docs/v2/TASKLIST.md IDN-017, a long-flagged known gap: "no scheduled
 * task ever calls `purgeExpired()` — expired rows are never actually
 * swept") and closes AUD-007 (configurable retention/purge) for the
 * persisted audit sink from the same run.
 *
 * Verified against a real local checkout of `pkp-lib`
 * (`classes/scheduledTask/ScheduledTask.php`'s actual abstract contract
 * is `executeActions(): bool`, not `execute()` — `execute()` is a
 * concrete wrapper that adds start/stop log entries around it).
 *
 * Registered via `ChatwootIntegrationV2Plugin implements HasTaskScheduler`
 * — see `PKP\plugins\interfaces\HasTaskScheduler`,
 * `PKP\scheduledTask\PKPScheduler::registerPluginSchedules()`.
 */
final class PurgeExpiredSupportDataTask extends ScheduledTask
{
    /**
     * Deliberately a class constant (configuration, not wire-contract
     * semantics — same precedent as the verification challenge TTL):
     * how long a Support API audit row is kept before this task deletes
     * it. 90 days is a starting default, not a documented guarantee.
     */
    private const AUDIT_LOG_RETENTION_SECONDS = 90 * 24 * 60 * 60;

    public function getName(): string
    {
        return 'Chatwoot Support Gateway: purge expired sessions/challenges/audit log';
    }

    protected function executeActions(): bool
    {
        try {
            $now = time();
            $sessionsPurged = (new DatabaseSupportSessionRepository())->purgeExpired($now);
            $challengesPurged = (new DatabaseVerificationChallengeRepository())->purgeExpired($now);
            $auditRowsPurged = (new DatabaseSupportApiAuditLogger())->purgeOlderThan($now, self::AUDIT_LOG_RETENTION_SECONDS);
            $this->addExecutionLogEntry(sprintf(
                'Purged %d expired support session(s), %d expired verification challenge(s), and %d audit log row(s) past retention.',
                $sessionsPurged,
                $challengesPurged,
                $auditRowsPurged
            ));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('Purge failed: ' . SafeExceptionMessage::describe($e));
            return false;
        }
    }
}
