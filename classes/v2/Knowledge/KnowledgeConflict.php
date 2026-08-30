<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Recorded when two facts collide on the same (locale, key) — the losing
 * fact per `KnowledgeSourcePrecedence`, kept only as an internal health
 * signal. Never rendered on a generated page; a future admin health
 * screen is the intended consumer (docs/v2/KNOWLEDGE_DIAGNOSTICS.md).
 */
final class KnowledgeConflict
{
    public function __construct(
        private string $key,
        private string $locale,
        private KnowledgeFact $winner,
        private KnowledgeFact $loser
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function winner(): KnowledgeFact
    {
        return $this->winner;
    }

    public function loser(): KnowledgeFact
    {
        return $this->loser;
    }
}
