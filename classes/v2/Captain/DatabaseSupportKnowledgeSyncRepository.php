<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

final class DatabaseSupportKnowledgeSyncRepository implements SupportKnowledgeSyncRepositoryInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this repository never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::KNOWLEDGE_SYNC_TABLE;
    }

    public function find(int $contextId, string $locale, string $resourceType): ?CaptainSyncState
    {
        $row = DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('locale', $locale)
            ->where('resource_type', $resourceType)
            ->first();

        return $row ? $this->hydrate($row) : null;
    }

    public function save(CaptainSyncState $state): void
    {
        $record = [
            'context_id' => $state->contextId(),
            'locale' => $state->locale(),
            'resource_type' => $state->resourceType(),
            'remote_resource_id' => $state->remoteResourceId(),
            'last_successful_fingerprint' => $state->lastSuccessfulFingerprint(),
            'last_successful_sync_at' => $state->lastSuccessfulSyncAt() !== null ? $this->toDatabaseTime($state->lastSuccessfulSyncAt()) : null,
            'last_error_code' => $state->lastErrorCode(),
            'updated_at' => $this->toDatabaseTime($state->updatedAt()),
        ];

        $existing = DB::table(self::table())
            ->where('context_id', $state->contextId())
            ->where('locale', $state->locale())
            ->where('resource_type', $state->resourceType())
            ->first();

        if ($existing) {
            DB::table(self::table())
                ->where('context_id', $state->contextId())
                ->where('locale', $state->locale())
                ->where('resource_type', $state->resourceType())
                ->update($record);
            return;
        }

        DB::table(self::table())->insert($record);
    }

    private function hydrate(object $row): CaptainSyncState
    {
        return new CaptainSyncState(
            (int) $row->context_id,
            (string) $row->locale,
            (string) $row->resource_type,
            $row->remote_resource_id !== null ? (string) $row->remote_resource_id : null,
            $row->last_successful_fingerprint !== null ? (string) $row->last_successful_fingerprint : null,
            $this->fromDatabaseTime($row->last_successful_sync_at),
            $row->last_error_code !== null ? (string) $row->last_error_code : null,
            $this->fromDatabaseTime($row->updated_at) ?? time()
        );
    }

    private function toDatabaseTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function fromDatabaseTime(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $timestamp = strtotime((string) $value . ' UTC');
        return $timestamp !== false ? $timestamp : null;
    }
}
