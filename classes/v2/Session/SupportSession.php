<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Session;

/**
 * Immutable short-lived support identity session.
 */
final class SupportSession
{
    public function __construct(
        private string $publicId,
        private int $contextId,
        private int $userId,
        private string $verificationMethod,
        private string $assuranceLevel,
        private ?string $bindingTokenHash,
        private ?int $bindingExpiresAt,
        private ?int $bindingConsumedAt,
        private ?string $chatwootAccountId,
        private ?string $chatwootContactId,
        private ?string $chatwootConversationId,
        private int $createdAt,
        private int $lastUsedAt,
        private int $idleExpiresAt,
        private int $absoluteExpiresAt,
        private ?int $revokedAt = null
    ) {
    }

    public function publicId(): string
    {
        return $this->publicId;
    }
    public function contextId(): int
    {
        return $this->contextId;
    }
    public function userId(): int
    {
        return $this->userId;
    }
    public function verificationMethod(): string
    {
        return $this->verificationMethod;
    }
    public function assuranceLevel(): string
    {
        return $this->assuranceLevel;
    }
    public function bindingTokenHash(): ?string
    {
        return $this->bindingTokenHash;
    }
    public function bindingExpiresAt(): ?int
    {
        return $this->bindingExpiresAt;
    }
    public function bindingConsumedAt(): ?int
    {
        return $this->bindingConsumedAt;
    }
    public function chatwootAccountId(): ?string
    {
        return $this->chatwootAccountId;
    }
    public function chatwootContactId(): ?string
    {
        return $this->chatwootContactId;
    }
    public function chatwootConversationId(): ?string
    {
        return $this->chatwootConversationId;
    }
    public function createdAt(): int
    {
        return $this->createdAt;
    }
    public function lastUsedAt(): int
    {
        return $this->lastUsedAt;
    }
    public function idleExpiresAt(): int
    {
        return $this->idleExpiresAt;
    }
    public function absoluteExpiresAt(): int
    {
        return $this->absoluteExpiresAt;
    }
    public function revokedAt(): ?int
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(int $now): bool
    {
        return $this->isRevoked() || $now >= $this->idleExpiresAt || $now >= $this->absoluteExpiresAt;
    }

    public function bindingAvailable(int $now): bool
    {
        return !$this->isExpired($now)
            && $this->bindingTokenHash !== null
            && $this->bindingConsumedAt === null
            && $this->bindingExpiresAt !== null
            && $now < $this->bindingExpiresAt;
    }

    public function isBound(): bool
    {
        return $this->chatwootAccountId !== null
            && $this->chatwootContactId !== null
            && $this->chatwootConversationId !== null;
    }

    public function matchesConversationBinding(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): bool {
        return $this->contextId === $contextId
            && hash_equals((string) $this->chatwootAccountId, $chatwootAccountId)
            && hash_equals((string) $this->chatwootContactId, $chatwootContactId)
            && hash_equals((string) $this->chatwootConversationId, $chatwootConversationId);
    }

    public function withConversationBinding(
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        int $now,
        int $idleExpiresAt
    ): self {
        return new self(
            $this->publicId,
            $this->contextId,
            $this->userId,
            $this->verificationMethod,
            $this->assuranceLevel,
            null,
            null,
            $now,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $this->createdAt,
            $now,
            $idleExpiresAt,
            $this->absoluteExpiresAt,
            $this->revokedAt
        );
    }

    public function touched(int $now, int $idleExpiresAt): self
    {
        return new self(
            $this->publicId,
            $this->contextId,
            $this->userId,
            $this->verificationMethod,
            $this->assuranceLevel,
            $this->bindingTokenHash,
            $this->bindingExpiresAt,
            $this->bindingConsumedAt,
            $this->chatwootAccountId,
            $this->chatwootContactId,
            $this->chatwootConversationId,
            $this->createdAt,
            $now,
            $idleExpiresAt,
            $this->absoluteExpiresAt,
            $this->revokedAt
        );
    }

    public function revoked(int $now): self
    {
        return new self(
            $this->publicId,
            $this->contextId,
            $this->userId,
            $this->verificationMethod,
            $this->assuranceLevel,
            null,
            null,
            $this->bindingConsumedAt,
            $this->chatwootAccountId,
            $this->chatwootContactId,
            $this->chatwootConversationId,
            $this->createdAt,
            $this->lastUsedAt,
            $this->idleExpiresAt,
            $this->absoluteExpiresAt,
            $now
        );
    }

    /** @return array<string,mixed> Internal persistence record; never expose to browser/LLM. */
    public function toPersistenceRecord(): array
    {
        return [
            'public_id' => $this->publicId,
            'context_id' => $this->contextId,
            'user_id' => $this->userId,
            'verification_method' => $this->verificationMethod,
            'assurance_level' => $this->assuranceLevel,
            'binding_token_hash' => $this->bindingTokenHash,
            'binding_expires_at' => $this->bindingExpiresAt,
            'binding_consumed_at' => $this->bindingConsumedAt,
            'chatwoot_account_id' => $this->chatwootAccountId,
            'chatwoot_contact_id' => $this->chatwootContactId,
            'chatwoot_conversation_id' => $this->chatwootConversationId,
            'created_at' => $this->createdAt,
            'last_used_at' => $this->lastUsedAt,
            'idle_expires_at' => $this->idleExpiresAt,
            'absolute_expires_at' => $this->absoluteExpiresAt,
            'revoked_at' => $this->revokedAt,
        ];
    }
}
