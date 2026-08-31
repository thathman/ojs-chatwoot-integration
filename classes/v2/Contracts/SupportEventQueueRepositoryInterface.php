<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEvent;

/**
 * Persisted Event Bridge v2 delivery queue (docs/v2/TASKLIST.md
 * EVT-011/EVT-014).
 */
interface SupportEventQueueRepositoryInterface
{
    /**
     * Idempotent: a `SupportEvent` whose `idempotencyKey()` already has a
     * row in the queue is a silent no-op (EVT-002's whole point — a
     * retried real occurrence must never enqueue twice). Returns true if a
     * new row was actually inserted, false if it was already queued.
     */
    public function enqueue(SupportEvent $event, string $deliveryMode): bool;

    /** @return array<int,array<string,mixed>> Up to $limit pending rows due for delivery (run_after null or in the past), oldest first. */
    public function fetchPendingBatch(int $limit, int $now): array;

    public function markDelivered(int $id, int $now): void;

    /** @param int $now Used to compute the next run_after via exponential backoff. */
    public function markFailed(int $id, string $errorCode, int $attempts, int $maxAttempts, int $now): void;
}
