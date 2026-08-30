<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * Result of one atomic attempt against a VerificationChallenge (see
 * VerificationChallengeRepositoryInterface::attemptConsume()). Every
 * "could not consume" reason is available here for internal logic/audit
 * only — the public API response must still collapse all of these into
 * the same generic shape (anti-enumeration).
 */
final class ChallengeAttemptOutcome
{
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_WRONG_SECRET = 'wrong_secret';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_ALREADY_CONSUMED = 'already_consumed';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_LOCKED_OUT = 'locked_out';

    private function __construct(
        private string $status,
        private ?VerificationChallenge $challenge
    ) {
    }

    public static function consumed(VerificationChallenge $challenge): self
    {
        return new self(self::STATUS_CONSUMED, $challenge);
    }

    public static function failed(string $status): self
    {
        return new self($status, null);
    }

    public function status(): string { return $this->status; }
    public function challenge(): ?VerificationChallenge { return $this->challenge; }
    public function isConsumed(): bool { return $this->status === self::STATUS_CONSUMED; }
}
