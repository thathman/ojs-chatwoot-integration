<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Task;

use APP\plugins\generic\chatwootIntegration\classes\v2\Session\DatabaseSupportSessionRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\DatabaseVerificationChallengeRepository;
use PKP\scheduledTask\ScheduledTask;

/**
 * Purges expired support sessions and verification challenges — wires the
 * `purgeExpired()` methods both repositories have always had into an
 * actual scheduled lifecycle (docs/v2/TASKLIST.md IDN-017, a long-flagged
 * known gap: "no scheduled task ever calls `purgeExpired()` — expired
 * rows are never actually swept").
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
    public function getName(): string
    {
        return 'Chatwoot Support Gateway: purge expired sessions/challenges';
    }

    protected function executeActions(): bool
    {
        try {
            $now = time();
            $sessionsPurged = (new DatabaseSupportSessionRepository())->purgeExpired($now);
            $challengesPurged = (new DatabaseVerificationChallengeRepository())->purgeExpired($now);
            $this->addExecutionLogEntry(sprintf(
                'Purged %d expired support session(s) and %d expired verification challenge(s).',
                $sessionsPurged,
                $challengesPurged
            ));
            return true;
        } catch (\Throwable $e) {
            $this->addExecutionLogEntry('Purge failed: ' . $e->getMessage());
            return false;
        }
    }
}
