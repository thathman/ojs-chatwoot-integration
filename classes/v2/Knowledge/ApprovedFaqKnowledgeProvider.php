<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportFaqCacheRepositoryInterface;

/**
 * KNO-011: approved Chatwoot Captain FAQ content, tier 4 (lowest) in
 * `KnowledgeSourcePrecedence` — the freeze directive's own ranking. Reads
 * only the local cache `FaqCacheSyncService` populates on its own
 * periodic schedule; never calls Chatwoot itself, so an anonymous
 * `/support-knowledge/` page load is always a fast, safe local DB read
 * with no live-API latency/outage risk, exactly like every other
 * KnowledgeProviderInterface implementer.
 *
 * A journal with no synced FAQ content (never synced, or Captain/the
 * assistant genuinely has none) simply contributes zero facts — never a
 * fabricated placeholder, and never an error surfaced to the visitor.
 */
final class ApprovedFaqKnowledgeProvider implements KnowledgeProviderInterface
{
    public function __construct(private SupportFaqCacheRepositoryInterface $repository)
    {
    }

    public function providerId(): string
    {
        return 'chatwoot.approved_faq';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context) || !method_exists($context, 'getId')) {
            return [];
        }

        try {
            $faqs = $this->repository->listApproved((int) $context->getId(), $locale);
        } catch (\Throwable $e) {
            return [];
        }

        $facts = [];
        foreach ($faqs as $faq) {
            $externalId = (string) ($faq['externalId'] ?? '');
            $question = KnowledgeSanitizer::sanitize((string) ($faq['question'] ?? ''));
            $answer = KnowledgeSanitizer::sanitize((string) ($faq['answer'] ?? ''));
            if ($externalId === '' || $question === '' || $answer === '') {
                continue;
            }

            $facts[] = new KnowledgeFact(
                'faq.' . $externalId,
                "<h3>{$question}</h3>{$answer}",
                KnowledgeClassification::PUBLIC,
                KnowledgeSourcePrecedence::SOURCE_FAQ,
                $locale,
                $this->providerId(),
                $externalId,
                $faq['syncedAt'] ?? null
            );
        }

        return $facts;
    }
}
