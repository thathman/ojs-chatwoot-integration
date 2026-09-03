<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

/**
 * KNO-011: the local cache `ApprovedFaqKnowledgeProvider` reads from and
 * `FaqCacheSyncScheduledTask` writes to. Deliberately the only path
 * either of those two classes uses to reach this data — the provider
 * never calls Chatwoot directly, and the sync task never hands a fact
 * straight to a live knowledge-page render.
 */
interface SupportFaqCacheRepositoryInterface
{
    /**
     * Replaces this journal/locale's entire cached FAQ set with $faqs in
     * one pass — a real sync always reflects Chatwoot's current approved
     * set exactly, never an accumulating superset (a FAQ un-approved or
     * deleted in Chatwoot must actually disappear from the local cache,
     * not linger indefinitely).
     *
     * @param array<int,array{externalId:string,question:string,answer:string}> $faqs
     */
    public function replaceAll(int $contextId, string $locale, array $faqs, int $now): void;

    /** @return array<int,array{externalId:string,question:string,answer:string,syncedAt:string}> */
    public function listApproved(int $contextId, string $locale): array;

    /** Unix timestamp of the most recent successful sync for this journal/locale, or null if never synced. */
    public function lastSyncedAt(int $contextId, string $locale): ?int;
}
