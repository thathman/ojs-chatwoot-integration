<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\VerificationChallengeRepositoryInterface;
use Closure;
use LogicException;

/**
 * The single shared challenge engine for both PIN and secure-link external
 * verification (docs/v2/ADRS.md ADR-005) — deliberately one engine, not two
 * verification systems. Pure business rules only: no OJS dependency beyond
 * the repository interface, no email sending, no Mailable. The caller
 * (plugin endpoint) owns OJS-specific concerns (email→user lookup, actually
 * sending the mail) and is responsible for preserving anti-enumeration:
 * this service's `requestChallenge()` returning null (silently throttled)
 * must produce exactly the same public response as it returning a prepared
 * challenge — the caller must never let the two paths diverge in what's
 * sent back to Chatwoot Captain.
 */
final class VerificationChallengeService
{
    private Closure $clock;

    public function __construct(
        private VerificationChallengeRepositoryInterface $repository,
        private VerificationSecretHasher $hasher,
        ?callable $clock = null,
        private int $ttlSeconds = 600,
        private int $cooldownSeconds = 60,
        private int $maxPerConversationPerWindow = 5,
        private int $maxPerIdentityPerWindow = 5,
        private int $rateLimitWindowSeconds = 3600,
        private int $maxAttempts = 5
    ) {
        if ($this->ttlSeconds <= 0 || $this->cooldownSeconds < 0 || $this->rateLimitWindowSeconds <= 0 || $this->maxAttempts <= 0) {
            throw new LogicException('Verification challenge timing/limits must be positive.');
        }
        $this->clock = $clock ? Closure::fromCallable($clock) : static fn (): int => time();
    }

    /**
     * Returns null when the request should be silently throttled (rate
     * limit or cooldown) — the caller must still respond with the same
     * generic success shape either way, never revealing that throttling
     * occurred.
     */
    public function requestChallenge(
        int $contextId,
        int $userId,
        string $purpose,
        string $method,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $pepper
    ): ?PreparedChallenge {
        $now = $this->now();

        if ($this->repository->countRecentForConversation(
            $contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $now - $this->rateLimitWindowSeconds
        ) >= $this->maxPerConversationPerWindow) {
            return null;
        }

        if ($userId > 0 && $this->repository->countRecentForIdentity(
            $contextId, $userId, $now - $this->rateLimitWindowSeconds
        ) >= $this->maxPerIdentityPerWindow) {
            return null;
        }

        $lastCreatedAt = $this->repository->mostRecentCreatedAtForConversationPurpose(
            $contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $purpose
        );
        if ($lastCreatedAt !== null && ($now - $lastCreatedAt) < $this->cooldownSeconds) {
            return null;
        }

        // Resend behavior: a new valid request invalidates the previous
        // unconsumed challenge for this exact context+conversation+purpose.
        $this->repository->supersedeActiveForConversationPurpose(
            $contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $purpose, $now
        );

        $method = $method === VerificationChallenge::METHOD_LINK ? VerificationChallenge::METHOD_LINK : VerificationChallenge::METHOD_PIN;
        if ($method === VerificationChallenge::METHOD_LINK) {
            $secret = $this->hasher->generateLinkToken();
            $secretHash = $this->hasher->hashLinkToken($secret);
        } else {
            $secret = $this->hasher->generatePin();
            $secretHash = $this->hasher->hashPin($pepper, $secret);
        }

        $challenge = new VerificationChallenge(
            bin2hex(random_bytes(16)),
            $contextId,
            $userId,
            $purpose,
            $method,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $secretHash,
            0,
            $this->maxAttempts,
            null,
            $now,
            $now + $this->ttlSeconds
        );
        $this->repository->create($challenge);

        return new PreparedChallenge($challenge, $secret);
    }

    public function confirmPin(
        string $publicReference,
        string $pin,
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose,
        string $pepper
    ): ChallengeAttemptOutcome {
        return $this->confirm(
            $publicReference,
            $contextId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $purpose,
            function (VerificationChallenge $challenge) use ($pin, $pepper): bool {
                return $challenge->method() === VerificationChallenge::METHOD_PIN
                    && $this->hasher->verifyPin($pepper, $pin, $challenge->secretHash());
            }
        );
    }

    /**
     * The secure-link flow deliberately does not take a conversation
     * tuple or purpose to match against — a browser opening the emailed
     * link has no way to supply one (the URL carries only the opaque
     * challenge reference and token by design; see
     * VerificationEmailContentBuilder's docblock). The binding instead
     * comes entirely from the challenge's own server-side stored
     * conversation tuple (set when the challenge was created) — "enough
     * server-side binding to the already-verified Chatwoot conversation"
     * without weakening it just to make the URL convenient. Only the
     * journal context is cheap to check independently (the browser GET
     * handler already resolves it from the current request) and adds a
     * belt-and-suspenders cross-journal guard, even though the public
     * reference's own entropy already makes cross-journal collision
     * negligible.
     */
    public function confirmLinkToken(string $publicReference, string $token, int $contextId): ChallengeAttemptOutcome
    {
        return $this->repository->attemptConsume(
            $publicReference,
            function (VerificationChallenge $challenge) use ($contextId, $token): bool {
                if ($challenge->contextId() !== $contextId) {
                    return false;
                }
                return $challenge->method() === VerificationChallenge::METHOD_LINK
                    && $this->hasher->verifyLinkToken($token, $challenge->secretHash());
            },
            $this->now()
        );
    }

    /**
     * Conversation/purpose binding is checked *inside* the secret
     * verifier — deliberately, so a mismatch on either is indistinguishable
     * from a wrong PIN/token to anyone observing the outcome, and still
     * correctly counts as a failed attempt against the lockout counter
     * rather than being silently ignored. All of this runs inside the
     * repository's single atomic transaction (see attemptConsume()).
     */
    private function confirm(
        string $publicReference,
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose,
        callable $secretPredicate
    ): ChallengeAttemptOutcome {
        return $this->repository->attemptConsume(
            $publicReference,
            function (VerificationChallenge $challenge) use ($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $purpose, $secretPredicate): bool {
                if (!$challenge->matchesConversation($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)) {
                    return false;
                }
                if (!$challenge->matchesPurpose($purpose)) {
                    return false;
                }
                return $secretPredicate($challenge);
            },
            $this->now()
        );
    }

    public function purgeExpired(): int { return $this->repository->purgeExpired($this->now()); }

    private function now(): int { return (int) ($this->clock)(); }
}
