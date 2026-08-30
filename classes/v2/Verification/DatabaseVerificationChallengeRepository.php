<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\VerificationChallengeRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

final class DatabaseVerificationChallengeRepository implements VerificationChallengeRepositoryInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this repository never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::CHALLENGE_TABLE;
    }

    public function create(VerificationChallenge $challenge): void
    {
        DB::table(self::table())->insert($this->encodeRecord($challenge->toPersistenceRecord()));
    }

    public function findByPublicReference(string $publicReference): ?VerificationChallenge
    {
        $row = DB::table(self::table())->where('public_reference', $publicReference)->first();
        return $row ? $this->hydrate($row) : null;
    }

    public function supersedeActiveForConversationPurpose(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose,
        int $now
    ): void {
        DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('chatwoot_account_id', $chatwootAccountId)
            ->where('chatwoot_contact_id', $chatwootContactId)
            ->where('chatwoot_conversation_id', $chatwootConversationId)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->whereNull('revoked_at')
            ->whereNull('superseded_at')
            ->update(['superseded_at' => $this->toDatabaseTime($now)]);
    }

    public function countRecentForConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        int $sinceTimestamp
    ): int {
        return DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('chatwoot_account_id', $chatwootAccountId)
            ->where('chatwoot_contact_id', $chatwootContactId)
            ->where('chatwoot_conversation_id', $chatwootConversationId)
            ->where('created_at', '>=', $this->toDatabaseTime($sinceTimestamp))
            ->count();
    }

    public function countRecentForIdentity(int $contextId, int $userId, int $sinceTimestamp): int
    {
        return DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('user_id', $userId)
            ->where('created_at', '>=', $this->toDatabaseTime($sinceTimestamp))
            ->count();
    }

    public function mostRecentCreatedAtForConversationPurpose(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $purpose
    ): ?int {
        $row = DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('chatwoot_account_id', $chatwootAccountId)
            ->where('chatwoot_contact_id', $chatwootContactId)
            ->where('chatwoot_conversation_id', $chatwootConversationId)
            ->where('purpose', $purpose)
            ->orderByDesc('id')
            ->first(['created_at']);
        return $row ? $this->fromDatabaseTime($row->created_at) : null;
    }

    public function attemptConsume(string $publicReference, callable $secretVerifier, int $now): ChallengeAttemptOutcome
    {
        return DB::transaction(function () use ($publicReference, $secretVerifier, $now): ChallengeAttemptOutcome {
            $row = DB::table(self::table())
                ->where('public_reference', $publicReference)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_NOT_FOUND);
            }

            $challenge = $this->hydrate($row);

            if ($challenge->isConsumed()) return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_ALREADY_CONSUMED);
            if ($challenge->isRevoked()) return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_REVOKED);
            if ($challenge->isSuperseded()) return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_SUPERSEDED);
            if ($challenge->isExpired($now)) return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_EXPIRED);
            if ($challenge->isLockedOut()) return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_LOCKED_OUT);

            if ($secretVerifier($challenge)) {
                $updated = DB::table(self::table())
                    ->where('public_reference', $publicReference)
                    ->whereNull('consumed_at')
                    ->update(['consumed_at' => $this->toDatabaseTime($now)]);

                if ($updated !== 1) {
                    // Lost the race to another concurrent consumption.
                    return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_ALREADY_CONSUMED);
                }

                return ChallengeAttemptOutcome::consumed($challenge);
            }

            DB::table(self::table())
                ->where('public_reference', $publicReference)
                ->update([
                    'attempt_count' => $challenge->attemptCount() + 1,
                    'last_attempt_at' => $this->toDatabaseTime($now),
                ]);

            return ChallengeAttemptOutcome::failed(ChallengeAttemptOutcome::STATUS_WRONG_SECRET);
        });
    }

    public function purgeExpired(int $now): int
    {
        $nowDb = $this->toDatabaseTime($now);
        return DB::table(self::table())
            ->where(function ($query) use ($nowDb): void {
                $query->where('expires_at', '<=', $nowDb)
                    ->orWhereNotNull('consumed_at')
                    ->orWhereNotNull('revoked_at')
                    ->orWhereNotNull('superseded_at');
            })
            ->delete();
    }

    private function encodeRecord(array $record): array
    {
        foreach (['last_attempt_at', 'created_at', 'expires_at', 'consumed_at', 'revoked_at', 'superseded_at'] as $key) {
            if (array_key_exists($key, $record)) {
                $record[$key] = $record[$key] === null ? null : $this->toDatabaseTime((int) $record[$key]);
            }
        }
        return $record;
    }

    private function hydrate(object $row): VerificationChallenge
    {
        return new VerificationChallenge(
            (string) $row->public_reference,
            (int) $row->context_id,
            (int) $row->user_id,
            (string) $row->purpose,
            (string) $row->method,
            (string) $row->chatwoot_account_id,
            (string) $row->chatwoot_contact_id,
            (string) $row->chatwoot_conversation_id,
            (string) $row->secret_hash,
            (int) $row->attempt_count,
            (int) $row->max_attempts,
            $this->fromDatabaseTime($row->last_attempt_at ?? null),
            (int) $this->fromDatabaseTime($row->created_at),
            (int) $this->fromDatabaseTime($row->expires_at),
            $this->fromDatabaseTime($row->consumed_at ?? null),
            $this->fromDatabaseTime($row->revoked_at ?? null),
            $this->fromDatabaseTime($row->superseded_at ?? null)
        );
    }

    private function toDatabaseTime(int $timestamp): string { return gmdate('Y-m-d H:i:s', $timestamp); }

    private function fromDatabaseTime(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        $timestamp = strtotime((string) $value . ' UTC');
        return $timestamp === false ? null : $timestamp;
    }
}
