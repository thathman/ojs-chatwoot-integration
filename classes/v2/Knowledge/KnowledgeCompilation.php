<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * The result of one KnowledgeCompiler::compile() call: always scoped to
 * exactly one context/journal and one resolved locale. Every `facts()`
 * entry is guaranteed `isPublic()` — KnowledgeCompiler filters out
 * anything else before this object is ever constructed.
 */
final class KnowledgeCompilation
{
    /** @param KnowledgeFact[] $facts */
    public function __construct(
        private int $contextId,
        private string $locale,
        private array $facts,
        private string $fingerprint,
        private int $generatedAt
    ) {
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return KnowledgeFact[] */
    public function facts(): array
    {
        return $this->facts;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function generatedAt(): int
    {
        return $this->generatedAt;
    }

    /** @return KnowledgeFact[] */
    public function factsWithKeyPrefix(string $prefix): array
    {
        return array_values(array_filter(
            $this->facts,
            static fn (KnowledgeFact $fact): bool => str_starts_with($fact->key(), $prefix)
        ));
    }

    public function fact(string $key): ?KnowledgeFact
    {
        foreach ($this->facts as $fact) {
            if ($fact->key() === $key) {
                return $fact;
            }
        }
        return null;
    }
}
