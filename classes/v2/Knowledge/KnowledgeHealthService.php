<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Builds a KnowledgeHealthReport from one KnowledgeCompilation
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md). Deterministic state rules:
 *
 *   every registered provider failed          -> failed
 *   at least one provider failed (not all)     -> degraded
 *   no provider failed, but zero public facts  -> empty
 *   otherwise                                  -> healthy
 *
 * All providers registered today are core/required (none declare
 * themselves optional yet — see KnowledgeProviderHealth), so a "failed"
 * provider is always a genuine bug, never an absent optional plugin: an
 * absent sibling plugin (Airix Submission Fee, Static Pages) returns an
 * empty fact array without throwing and is recorded as `ok`, exactly as
 * intended — its absence must never look like a system failure.
 */
final class KnowledgeHealthService
{
    public function __construct(private KnowledgeCompiler $compiler)
    {
    }

    public function buildReport($context, $request, int $contextId, string $locale): KnowledgeHealthReport
    {
        $compilation = $this->compiler->compile($context, $request, $contextId, $locale);
        return $this->reportFor($compilation);
    }

    public function reportFor(KnowledgeCompilation $compilation): KnowledgeHealthReport
    {
        $providerHealth = $compilation->providerHealth();
        $healthyProviders = [];
        $failedProviders = [];
        foreach ($providerHealth as $providerId => $state) {
            if ($state === KnowledgeProviderHealth::FAILED) {
                $failedProviders[] = $providerId;
            } else {
                $healthyProviders[] = $providerId;
            }
        }

        $providerCount = count($providerHealth);
        $facts = $compilation->facts();

        if ($providerCount > 0 && count($failedProviders) === $providerCount) {
            $state = KnowledgeHealthReport::STATE_FAILED;
        } elseif (count($failedProviders) > 0) {
            $state = KnowledgeHealthReport::STATE_DEGRADED;
        } elseif (count($facts) === 0) {
            $state = KnowledgeHealthReport::STATE_EMPTY;
        } else {
            $state = KnowledgeHealthReport::STATE_HEALTHY;
        }

        $conflicts = array_map(
            static fn (KnowledgeConflict $conflict): array => [
                'key' => $conflict->key(),
                'winnerSource' => $conflict->winner()->source(),
                'loserSource' => $conflict->loser()->source(),
            ],
            $compilation->conflicts()
        );

        return new KnowledgeHealthReport(
            $compilation->contextId(),
            $compilation->locale(),
            $compilation->fingerprint(),
            $state,
            count($facts),
            $providerCount,
            $healthyProviders,
            $failedProviders,
            $compilation->excludedPrivateCount(),
            $compilation->excludedUnsupportedCount(),
            count($conflicts),
            $conflicts,
            KnowledgeRouteCatalog::categories()
        );
    }
}
