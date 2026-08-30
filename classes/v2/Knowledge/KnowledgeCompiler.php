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
        foreach ($this->providers as $provider) {
            try {
                $collected = $provider->collect($context, $request, $locale);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[ChatwootIntegration] Knowledge provider "%s" failed: %s',
                    $this->safeProviderId($provider),
                    $e->getMessage()
                ));
                continue;
            }

            foreach ($collected as $fact) {
                if (!$fact instanceof KnowledgeFact || !$fact->isPublic()) {
                    continue;
                }
                $facts[] = $fact;
            }
        }

        usort(
            $facts,
            static fn (KnowledgeFact $a, KnowledgeFact $b): int => [$a->locale(), $a->key()] <=> [$b->locale(), $b->key()]
        );

        return new KnowledgeCompilation($contextId, $locale, $facts, KnowledgeFingerprint::compute($facts), time());
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
