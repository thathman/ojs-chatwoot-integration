<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationChallenge;

/**
 * Persistence contract for the shared PIN/secure-link challenge engine.
 * Implementations must never persist a plaintext PIN/token or claimed
 * email — see VerificationChallenge's own docblock.
 */
interface VerificationChallengeRepositoryInterface
{
    public function create(VerificationChallenge $challenge): void;

    public function findByPublicReference(string $publicReference): ?VerificationChallenge;

    /**
     * A new valid request for the same context+conversation+purpose+identity
     * must invalidate the previous unconsumed challenge (resend behavior).
     */
    public function supersedeActiveForConversationPurpose(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose,
        int $now
    ): void;

    /** For the rolling per-conversation rate limit. */
    public function countRecentForConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        int $sinceTimestamp
    ): int;

    /** For the rolling per-identity rate limit (only checkable once an email lookup resolves a user). */
    public function countRecentForIdentity(int $contextId, int $userId, int $sinceTimestamp): int;

    /** For the resend cooldown. */
    public function mostRecentCreatedAtForConversationPurpose(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose
    ): ?int;

    /**
     * Atomically loads the challenge (row-locked within a transaction),
     * lets the caller verify the secret against it via $secretVerifier, and
     * either consumes it or records a failed attempt within that same
     * transaction — never two separate, raceable operations. A simultaneous
     * replay must result in exactly one successful consumption.
     *
     * @param callable(VerificationChallenge):bool $secretVerifier
     */
    public function attemptConsume(string $publicReference, callable $secretVerifier, int $now): ChallengeAttemptOutcome;

    public function purgeExpired(int $now): int;
}
