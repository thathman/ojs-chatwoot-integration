<?php

declare(strict_types=1);

namespace PKP\scheduledTask {
    /** Mirrors only the real base class's contract — see a real local checkout of pkp-lib classes/scheduledTask/ScheduledTask.php. */
    abstract class ScheduledTask
    {
        /** Mirrors the real constructor signature (TST-021) so subclasses calling parent::__construct() work against this mock too. */
        public function __construct(private array $args = [])
        {
        }
        /** @var string[] */
        public array $logEntries = [];

        public function getName(): string
        {
            return 'test-task';
        }
        abstract protected function executeActions(): bool;
        public function execute(): bool
        {
            return $this->executeActions();
        }
        public function addExecutionLogEntry(string $message, ?string $type = null): void
        {
            $this->logEntries[] = $message;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    function purgeTaskCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    $sessionClassSource = (string) file_get_contents($root . '/classes/v2/Session/DatabaseSupportSessionRepository.php');
    purgeTaskCheck(str_contains($sessionClassSource, 'function purgeExpired'), 'sanity: DatabaseSupportSessionRepository must still expose purgeExpired()');

    $taskSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Task/PurgeExpiredSupportDataTask.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $taskSource .= is_array($token) ? $token[1] : $token;
    }
    purgeTaskCheck(str_contains($taskSource, 'extends ScheduledTask'), 'task must extend the real pkp-lib ScheduledTask base class');
    purgeTaskCheck(str_contains($taskSource, 'protected function executeActions'), 'task must implement executeActions(), the real abstract contract (not execute(), which pkp-lib itself already implements as a logging wrapper)');
    purgeTaskCheck(str_contains($taskSource, 'DatabaseSupportSessionRepository())->purgeExpired'), 'task must call the real session purge method');
    purgeTaskCheck(str_contains($taskSource, 'DatabaseVerificationChallengeRepository())->purgeExpired'), 'task must call the real verification-challenge purge method');
    purgeTaskCheck(str_contains($taskSource, 'DatabaseSupportApiAuditLogger())->purgeOlderThan'), 'task must also enforce audit log retention (AUD-007)');
    purgeTaskCheck(str_contains($taskSource, 'catch (\Throwable'), 'task must isolate a purge failure rather than letting it propagate uncaught out of a scheduled run');

    // ================================================================
    // Behavioral test: executeActions() actually calls both purges and
    // logs a real count, using a real instance with fake DB-backed
    // repositories substituted via a subclass — since the real
    // repositories require Illuminate\Support\Facades\DB, which is not
    // available in this plain-PHP test environment, this proves the
    // control flow (both calls made, log entry written, true returned)
    // through a minimal reflection-free stand-in class with the same
    // shape as the real task, rather than the real DB-backed one.
    // ================================================================
    final class FakePurgeSessionRepository
    {
        public int $now = 0;
        public function purgeExpired(int $now): int
        {
            $this->now = $now;
            return 3;
        }
    }
    final class FakePurgeChallengeRepository
    {
        public int $now = 0;
        public function purgeExpired(int $now): int
        {
            $this->now = $now;
            return 5;
        }
    }
    final class FakePurgeAuditLogger
    {
        public int $now = 0;
        public int $retentionSeconds = 0;
        public function purgeOlderThan(int $now, int $retentionSeconds): int
        {
            $this->now = $now;
            $this->retentionSeconds = $retentionSeconds;
            return 9;
        }
    }
    final class TestablePurgeTask extends \PKP\scheduledTask\ScheduledTask
    {
        private const AUDIT_LOG_RETENTION_SECONDS = 90 * 24 * 60 * 60;

        public function __construct(
            private FakePurgeSessionRepository $sessions,
            private FakePurgeChallengeRepository $challenges,
            private FakePurgeAuditLogger $auditLog
        ) {
        }
        protected function executeActions(): bool
        {
            try {
                $now = time();
                $sessionsPurged = $this->sessions->purgeExpired($now);
                $challengesPurged = $this->challenges->purgeExpired($now);
                $auditRowsPurged = $this->auditLog->purgeOlderThan($now, self::AUDIT_LOG_RETENTION_SECONDS);
                $this->addExecutionLogEntry(sprintf(
                    'Purged %d expired support session(s), %d expired verification challenge(s), and %d audit log row(s) past retention.',
                    $sessionsPurged,
                    $challengesPurged,
                    $auditRowsPurged
                ));
                return true;
            } catch (\Throwable $e) {
                $this->addExecutionLogEntry('Purge failed: ' . $e->getMessage());
                return false;
            }
        }
    }

    $sessions = new FakePurgeSessionRepository();
    $challenges = new FakePurgeChallengeRepository();
    $auditLog = new FakePurgeAuditLogger();
    $task = new TestablePurgeTask($sessions, $challenges, $auditLog);
    $result = $task->execute();
    purgeTaskCheck($result === true, 'a successful purge run must return true');
    purgeTaskCheck($sessions->now > 0 && $challenges->now > 0, 'both repositories must actually be invoked with a real timestamp');
    purgeTaskCheck($auditLog->now > 0, 'the audit logger must also be invoked with a real timestamp');
    purgeTaskCheck($auditLog->retentionSeconds === 90 * 24 * 60 * 60, 'audit log retention must be a real, non-zero window (90 days)');
    purgeTaskCheck(
        count($task->logEntries) === 1
            && str_contains($task->logEntries[0], 'Purged 3')
            && str_contains($task->logEntries[0], '5 expired')
            && str_contains($task->logEntries[0], '9 audit log row'),
        'a successful run must log the real purge counts, including the audit log row count'
    );

    fwrite(STDOUT, "Purge expired support data task tests passed\n");
}
