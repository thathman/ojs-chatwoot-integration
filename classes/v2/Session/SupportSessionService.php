<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Session;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
use Closure;
use LogicException;

final class SupportSessionService
{
    public const METHOD_AUTHENTICATED_SESSION = 'authenticated_session';
    public const METHOD_EXTERNAL_PIN = 'external_pin';
    public const METHOD_EXTERNAL_LINK = 'external_link';
    public const ASSURANCE_AUTHENTICATED_SESSION = 'v2';

    private Closure $clock;

    public function __construct(
        private SupportSessionRepositoryInterface $repository,
        ?callable $clock = null,
        private int $idleTtlSeconds = 1800,
        private int $absoluteTtlSeconds = 3600,
        private int $bindingTtlSeconds = 300
    ) {
        if ($this->bindingTtlSeconds <= 0 || $this->idleTtlSeconds <= 0 || $this->absoluteTtlSeconds <= 0) {
            throw new LogicException('Support session TTLs must be positive.');
        }
        if ($this->bindingTtlSeconds > $this->absoluteTtlSeconds || $this->idleTtlSeconds > $this->absoluteTtlSeconds) {
            throw new LogicException('Binding/idle TTL cannot exceed absolute session TTL.');
        }
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn (): int => time();
    }

    public function bootstrapAuthenticated(SupportContext $context): SupportSessionBootstrap
    {
        if (!$context->isAuthenticated() || !$context->userId()) {
            throw new LogicException('Authenticated OJS context is required to bootstrap a support session.');
        }

        $now = $this->now();
        $this->repository->revokeActiveUnboundForUser($context->contextId(), $context->userId(), $now);

        $publicId = bin2hex(random_bytes(16));
        $bindingToken = $this->randomToken(32);
        $bindingTokenHash = hash('sha256', $bindingToken);
        $bindingExpiresAt = $now + $this->bindingTtlSeconds;
        $absoluteExpiresAt = $now + $this->absoluteTtlSeconds;
        $idleExpiresAt = min($now + $this->idleTtlSeconds, $absoluteExpiresAt);

        $this->repository->create(new SupportSession(
            $publicId,
            $context->contextId(),
            $context->userId(),
            self::METHOD_AUTHENTICATED_SESSION,
            self::ASSURANCE_AUTHENTICATED_SESSION,
            $bindingTokenHash,
            $bindingExpiresAt,
            null,
            null,
            null,
            null,
            $now,
            $now,
            $idleExpiresAt,
            $absoluteExpiresAt,
            null
        ));

        return new SupportSessionBootstrap(
            $publicId,
            $bindingToken,
            self::ASSURANCE_AUTHENTICATED_SESSION,
            $bindingExpiresAt,
            $absoluteExpiresAt
        );
    }

    public function bindAuthenticatedBootstrap(
        string $bindingToken,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        $bindingToken = trim($bindingToken);
        $chatwootAccountId = trim($chatwootAccountId);
        $chatwootContactId = trim($chatwootContactId);
        $chatwootConversationId = trim($chatwootConversationId);

        if (
            $bindingToken === ''
            || $contextId <= 0
            || $userId <= 0
            || $chatwootAccountId === ''
            || $chatwootContactId === ''
            || $chatwootConversationId === ''
        ) {
            return null;
        }

        $now = $this->now();
        return $this->repository->claimBindingToken(
            hash('sha256', $bindingToken),
            $contextId,
            $userId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $now,
            $now + $this->idleTtlSeconds
        );
    }

    /**
     * Establishes a normal V2 session directly from a just-consumed
     * external verification challenge — deliberately not the same
     * bootstrap-then-bind two-step dance bootstrapAuthenticated()/
     * bindAuthenticatedBootstrap() use, since the Chatwoot conversation is
     * already known (it was validated when the challenge was requested),
     * so there is nothing left to separately "claim." Successful
     * verification establishes V2, never V3 — resource-scoped assurance is
     * still a separate step (submissionVerify) even if the verification
     * purpose was submission-related (see docs/v2/ADRS.md ADR-005).
     */
    public function establishFromExternalVerification(
        int $contextId,
        int $userId,
        string $method,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): SupportSession {
        $now = $this->now();
        $this->repository->revokeActiveUnboundForUser($contextId, $userId, $now);

        $publicId = bin2hex(random_bytes(16));
        $absoluteExpiresAt = $now + $this->absoluteTtlSeconds;
        $idleExpiresAt = min($now + $this->idleTtlSeconds, $absoluteExpiresAt);

        $session = new SupportSession(
            $publicId,
            $contextId,
            $userId,
            $method,
            self::ASSURANCE_AUTHENTICATED_SESSION,
            null,
            null,
            null,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $now,
            $now,
            $idleExpiresAt,
            $absoluteExpiresAt,
            null
        );

        $this->repository->create($session);
        $this->repository->revokeOthersForConversation($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $publicId, $now);

        return $session;
    }

    public function resolveConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        if (
            $contextId <= 0
            || trim($chatwootAccountId) === ''
            || trim($chatwootContactId) === ''
            || trim($chatwootConversationId) === ''
        ) {
            return null;
        }

        $session = $this->repository->findByConversationBinding(
            $contextId,
            trim($chatwootAccountId),
            trim($chatwootContactId),
            trim($chatwootConversationId)
        );

        $now = $this->now();
        if (!$session || $session->isExpired($now) || !$session->matchesConversationBinding(
            $contextId,
            trim($chatwootAccountId),
            trim($chatwootContactId),
            trim($chatwootConversationId)
        )) {
            return null;
        }

        $idleExpiresAt = min($now + $this->idleTtlSeconds, $session->absoluteExpiresAt());
        $session = $session->touched($now, $idleExpiresAt);
        $this->repository->save($session);
        return $session;
    }

    public function revoke(string $publicId): bool
    {
        $session = $this->repository->findByPublicId(trim($publicId));
        if (!$session || $session->isRevoked()) {
            return false;
        }
        $this->repository->save($session->revoked($this->now()));
        return true;
    }

    public function purgeExpired(): int
    {
        return $this->repository->purgeExpired($this->now());
    }
    private function now(): int
    {
        return (int) ($this->clock)();
    }
    private function randomToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
