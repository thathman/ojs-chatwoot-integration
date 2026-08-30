<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;

/**
 * Compiles registered KnowledgeProviders into one context/locale-scoped
 * KnowledgeCompilation (docs/v2/KNOWLEDGE_DIAGNOSTICS.md).
 *
 * Two hard rules enforced here, not left to provider discipline:
 *  - a fact survives only if its classification is EXACTLY
 *    KnowledgeClassification::PUBLIC — a provider that returns something
 *    unclassified/misclassified is rejected, never defaulted to public;
 *  - a provider that throws is isolated (logged, skipped) and never
 *    prevents another provider's facts, or generation itself, from
 *    succeeding.
 *
 * This class never consults a SupportSession, Chatwoot conversation, OJS
 * user, or capability decision — it has no such inputs to consult.
 */
final class KnowledgeCompiler
{
    /** @var KnowledgeProviderInterface[] */
    private array $providers = [];

    public function registerProvider(KnowledgeProviderInterface $provider): void
    {
        $this->providers[] = $provider;
    }

    public function compile($context, $request, int $contextId, string $requestedLocale): KnowledgeCompilation
    {
        $locale = $this->resolveLocale($context, $requestedLocale);

        $facts = [];
        $providerHealth = [];
        $excludedPrivateCount = 0;
        $excludedUnsupportedCount = 0;

        foreach ($this->providers as $provider) {
            $providerId = $this->safeProviderId($provider);
            try {
                $collected = $provider->collect($context, $request, $locale);
                $providerHealth[$providerId] = KnowledgeProviderHealth::OK;
            } catch (\Throwable $e) {
                $providerHealth[$providerId] = KnowledgeProviderHealth::FAILED;
                error_log(sprintf(
                    '[ChatwootIntegration] Knowledge provider "%s" failed: %s',
                    $providerId,
                    $e->getMessage()
                ));
                continue;
            }

            foreach ($collected as $fact) {
                if (!$fact instanceof KnowledgeFact) {
                    continue;
                }
                if ($fact->classification() === KnowledgeClassification::PRIVATE) {
                    $excludedPrivateCount++;
                    continue;
                }
                if ($fact->classification() === KnowledgeClassification::UNSUPPORTED) {
                    $excludedUnsupportedCount++;
                    continue;
                }
                if (!$fact->isPublic()) {
                    continue;
                }
                $facts[] = $fact;
            }
        }

        [$facts, $conflicts] = $this->resolveConflicts($facts);

        usort(
            $facts,
            static fn (KnowledgeFact $a, KnowledgeFact $b): int => [$a->locale(), $a->key()] <=> [$b->locale(), $b->key()]
        );

        return new KnowledgeCompilation(
            $contextId,
            $locale,
            $facts,
            KnowledgeFingerprint::compute($facts),
            time(),
            $conflicts,
            $providerHealth,
            $excludedPrivateCount,
            $excludedUnsupportedCount
        );
    }

    /**
     * When two facts collide on the same (locale, key), the highest-precedence
     * source (KnowledgeSourcePrecedence) wins and the rest are recorded as
     * conflicts — never silently dropped, never "whichever provider ran
     * last." A tie keeps the first-collected fact (stable, deterministic
     * given a fixed provider registration order).
     *
     * @param KnowledgeFact[] $facts
     * @return array{0:KnowledgeFact[],1:KnowledgeConflict[]}
     */
    private function resolveConflicts(array $facts): array
    {
        $byLocaleKey = [];
        foreach ($facts as $fact) {
            $byLocaleKey[$fact->locale()]["\x00" . $fact->key()][] = $fact;
        }

        $winners = [];
        $conflicts = [];
        foreach ($byLocaleKey as $locale => $byKey) {
            foreach ($byKey as $candidates) {
                $winner = $candidates[0];
                foreach ($candidates as $candidate) {
                    if (KnowledgeSourcePrecedence::rank($candidate->source()) < KnowledgeSourcePrecedence::rank($winner->source())) {
                        $winner = $candidate;
                    }
                }
                $winners[] = $winner;
                foreach ($candidates as $candidate) {
                    if ($candidate !== $winner) {
                        $conflicts[] = new KnowledgeConflict($winner->key(), $locale, $winner, $candidate);
                    }
                }
            }
        }

        return [$winners, $conflicts];
    }

    private function safeProviderId(KnowledgeProviderInterface $provider): string
    {
        try {
            return $provider->providerId();
        } catch (\Throwable $e) {
            return get_class($provider);
        }
    }

    /**
     * requested locale -> journal-supported locale -> journal primary
     * locale -> safe fallback ('en'). Reuses Context's own
     * getSupportedLocales()/getPrimaryLocale() rather than re-deriving
     * OJS's locale rules; duck-typed since $context is never assumed to
     * be a specific class here.
     */
    private function resolveLocale($context, string $requestedLocale): string
    {
        $requestedLocale = trim($requestedLocale);
        if ($requestedLocale === '') {
            $requestedLocale = 'en';
        }

        if (!is_object($context)) {
            return $requestedLocale;
        }

        try {
            $supported = method_exists($context, 'getSupportedLocales') ? (array) $context->getSupportedLocales() : [];
            if (in_array($requestedLocale, $supported, true)) {
                return $requestedLocale;
            }

            $primary = method_exists($context, 'getPrimaryLocale') ? $context->getPrimaryLocale() : null;
            if (is_string($primary) && $primary !== '') {
                return $primary;
            }
        } catch (\Throwable $e) {
            // Fall through to the safe default below.
        }

        return 'en';
    }
}
