<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;

interface SupportSessionRepositoryInterface
{
    public function create(SupportSession $session): void;

    public function save(SupportSession $session): void;

    public function findByPublicId(string $publicId): ?SupportSession;

    /**
     * Atomically consume an unexpired one-time binding token and bind the
     * session to one exact authenticated OJS user + Chatwoot conversation.
     */
    public function claimBindingToken(
        string $bindingTokenHash,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        int $now,
        int $idleExpiresAt
    ): ?SupportSession;

    public function findByConversationBinding(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession;

    public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void;

    /**
     * Revoke any other active session already bound to this exact Chatwoot
     * conversation — used when external verification establishes a fresh,
     * already-bound session directly (no separate binding-token step,
     * since the conversation is already known at confirm time).
     */
    public function revokeOthersForConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $exceptPublicId,
        int $now
    ): void;

    public function purgeExpired(int $now): int;
}
