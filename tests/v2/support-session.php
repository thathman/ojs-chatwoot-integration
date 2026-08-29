<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;

function sessionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class InMemorySupportSessionRepository implements SupportSessionRepositoryInterface
{
    /** @var array<string,SupportSession> */
    public array $sessions = [];

    public function create(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
    public function save(SupportSession $session): void { $this->sessions[$session->publicId()] = $session; }
    public function findByPublicId(string $publicId): ?SupportSession { return $this->sessions[$publicId] ?? null; }

    public function claimBindingToken(
        string $bindingTokenHash,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        int $now,
        int $idleExpiresAt
    ): ?SupportSession {
        foreach ($this->sessions as $publicId => $session) {
            if (
                $session->contextId() !== $contextId
                || $session->userId() !== $userId
                || $session->bindingTokenHash() !== $bindingTokenHash
                || !$session->bindingAvailable($now)
            ) {
                continue;
            }

            foreach ($this->sessions as $otherId => $other) {
                if (
                    $otherId !== $publicId
                    && !$other->isRevoked()
                    && $other->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)
                ) {
                    $this->sessions[$otherId] = $other->revoked($now);
                }
            }

            $bound = $session->withConversationBinding(
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId,
                $now,
                min($idleExpiresAt, $session->absoluteExpiresAt())
            );
            $this->sessions[$publicId] = $bound;
            return $bound;
        }
        return null;
    }

    public function findByConversationBinding(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        $matches = [];
        foreach ($this->sessions as $session) {
            if (!$session->isRevoked() && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)) {
                $matches[] = $session;
            }
        }
        usort($matches, fn (SupportSession $a, SupportSession $b): int => $b->createdAt() <=> $a->createdAt());
        return $matches[0] ?? null;
    }

    public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void
    {
        foreach ($this->sessions as $publicId => $session) {
            if ($session->contextId() === $contextId && $session->userId() === $userId && !$session->isBound() && !$session->isRevoked()) {
                $this->sessions[$publicId] = $session->revoked($now);
            }
        }
    }

    public function purgeExpired(int $now): int
    {
        $count = 0;
        foreach ($this->sessions as $publicId => $session) {
            if ($session->isExpired($now)) {
                unset($this->sessions[$publicId]);
                $count++;
            }
        }
        return $count;
    }
}

$now = 1_800_000_000;
$repo = new InMemorySupportSessionRepository();
$service = new SupportSessionService($repo, static function () use (&$now): int { return $now; });
$authenticated = new SupportContext(7, 'journal-a', 42, [16, 4096], 'workflow', 'index', 'en');
$guest = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');

$guestRejected = false;
try {
    $service->bootstrapAuthenticated($guest);
} catch (LogicException $e) {
    $guestRejected = true;
}
sessionCheck($guestRejected, 'guest context must not bootstrap an authenticated support session');

$bootstrap = $service->bootstrapAuthenticated($authenticated);
$stored = $repo->findByPublicId($bootstrap->sessionRef());
sessionCheck($stored !== null, 'authenticated bootstrap should be persisted');
sessionCheck($stored?->assuranceLevel() === 'v2', 'authenticated OJS session should establish V2 assurance');
sessionCheck($stored?->verificationMethod() === 'authenticated_session', 'verification method should record authenticated session');
sessionCheck($stored?->bindingTokenHash() === hash('sha256', $bootstrap->bindingToken()), 'repository should store only binding token hash');
sessionCheck($stored?->bindingTokenHash() !== $bootstrap->bindingToken(), 'plaintext binding token must not be persisted');
sessionCheck($bootstrap->browserPayload()['contract'] === 'one_time_binding', 'bootstrap payload should state one-time binding contract');

$crossContext = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 8, 42, '1', '100', '500');
sessionCheck($crossContext === null, 'binding token must be journal/context bound');
sessionCheck($repo->findByPublicId($bootstrap->sessionRef())?->bindingAvailable($now) === true, 'failed cross-context bind must not consume token');

$crossUser = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 43, '1', '100', '500');
sessionCheck($crossUser === null, 'binding token must be bound to the currently authenticated OJS user');
sessionCheck($repo->findByPublicId($bootstrap->sessionRef())?->bindingAvailable($now) === true, 'failed cross-user bind must not consume token');

$bound = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
sessionCheck($bound !== null && $bound->isBound(), 'valid token should bind exact Chatwoot conversation');
sessionCheck($bound?->bindingTokenHash() === null, 'consumed binding token hash should be removed from active session');
sessionCheck($bound?->bindingConsumedAt() === $now, 'binding consumption time should be recorded');

$replay = $service->bindAuthenticatedBootstrap($bootstrap->bindingToken(), 7, 42, '1', '100', '500');
sessionCheck($replay === null, 'one-time binding token must reject replay');

$resolved = $service->resolveConversation(7, '1', '100', '500');
sessionCheck($resolved?->userId() === 42, 'exact bound conversation should resolve server-side OJS identity');
sessionCheck($service->resolveConversation(7, '1', '100', '501') === null, 'different conversation must not reuse support session');
sessionCheck($service->resolveConversation(7, '1', '101', '500') === null, 'different contact must not reuse support session');
sessionCheck($service->resolveConversation(8, '1', '100', '500') === null, 'different journal must not reuse support session');

$now += 5;
$replacementBootstrap = $service->bootstrapAuthenticated($authenticated);
$replacement = $service->bindAuthenticatedBootstrap($replacementBootstrap->bindingToken(), 7, 42, '1', '100', '500');
sessionCheck($replacement !== null, 'fresh authenticated bootstrap should bind same conversation');
sessionCheck($repo->findByPublicId($bootstrap->sessionRef())?->isRevoked() === true, 'new binding should rotate/revoke older conversation session');

sessionCheck($service->revoke($replacementBootstrap->sessionRef()) === true, 'explicit revocation should succeed');
sessionCheck($service->resolveConversation(7, '1', '100', '500') === null, 'revoked session must not resolve');
sessionCheck($service->revoke($replacementBootstrap->sessionRef()) === false, 'revocation should be idempotent-safe');

$shortNow = 2_000_000_000;
$shortRepo = new InMemorySupportSessionRepository();
$shortService = new SupportSessionService($shortRepo, static function () use (&$shortNow): int { return $shortNow; }, 10, 20, 5);
$shortBootstrap = $shortService->bootstrapAuthenticated($authenticated);
$shortNow += 6;
sessionCheck(
    $shortService->bindAuthenticatedBootstrap($shortBootstrap->bindingToken(), 7, 42, '1', '100', '700') === null,
    'expired one-time binding token must fail closed'
);

$freshBootstrap = $shortService->bootstrapAuthenticated($authenticated);
$boundAt = $shortNow;
$freshBound = $shortService->bindAuthenticatedBootstrap($freshBootstrap->bindingToken(), 7, 42, '1', '100', '701');
sessionCheck($freshBound !== null, 'fresh short-lived bootstrap should bind');
$absolute = $freshBound?->absoluteExpiresAt() ?? 0;
$shortNow = $boundAt + 9;
$touched = $shortService->resolveConversation(7, '1', '100', '701');
sessionCheck(($touched?->idleExpiresAt() ?? 0) <= $absolute, 'idle extension must never exceed absolute expiry');
$shortNow = $absolute;
sessionCheck($shortService->resolveConversation(7, '1', '100', '701') === null, 'absolute expiry must terminate support session');

$migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
sessionCheck(str_contains($migrationSource, "binding_token_hash"), 'migration must store hashed binding token');
sessionCheck(!str_contains($migrationSource, "->string('binding_token',"), 'migration must never create plaintext binding token column');

$repositorySource = (string) file_get_contents($root . '/classes/v2/Session/DatabaseSupportSessionRepository.php');
sessionCheck(str_contains($repositorySource, 'lockForUpdate()'), 'database binding claim must lock row transactionally');
sessionCheck(str_contains($repositorySource, "where('user_id', \$userId)"), 'database binding claim must constrain current OJS user');

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
sessionCheck(str_contains($pluginSource, 'getInstallMigration'), 'live plugin must register support session migration');
sessionCheck(str_contains($pluginSource, 'bootstrapAuthenticatedSupportSession'), 'live plugin must silently bootstrap authenticated OJS session');

fwrite(STDOUT, "Support session tests passed\n");
