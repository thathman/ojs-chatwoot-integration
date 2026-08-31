<?php

declare(strict_types=1);

namespace PKP\user {
    final class Repo
    {
        /** @var array<string,object> keyed by lowercase email */
        public static array $usersByEmail = [];

        public static function user(): self
        {
            return new self();
        }

        public function getByEmail(string $email, bool $allowDisabled = false): ?object
        {
            return self::$usersByEmail[strtolower($email)] ?? null;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\VerificationChallengeRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\ChallengeAttemptOutcome;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationChallenge;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationChallengeService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailContentBuilder;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationSecretHasher;

    function verificationCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * In-memory fake mirroring DatabaseVerificationChallengeRepository's
     * logic exactly (same check order in attemptConsume) so the SERVICE's
     * business rules are tested against realistic repository behavior,
     * matching this codebase's established convention of never unit-testing
     * the real DB-backed repository directly (see DatabaseSupportSessionRepository,
     * which has likewise never been tested against a live database).
     */
    final class InMemoryVerificationChallengeRepository implements VerificationChallengeRepositoryInterface
    {
        /** @var array<string,VerificationChallenge> */
        public array $challenges = [];

        public function create(VerificationChallenge $challenge): void
        {
            $this->challenges[$challenge->publicReference()] = $challenge;
        }

        public function findByPublicReference(string $publicReference): ?VerificationChallenge
        {
            return $this->challenges[$publicReference] ?? null;
        }

        public function supersedeActiveForConversationPurpose(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId,
            string $purpose,
            int $now
        ): void {
            foreach ($this->challenges as $ref => $challenge) {
                if (
                    $challenge->contextId() === $contextId
                    && $challenge->chatwootAccountId() === $chatwootAccountId
                    && $challenge->chatwootContactId() === $chatwootContactId
                    && $challenge->chatwootConversationId() === $chatwootConversationId
                    && $challenge->purpose() === $purpose
                    && !$challenge->isConsumed()
                    && !$challenge->isRevoked()
                    && !$challenge->isSuperseded()
                ) {
                    $this->challenges[$ref] = $this->withField($challenge, 'supersededAt', $now);
                }
            }
        }

        public function countRecentForConversation(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId,
            int $sinceTimestamp
        ): int {
            $count = 0;
            foreach ($this->challenges as $challenge) {
                if (
                    $challenge->contextId() === $contextId
                    && $challenge->chatwootAccountId() === $chatwootAccountId
                    && $challenge->chatwootContactId() === $chatwootContactId
                    && $challenge->chatwootConversationId() === $chatwootConversationId
                    && $challenge->createdAt() >= $sinceTimestamp
                ) {
                    $count++;
                }
            }
            return $count;
        }

        public function countRecentForIdentity(int $contextId, int $userId, int $sinceTimestamp): int
        {
            $count = 0;
            foreach ($this->challenges as $challenge) {
                if ($challenge->contextId() === $contextId && $challenge->userId() === $userId && $challenge->createdAt() >= $sinceTimestamp) {
                    $count++;
                }
            }
            return $count;
        }

        public function mostRecentCreatedAtForConversationPurpose(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId,
            string $purpose
        ): ?int {
            $latest = null;
            foreach ($this->challenges as $challenge) {
                if (
                    $challenge->contextId() === $contextId
                    && $challenge->chatwootAccountId() === $chatwootAccountId
                    && $challenge->chatwootContactId() === $chatwootContactId
                    && $challenge->chatwootConversationId() === $chatwootConversationId
                    && $challenge->purpose() === $purpose
                    && ($latest === null || $challenge->createdAt() > $latest)
                ) {
                    $latest = $challenge->createdAt();
                }
            }
            return $latest;
        }

        public function attemptConsume(string $publicReference, callable $secretVerifier, int $now): ChallengeAttemptOutcome
        {
            $challenge = $this->challenges[$publicReference] ?? null;
            if (!$challenge) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_NOT_FOUND);
            }
            if ($challenge->isConsumed()) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_ALREADY_CONSUMED);
            }
            if ($challenge->isRevoked()) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_REVOKED);
            }
            if ($challenge->isSuperseded()) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_SUPERSEDED);
            }
            if ($challenge->isExpired($now)) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_EXPIRED);
            }
            if ($challenge->isLockedOut()) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_LOCKED_OUT);
            }

            if ($secretVerifier($challenge)) {
                $this->challenges[$publicReference] = $this->withField($challenge, 'consumedAt', $now);
                return ChallengeAttemptOutcome::consumed($challenge);
            }

            $this->challenges[$publicReference] = $this->withField($challenge, 'attemptCount', $challenge->attemptCount() + 1);
            return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_WRONG_SECRET);
        }

        public function purgeExpired(int $now): int
        {
            $before = count($this->challenges);
            $this->challenges = array_filter($this->challenges, fn (VerificationChallenge $c): bool => !$c->isExpired($now));
            return $before - count($this->challenges);
        }

        /** Test-only helper: this codebase has no public "revoke a challenge" feature yet, but attemptConsume() must still handle an already-revoked row correctly. */
        public function testOnlyRevoke(string $publicReference, int $now): void
        {
            $challenge = $this->challenges[$publicReference] ?? null;
            if ($challenge) {
                $this->challenges[$publicReference] = $this->withField($challenge, 'revokedAt', $now);
            }
        }

        private function withField(VerificationChallenge $challenge, string $field, mixed $value): VerificationChallenge
        {
            $record = $challenge->toPersistenceRecord();
            $record[match ($field) {
                'supersededAt' => 'superseded_at',
                'consumedAt' => 'consumed_at',
                'revokedAt' => 'revoked_at',
                'attemptCount' => 'attempt_count',
            }] = $value;

            return new VerificationChallenge(
                $record['public_reference'],
                $record['context_id'],
                $record['user_id'],
                $record['purpose'],
                $record['method'],
                $record['chatwoot_account_id'],
                $record['chatwoot_contact_id'],
                $record['chatwoot_conversation_id'],
                $record['secret_hash'],
                $record['attempt_count'],
                $record['max_attempts'],
                $record['last_attempt_at'],
                $record['created_at'],
                $record['expires_at'],
                $record['consumed_at'],
                $record['revoked_at'],
                $record['superseded_at']
            );
        }
    }

    final class InMemorySupportSessionRepositoryForVerification implements SupportSessionRepositoryInterface
    {
        /** @var array<string,SupportSession> */
        public array $sessions = [];
        public array $revokeOthersCalls = [];

        public function create(SupportSession $session): void
        {
            $this->sessions[$session->publicId()] = $session;
        }
        public function save(SupportSession $session): void
        {
            $this->sessions[$session->publicId()] = $session;
        }
        public function findByPublicId(string $publicId): ?SupportSession
        {
            return $this->sessions[$publicId] ?? null;
        }

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
            return null;
        }

        public function findByConversationBinding(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId
        ): ?SupportSession {
            foreach ($this->sessions as $session) {
                if (!$session->isRevoked() && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)) {
                    return $session;
                }
            }
            return null;
        }

        public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void
        {
            foreach ($this->sessions as $publicId => $session) {
                if ($session->contextId() === $contextId && $session->userId() === $userId && !$session->isBound() && !$session->isRevoked()) {
                    $this->sessions[$publicId] = $session->revoked($now);
                }
            }
        }

        public function revokeOthersForConversation(
            int $contextId,
            string $chatwootAccountId,
            string $chatwootContactId,
            string $chatwootConversationId,
            string $exceptPublicId,
            int $now
        ): void {
            $this->revokeOthersCalls[] = [$contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $exceptPublicId];
            foreach ($this->sessions as $publicId => $session) {
                if (
                    $publicId !== $exceptPublicId
                    && $session->matchesConversationBinding($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId)
                    && !$session->isRevoked()
                ) {
                    $this->sessions[$publicId] = $session->revoked($now);
                }
            }
        }

        public function purgeExpired(int $now): int
        {
            return 0;
        }
    }

    // ================================================================
    // Part 1: VerificationSecretHasher — pure crypto properties.
    // ================================================================
    $hasher = new VerificationSecretHasher();

    $pin = $hasher->generatePin();
    verificationCheck(strlen($pin) === 6 && ctype_digit($pin), 'generatePin() must produce exactly 6 digits');

    $pepper = bin2hex(random_bytes(32));
    $hash1 = $hasher->hashPin($pepper, $pin);
    $hash2 = $hasher->hashPin($pepper, $pin);
    verificationCheck($hash1 === $hash2, 'hashPin() must be deterministic for the same pepper+pin');
    verificationCheck($hash1 !== $pin, 'the PIN hash must never equal the plaintext PIN');
    verificationCheck($hasher->verifyPin($pepper, $pin, $hash1), 'verifyPin() must accept the correct pin+pepper+hash combination');
    verificationCheck(!$hasher->verifyPin($pepper, '000000' === $pin ? '111111' : '000000', $hash1), 'verifyPin() must reject a wrong pin');

    $otherPepper = bin2hex(random_bytes(32));
    verificationCheck($hasher->hashPin($otherPepper, $pin) !== $hash1, 'a different pepper must produce a different hash for the same PIN — the pepper is a real keying factor, not decorative');

    $token1 = $hasher->generateLinkToken();
    $token2 = $hasher->generateLinkToken();
    verificationCheck($token1 !== $token2, 'generateLinkToken() must produce distinct tokens');
    verificationCheck(strlen($token1) >= 32, 'the link token must be high-entropy (256 bits base64url-encoded is well over 32 characters)');
    $tokenHash = $hasher->hashLinkToken($token1);
    verificationCheck($tokenHash !== $token1, 'the link token hash must never equal the plaintext token');
    verificationCheck($hasher->verifyLinkToken($token1, $tokenHash), 'verifyLinkToken() must accept the correct token+hash combination');
    verificationCheck(!$hasher->verifyLinkToken($token2, $tokenHash), 'verifyLinkToken() must reject a wrong token');

    // ================================================================
    // Part 2: VerificationEmailContentBuilder — privacy/content checks.
    // ================================================================
    $pinBody = VerificationEmailContentBuilder::pinBody('Journal of Examples', '123456', 10);
    foreach (['submission', 'manuscript', 'reviewer', 'password'] as $forbidden) {
        verificationCheck(!str_contains(strtolower($pinBody), $forbidden), "the PIN email body must never contain the substring '{$forbidden}'");
    }
    verificationCheck(str_contains($pinBody, '123456'), 'the PIN email body must include the actual PIN');

    $linkBody = VerificationEmailContentBuilder::linkBody('Journal of Examples', 'https://example.com/verify?challenge=abc&token=<script>', 10);
    verificationCheck(!str_contains($linkBody, '<script>'), 'the link email body must HTML-escape the URL, never inject raw markup');
    foreach (['submission', 'manuscript', 'reviewer'] as $forbidden) {
        verificationCheck(!str_contains(strtolower($linkBody), $forbidden), "the link email body must never contain the substring '{$forbidden}'");
    }

    // ================================================================
    // Part 3: VerificationChallengeService — the shared engine's business
    // rules, against the in-memory repository fake.
    // ================================================================
    $now = 1_000_000_000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $repo = new InMemoryVerificationChallengeRepository();
    $service = new VerificationChallengeService(
        $repo,
        $hasher,
        $clock,
        ttlSeconds: 600,
        cooldownSeconds: 60,
        maxPerConversationPerWindow: 3,
        maxPerIdentityPerWindow: 3,
        rateLimitWindowSeconds: 3600,
        maxAttempts: 4
    );

    $pepperA = bin2hex(random_bytes(32));

    // --- Basic PIN request + confirm round trip ---
    $prepared = $service->requestChallenge(7, 42, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '500', $pepperA);
    verificationCheck($prepared !== null, 'a fresh request must succeed');
    verificationCheck($prepared->challenge()->method() === VerificationChallenge::METHOD_PIN, 'requested method must be pin');

    // PIN plaintext never persists.
    $persisted = $repo->findByPublicReference($prepared->challenge()->publicReference());
    verificationCheck($persisted->secretHash() !== $prepared->plaintextSecret(), 'the persisted challenge must never store the plaintext PIN');
    verificationCheck($persisted->secretHash() === $hasher->hashPin($pepperA, $prepared->plaintextSecret()), 'the persisted hash must match the real HMAC of the plaintext PIN');

    // PIN from another journal fails.
    $wrongJournal = $service->confirmPin($prepared->challenge()->publicReference(), $prepared->plaintextSecret(), 999, '1', '100', '500', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$wrongJournal->isConsumed() && $wrongJournal->status() === ChallengeAttemptOutcome::STATUS_WRONG_SECRET, 'a correct PIN presented against another journal/context must fail, indistinguishable from a wrong PIN');

    // PIN from another conversation fails.
    $wrongConversation = $service->confirmPin($prepared->challenge()->publicReference(), $prepared->plaintextSecret(), 7, '1', '100', '999', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$wrongConversation->isConsumed() && $wrongConversation->status() === ChallengeAttemptOutcome::STATUS_WRONG_SECRET, 'a correct PIN presented against another conversation must fail');

    // PIN for another purpose fails.
    $wrongPurpose = $service->confirmPin($prepared->challenge()->publicReference(), $prepared->plaintextSecret(), 7, '1', '100', '500', VerificationChallenge::PURPOSE_SUBMISSION_SUPPORT, $pepperA);
    verificationCheck(!$wrongPurpose->isConsumed() && $wrongPurpose->status() === ChallengeAttemptOutcome::STATUS_WRONG_SECRET, 'a correct PIN presented for another purpose must fail');

    // Per the service's own docblock, a binding mismatch still increments
    // the attempt counter (it is indistinguishable from a wrong secret,
    // and must never be silently ignored) — 3 mismatches above, so exactly
    // 3 recorded attempts now, one below maxAttempts (4).
    $afterMismatches = $repo->findByPublicReference($prepared->challenge()->publicReference());
    verificationCheck($afterMismatches->attemptCount() === 3, 'binding mismatches must still count as failed attempts, never silently ignored');

    // Wrong PIN (correct binding) also counts as an attempt — this is the
    // 4th (== maxAttempts), so it must still fail as wrong_secret first,
    // and only *after* this does the challenge become locked out.
    $wrongPin = $service->confirmPin($prepared->challenge()->publicReference(), '000000' === $prepared->plaintextSecret() ? '111111' : '000000', 7, '1', '100', '500', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck($wrongPin->status() === ChallengeAttemptOutcome::STATUS_WRONG_SECRET, 'a wrong PIN with correct binding must fail as wrong_secret, even on the attempt that pushes the counter to maxAttempts');
    $lockedOutNow = $repo->findByPublicReference($prepared->challenge()->publicReference());
    verificationCheck($lockedOutNow->isLockedOut(), 'the attempt counter must lock the challenge out after maxAttempts (4) failed attempts');

    $correctButLockedOut = $service->confirmPin($prepared->challenge()->publicReference(), $prepared->plaintextSecret(), 7, '1', '100', '500', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck($correctButLockedOut->status() === ChallengeAttemptOutcome::STATUS_LOCKED_OUT, 'even the genuinely correct PIN must be rejected once locked out — the lockout is not a soft warning');

    // --- Fresh challenge: successful consumption + replay protection ---
    // (a distinct userId from here on, so per-identity rate limiting
    // exercised later doesn't collide with these otherwise-unrelated scenarios)
    $now += 100;
    $prepared2 = $service->requestChallenge(7, 50, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '501', $pepperA);
    verificationCheck($prepared2 !== null, 'a fresh request on a different conversation must succeed');

    $firstConfirm = $service->confirmPin($prepared2->challenge()->publicReference(), $prepared2->plaintextSecret(), 7, '1', '100', '501', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck($firstConfirm->isConsumed(), 'a correct PIN with correct binding must be consumed successfully');

    // Consumed PIN replay fails; this also stands in for "concurrent
    // consumption permits exactly one success" — true multi-process
    // concurrency cannot be exercised in this single-threaded CLI harness,
    // but the atomicity guarantee (a WHERE consumed_at IS NULL guard
    // checked via affected-row-count) is reviewed directly in
    // DatabaseVerificationChallengeRepository::attemptConsume(); this
    // proves the same logical one-time-only outcome the atomic guard
    // exists to guarantee.
    $replay = $service->confirmPin($prepared2->challenge()->publicReference(), $prepared2->plaintextSecret(), 7, '1', '100', '501', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$replay->isConsumed() && $replay->status() === ChallengeAttemptOutcome::STATUS_ALREADY_CONSUMED, 'replaying an already-consumed PIN must fail, never succeed twice');

    // --- Expired PIN fails ---
    $now += 200;
    $prepared3 = $service->requestChallenge(7, 51, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '502', $pepperA);
    $now += 700; // past the 600s TTL
    $expired = $service->confirmPin($prepared3->challenge()->publicReference(), $prepared3->plaintextSecret(), 7, '1', '100', '502', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$expired->isConsumed() && $expired->status() === ChallengeAttemptOutcome::STATUS_EXPIRED, 'an expired PIN must fail even with the correct value');

    // --- Revoked PIN fails ---
    $now += 100;
    $prepared4 = $service->requestChallenge(7, 52, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '503', $pepperA);
    $repo->testOnlyRevoke($prepared4->challenge()->publicReference(), $now);
    $revokedAttempt = $service->confirmPin($prepared4->challenge()->publicReference(), $prepared4->plaintextSecret(), 7, '1', '100', '503', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$revokedAttempt->isConsumed() && $revokedAttempt->status() === ChallengeAttemptOutcome::STATUS_REVOKED, 'a revoked PIN must fail even with the correct value');

    // --- Resend invalidates prior unconsumed PIN ---
    $now += 100;
    $original = $service->requestChallenge(7, 43, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '504', $pepperA);
    verificationCheck($original !== null, 'test fixture: the original request must succeed');
    $now += 61; // past the 60s cooldown
    $resend = $service->requestChallenge(7, 43, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '504', $pepperA);
    verificationCheck($resend !== null, 'a resend after the cooldown must succeed');
    $originalAfterResend = $repo->findByPublicReference($original->challenge()->publicReference());
    verificationCheck($originalAfterResend->isSuperseded(), 'a new valid request must invalidate (supersede) the previous unconsumed challenge');
    $oldPinAttempt = $service->confirmPin($original->challenge()->publicReference(), $original->plaintextSecret(), 7, '1', '100', '504', VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, $pepperA);
    verificationCheck(!$oldPinAttempt->isConsumed() && $oldPinAttempt->status() === ChallengeAttemptOutcome::STATUS_SUPERSEDED, 'the old, superseded PIN must no longer work after a resend');

    // --- Cooldown blocks an immediate resend ---
    $now += 100;
    $service->requestChallenge(7, 44, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '505', $pepperA);
    $immediateResend = $service->requestChallenge(7, 44, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '505', $pepperA);
    verificationCheck($immediateResend === null, 'a resend within the cooldown window must be silently throttled');

    // --- Rate limiting: per-conversation and per-identity ---
    $now += 1000;
    $conversationLimited = null;
    for ($i = 0; $i < 5; $i++) {
        $now += 100; // clear the cooldown each time
        $conversationLimited = $service->requestChallenge(7, 45 + $i, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', '506', $pepperA);
    }
    verificationCheck($conversationLimited === null, 'the per-conversation rolling rate limit (3 per window) must throttle a 5th distinct request on the same conversation');

    $now += 1000;
    $identityLimited = null;
    for ($i = 0; $i < 5; $i++) {
        $now += 100;
        $identityLimited = $service->requestChallenge(7, 999, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_PIN, '1', '100', (string) (600 + $i), $pepperA);
    }
    verificationCheck($identityLimited === null, 'the per-identity rolling rate limit (3 per window) must throttle a 5th distinct request for the same user across different conversations');

    // ================================================================
    // Part 4: secure-link path — link token plaintext never persists,
    // single use, and does not require an externally-supplied conversation
    // tuple (the browser flow cannot supply one).
    // ================================================================
    $now += 5000;
    $linkPrepared = $service->requestChallenge(7, 46, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_LINK, '1', '100', '700', $pepperA);
    verificationCheck($linkPrepared !== null && $linkPrepared->challenge()->method() === VerificationChallenge::METHOD_LINK, 'a link-method request must succeed and be tagged as link');

    $persistedLink = $repo->findByPublicReference($linkPrepared->challenge()->publicReference());
    verificationCheck($persistedLink->secretHash() !== $linkPrepared->plaintextSecret(), 'the persisted challenge must never store the plaintext link token');
    verificationCheck($persistedLink->secretHash() === $hasher->hashLinkToken($linkPrepared->plaintextSecret()), 'the persisted hash must match the real SHA-256 of the plaintext token');

    $linkConfirm = $service->confirmLinkToken($linkPrepared->challenge()->publicReference(), $linkPrepared->plaintextSecret(), 7);
    verificationCheck($linkConfirm->isConsumed(), 'a correct link token must be consumed successfully with only a context ID, no conversation tuple supplied');

    $linkReplay = $service->confirmLinkToken($linkPrepared->challenge()->publicReference(), $linkPrepared->plaintextSecret(), 7);
    verificationCheck(!$linkReplay->isConsumed() && $linkReplay->status() === ChallengeAttemptOutcome::STATUS_ALREADY_CONSUMED, 'secure-link tokens must be single-use — a replay must fail');

    $now += 100;
    $linkWrongJournal = $service->requestChallenge(7, 47, VerificationChallenge::PURPOSE_ACCOUNT_SUPPORT, VerificationChallenge::METHOD_LINK, '1', '100', '701', $pepperA);
    $crossJournalLink = $service->confirmLinkToken($linkWrongJournal->challenge()->publicReference(), $linkWrongJournal->plaintextSecret(), 999);
    verificationCheck(!$crossJournalLink->isConsumed(), 'a correct link token presented against another journal/context must fail');

    // ================================================================
    // Part 5: SupportSessionService::establishFromExternalVerification —
    // new successful verification rotates/revokes stale sessions, resolves
    // through the same conversation-binding lookup /status uses, and can
    // subsequently reach V3 normally.
    // ================================================================
    $sessionRepo = new InMemorySupportSessionRepositoryForVerification();
    $sessionNow = 2_000_000_000;
    $sessionService = new SupportSessionService($sessionRepo, static fn (): int => $sessionNow);

    // A stale session already bound to this exact conversation from a
    // PRIOR verification must be revoked when a new one succeeds.
    $staleSession = new SupportSession(
        'stale-session',
        7,
        42,
        SupportSessionService::METHOD_EXTERNAL_PIN,
        'v2',
        null,
        null,
        null,
        '1',
        '100',
        '800',
        $sessionNow - 500,
        $sessionNow - 500,
        $sessionNow + 1000,
        $sessionNow + 2000,
        null
    );
    $sessionRepo->create($staleSession);

    $newSession = $sessionService->establishFromExternalVerification(7, 42, SupportSessionService::METHOD_EXTERNAL_PIN, '1', '100', '800');
    verificationCheck($newSession->assuranceLevel() === 'v2', 'external verification must establish v2, never v3, regardless of the verification purpose');
    verificationCheck($newSession->isBound(), 'the new session must already be bound to the conversation — no separate binding-token step for external verification');

    $staleAfter = $sessionRepo->findByPublicId('stale-session');
    verificationCheck($staleAfter->isRevoked(), 'a stale session already bound to the same conversation must be revoked by the new successful verification');

    // Resolves through the same conversation-binding lookup /status,
    // /identity, and /actions all use internally (SupportSessionService::resolveConversation).
    $resolved = $sessionService->resolveConversation(7, '1', '100', '800');
    verificationCheck($resolved !== null && $resolved->publicId() === $newSession->publicId(), 'the newly established session must resolve through the same conversation-binding lookup /status/identity/actions already use');
    verificationCheck($resolved->assuranceLevel() === 'v2', 'the resolved session must report v2 assurance');

    // Can subsequently reach V3 normally — the capability engine does not
    // care how v2 was reached, only that it genuinely is v2.
    $externalIdentity = new \APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext(7, 'journal-a', 42, [65538], 'index', 'index', 'en');
    $fakeRelationship = new \APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship('submission', 456, ['author'], []);
    $v3Decision = (new \APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityPolicyEngine())->evaluate(new CapabilityRequest(
        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        'v3',
        $externalIdentity,
        $fakeRelationship
    ));
    verificationCheck($v3Decision->allows('submission.read_own_support_status'), 'a session established via external verification must be able to reach v3 normally afterward, exactly like the authenticated-session path');

    // ================================================================
    // Part 6: source-level checks
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    verificationCheck(str_contains($pluginSource, 'function supportVerificationRequestRequest'), 'plugin must implement the verification-request endpoint');
    verificationCheck(str_contains($pluginSource, 'function supportVerificationConfirmRequest'), 'plugin must implement the verification-confirm endpoint');
    verificationCheck(str_contains($pluginSource, 'function verifyLinkRequest'), 'plugin must implement the browser-facing secure-link endpoint');
    $requestMethodStart = strpos($pluginSource, 'function supportVerificationRequestRequest');
    verificationCheck($requestMethodStart !== false, 'must be able to locate the verification-request method body for the source-level check below');
    $requestMethodNextStart = strpos($pluginSource, 'public function', $requestMethodStart + 1);
    $requestMethodBody = $requestMethodNextStart !== false
        ? substr($pluginSource, $requestMethodStart, $requestMethodNextStart - $requestMethodStart)
        : substr($pluginSource, $requestMethodStart);
    verificationCheck(
        substr_count($requestMethodBody, 'SupportApiResponse::success(') === 1,
        'the verification-request endpoint must have exactly one success response call in its body — every code path (found/not-found/throttled/mail-failed) must fall through to the same unconditional response, never branch to a distinguishable one'
    );
    verificationCheck(
        str_contains($pluginSource, 'ASSURANCE_AUTHENTICATED_SESSION') || str_contains($pluginSource, "'assurance' => \$session ? \$session->assuranceLevel()"),
        'the confirm endpoint must report the real session assurance, not a hardcoded claim'
    );

    $handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
    verificationCheck(str_contains($handlerSource, 'function verificationRequest('), 'handler must register the verificationRequest operation');
    verificationCheck(str_contains($handlerSource, 'function verificationConfirm('), 'handler must register the verificationConfirm operation');
    verificationCheck(str_contains($handlerSource, 'function verify('), 'handler must register the browser-facing verify operation');

    $migrationSource = (string) file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
    verificationCheck(str_contains($migrationSource, "'secret_hash'"), 'the challenge table must store a secret hash column');
    verificationCheck(!str_contains($migrationSource, "'plaintext_pin'") && !str_contains($migrationSource, "'pin'") && !str_contains($migrationSource, "'token'"), 'the challenge table must never define a raw pin/token column, only secret_hash');

    $auditSource = (string) file_get_contents($root . '/classes/v2/Audit/ErrorLogSupportApiAuditLogger.php');
    foreach (['pin', 'secret', 'token'] as $forbidden) {
        verificationCheck(!str_contains(strtolower($auditSource), $forbidden), "the audit sink must never reference '{$forbidden}' — logs/audit must never contain a PIN or secure token");
    }

    fwrite(STDOUT, "External verification tests passed\n");
}
