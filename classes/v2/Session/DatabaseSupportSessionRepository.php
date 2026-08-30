<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Session;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportSessionRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

final class DatabaseSupportSessionRepository implements SupportSessionRepositoryInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this repository never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::SESSION_TABLE;
    }

    public function create(SupportSession $session): void
    {
        DB::table(self::table())->insert($this->encodeRecord($session->toPersistenceRecord()));
    }

    public function save(SupportSession $session): void
    {
        $record = $this->encodeRecord($session->toPersistenceRecord());
        unset($record['public_id']);
        DB::table(self::table())->where('public_id', $session->publicId())->update($record);
    }

    public function findByPublicId(string $publicId): ?SupportSession
    {
        $row = DB::table(self::table())->where('public_id', $publicId)->first();
        return $row ? $this->hydrate($row) : null;
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
        return DB::transaction(function () use (
            $bindingTokenHash,
            $contextId,
            $userId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $now,
            $idleExpiresAt
        ): ?SupportSession {
            $nowDb = $this->toDatabaseTime($now);

            $row = DB::table(self::table())
                ->where('binding_token_hash', $bindingTokenHash)
                ->where('context_id', $contextId)
                ->where('user_id', $userId)
                ->whereNull('binding_consumed_at')
                ->whereNull('revoked_at')
                ->where('binding_expires_at', '>', $nowDb)
                ->where('idle_expires_at', '>', $nowDb)
                ->where('absolute_expires_at', '>', $nowDb)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return null;
            }

            $session = $this->hydrate($row);
            if (!$session->bindingAvailable($now)) {
                return null;
            }

            // Rotate any previous active session bound to this exact Chatwoot
            // conversation before claiming the new authenticated OJS session.
            $this->revokeOthersForConversation($contextId, $chatwootAccountId, $chatwootContactId, $chatwootConversationId, $session->publicId(), $now);

            $idleExpiresAt = min($idleExpiresAt, $session->absoluteExpiresAt());
            $bound = $session->withConversationBinding(
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId,
                $now,
                $idleExpiresAt
            );

            $record = $this->encodeRecord($bound->toPersistenceRecord());
            unset($record['public_id']);

            $updated = DB::table(self::table())
                ->where('public_id', $session->publicId())
                ->where('user_id', $userId)
                ->whereNull('binding_consumed_at')
                ->where('binding_token_hash', $bindingTokenHash)
                ->update($record);

            return $updated === 1 ? $bound : null;
        });
    }

    public function findByConversationBinding(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        $row = DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('chatwoot_account_id', $chatwootAccountId)
            ->where('chatwoot_contact_id', $chatwootContactId)
            ->where('chatwoot_conversation_id', $chatwootConversationId)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();
        return $row ? $this->hydrate($row) : null;
    }

    public function revokeActiveUnboundForUser(int $contextId, int $userId, int $now): void
    {
        DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('user_id', $userId)
            ->whereNull('chatwoot_conversation_id')
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $this->toDatabaseTime($now),
                'binding_token_hash' => null,
                'binding_expires_at' => null,
            ]);
    }

    public function revokeOthersForConversation(
        int $contextId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId,
        string $exceptPublicId,
        int $now
    ): void {
        DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('chatwoot_account_id', $chatwootAccountId)
            ->where('chatwoot_contact_id', $chatwootContactId)
            ->where('chatwoot_conversation_id', $chatwootConversationId)
            ->where('public_id', '!=', $exceptPublicId)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $this->toDatabaseTime($now),
                'binding_token_hash' => null,
                'binding_expires_at' => null,
            ]);
    }

    public function purgeExpired(int $now): int
    {
        $nowDb = $this->toDatabaseTime($now);
        return DB::table(self::table())
            ->where(function ($query) use ($nowDb): void {
                $query->where('absolute_expires_at', '<=', $nowDb)
                    ->orWhere('idle_expires_at', '<=', $nowDb)
                    ->orWhere(function ($nested) use ($nowDb): void {
                        $nested->whereNotNull('revoked_at')->where('revoked_at', '<=', $nowDb);
                    });
            })
            ->delete();
    }

    private function encodeRecord(array $record): array
    {
        foreach (['binding_expires_at','binding_consumed_at','created_at','last_used_at','idle_expires_at','absolute_expires_at','revoked_at'] as $key) {
            if (array_key_exists($key, $record)) {
                $record[$key] = $record[$key] === null ? null : $this->toDatabaseTime((int) $record[$key]);
            }
        }
        return $record;
    }

    private function hydrate(object $row): SupportSession
    {
        return new SupportSession(
            (string) $row->public_id,
            (int) $row->context_id,
            (int) $row->user_id,
            (string) $row->verification_method,
            (string) $row->assurance_level,
            $row->binding_token_hash !== null ? (string) $row->binding_token_hash : null,
            $this->fromDatabaseTime($row->binding_expires_at ?? null),
            $this->fromDatabaseTime($row->binding_consumed_at ?? null),
            $row->chatwoot_account_id !== null ? (string) $row->chatwoot_account_id : null,
            $row->chatwoot_contact_id !== null ? (string) $row->chatwoot_contact_id : null,
            $row->chatwoot_conversation_id !== null ? (string) $row->chatwoot_conversation_id : null,
            (int) $this->fromDatabaseTime($row->created_at),
            (int) $this->fromDatabaseTime($row->last_used_at),
            (int) $this->fromDatabaseTime($row->idle_expires_at),
            (int) $this->fromDatabaseTime($row->absolute_expires_at),
            $this->fromDatabaseTime($row->revoked_at ?? null)
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
