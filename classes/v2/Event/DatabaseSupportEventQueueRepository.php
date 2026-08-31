<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportEventQueueRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

final class DatabaseSupportEventQueueRepository implements SupportEventQueueRepositoryInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this repository never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::EVENT_QUEUE_TABLE;
    }

    public function enqueue(SupportEvent $event, string $deliveryMode): bool
    {
        $exists = DB::table(self::table())
            ->where('idempotency_key', $event->idempotencyKey())
            ->exists();
        if ($exists) {
            return false;
        }

        try {
            DB::table(self::table())->insert([
                'idempotency_key' => $event->idempotencyKey(),
                'event_type' => $event->type(),
                'context_id' => $event->contextId(),
                'resource_type' => $event->resourceType(),
                'resource_id' => $event->resourceId(),
                'attributes' => json_encode($event->attributes(), JSON_UNESCAPED_SLASHES) ?: null,
                'delivery_mode' => $deliveryMode,
                'status' => 'pending',
                'attempts' => 0,
                'occurred_at' => $this->toDatabaseTime($event->occurredAt()),
                'created_at' => $this->toDatabaseTime(time()),
            ]);
        } catch (\Throwable $e) {
            // A concurrent enqueue of the very same real occurrence can
            // race the exists() check above and hit the idempotency_key
            // unique constraint — that race is exactly the "already
            // queued" outcome, not a real failure.
            return false;
        }

        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public function fetchPendingBatch(int $limit, int $now): array
    {
        $nowDb = $this->toDatabaseTime($now);

        $rows = DB::table(self::table())
            ->where('status', 'pending')
            ->where(function ($query) use ($nowDb): void {
                $query->whereNull('run_after')->orWhere('run_after', '<=', $nowDb);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = (array) $row;
        }
        return $result;
    }

    public function markDelivered(int $id, int $now): void
    {
        DB::table(self::table())->where('id', $id)->update([
            'status' => 'delivered',
            'delivered_at' => $this->toDatabaseTime($now),
        ]);
    }

    public function markFailed(int $id, string $errorCode, int $attempts, int $maxAttempts, int $now): void
    {
        $status = $attempts >= $maxAttempts ? 'failed' : 'pending';
        // Exponential backoff, capped at one hour — same shape as v1's own
        // retry queue (min(3600, 2^attempts * 30)).
        $runAfter = $status === 'pending'
            ? $this->toDatabaseTime($now + min(3600, (int) (2 ** $attempts) * 30))
            : null;

        DB::table(self::table())->where('id', $id)->update([
            'status' => $status,
            'attempts' => $attempts,
            'last_error_code' => $errorCode,
            'run_after' => $runAfter,
        ]);
    }

    private function toDatabaseTime(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
