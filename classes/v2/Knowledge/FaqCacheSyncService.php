<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportFaqCacheRepositoryInterface;

/**
 * KNO-011: pulls this journal's real, currently-approved
 * `Captain::AssistantResponse` set from Chatwoot and replaces the local
 * cache with it in one pass — the one place this whole feature ever
 * calls the live Chatwoot API. `ApprovedFaqKnowledgeProvider` never does;
 * it only ever reads what this service already wrote.
 *
 * A sync failure (Chatwoot unreachable, Captain unavailable — an
 * Enterprise-Edition-gated feature that may legitimately be off even
 * when the base API works) leaves the existing cache untouched rather
 * than clearing it — a stale-but-real FAQ set is safer to keep serving
 * than to silently go empty because of a transient outage.
 */
final class FaqCacheSyncService
{
    public function __construct(
        private ChatwootCaptainClientInterface $client,
        private SupportFaqCacheRepositoryInterface $repository
    ) {
    }

    /** @return int Number of FAQ facts synced, or -1 if the sync could not run at all (never touches the existing cache in that case). */
    public function sync(int $contextId, string $locale, int $assistantId, int $now): int
    {
        try {
            $responses = $this->client->listCaptainAssistantResponses($assistantId);
        } catch (\Throwable $e) {
            return -1;
        }

        $faqs = [];
        foreach ($responses as $response) {
            $externalId = trim((string) ($response['id'] ?? ''));
            $question = trim((string) ($response['question'] ?? ''));
            $answer = trim((string) ($response['answer'] ?? ''));
            if ($externalId === '' || $question === '' || $answer === '') {
                continue;
            }
            $faqs[] = ['externalId' => $externalId, 'question' => $question, 'answer' => $answer];
        }

        $this->repository->replaceAll($contextId, $locale, $faqs, $now);
        return count($faqs);
    }
}
