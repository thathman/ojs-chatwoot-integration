<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * Immutable one-time external verification challenge — shared by both PIN
 * and secure-link methods (see docs/v2/ADRS.md ADR-005). Never carries a
 * plaintext PIN/token or a plaintext claimed email.
 */
final class VerificationChallenge
{
    public const METHOD_PIN = 'pin';
    public const METHOD_LINK = 'link';

    public const PURPOSE_ACCOUNT_SUPPORT = 'account_support';
    public const PURPOSE_SUBMISSION_SUPPORT = 'submission_support';

    public const PURPOSES = [self::PURPOSE_ACCOUNT_SUPPORT, self::PURPOSE_SUBMISSION_SUPPORT];

    public function __construct(
        private string $publicReference,
        private int $contextId,
        private int $userId,
        private string $purpose,
        private string $method,
        private string $chatwootAccountId,
        private string $chatwootContactId,
        private string $chatwootConversationId,
        private string $secretHash,
        private int $attemptCount,
        private int $maxAttempts,
        private ?int $lastAttemptAt,
        private int $createdAt,
        private int $expiresAt,
        private ?int $consumedAt = null,
        private ?int $revokedAt = null,
        private ?int $supersededAt = null
    ) {
    }

    public function publicReference(): string
    {
        return $this->publicReference;
    }
    public function contextId(): int
    {
        return $this->contextId;
    }
    public function userId(): int
    {
        return $this->userId;
    }
    public function purpose(): string
    {
        return $this->purpose;
    }
    public function method(): string
    {
        return $this->method;
    }
    public function chatwootAccountId(): string
    {
        return $this->chatwootAccountId;
    }
    public function chatwootContactId(): string
    {
        return $this->chatwootContactId;
    }
    public function chatwootConversationId(): string
    {
        return $this->chatwootConversationId;
    }
    public function secretHash(): string
    {
        return $this->secretHash;
    }
    public function attemptCount(): int
    {
        return $this->attemptCount;
    }
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
    public function lastAttemptAt(): ?int
    {
        return $this->lastAttemptAt;
    }
    public function createdAt(): int
    {
        return $this->createdAt;
    }
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
    public function consumedAt(): ?int
    {
        return $this->consumedAt;
    }
    public function revokedAt(): ?int
    {
        return $this->revokedAt;
    }
    public function supersededAt(): ?int
    {
        return $this->supersededAt;
    }

    public function isConsumed(): bool
    {
        return $this->consumedAt !== null;
    }
    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }
    public function isSuperseded(): bool
    {
        return $this->supersededAt !== null;
    }

    public function isExpired(int $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function isLockedOut(): bool
    {
        return $this->attemptCount >= $this->maxAttempts;
    }

    /** Usable right now for a fresh confirmation attempt. */
    public function isActive(int $now): bool
    {
        return !$this->isConsumed()
            && !$this->isRevoked()
            && !$this->isSuperseded()
            && !$this->isExpired($now)
            && !$this->isLockedOut();
    }

    public function matchesConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): bool {
        return $this->contextId === $contextId
            && hash_equals($this->chatwootAccountId, $chatwootAccountId)
            && hash_equals($this->chatwootContactId, $chatwootContactId)
            && hash_equals($this->chatwootConversationId, $chatwootConversationId);
    }

    public function matchesPurpose(string $purpose): bool
    {
        return hash_equals($this->purpose, $purpose);
    }

    /** @return array<string,mixed> Internal persistence record; never expose to browser/LLM. */
    public function toPersistenceRecord(): array
    {
        return [
            'public_reference' => $this->publicReference,
            'context_id' => $this->contextId,
            'user_id' => $this->userId,
            'purpose' => $this->purpose,
            'method' => $this->method,
            'chatwoot_account_id' => $this->chatwootAccountId,
            'chatwoot_contact_id' => $this->chatwootContactId,
            'chatwoot_conversation_id' => $this->chatwootConversationId,
            'secret_hash' => $this->secretHash,
            'attempt_count' => $this->attemptCount,
            'max_attempts' => $this->maxAttempts,
            'last_attempt_at' => $this->lastAttemptAt,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'consumed_at' => $this->consumedAt,
            'revoked_at' => $this->revokedAt,
            'superseded_at' => $this->supersededAt,
        ];
    }
}
