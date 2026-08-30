<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * One piece of provenance-carrying journal knowledge (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
 * §2). Immutable, and validates its own classification at construction —
 * a provider cannot hand the compiler an unrecognized classification
 * string and have it silently pass through as something else.
 *
 * `value` must already be display-safe text (KnowledgeSanitizer-cleaned
 * where the source is HTML) — this class does not sanitize; it only
 * carries provenance.
 */
final class KnowledgeFact
{
    public function __construct(
        private string $key,
        private string $value,
        private string $classification,
        private string $source,
        private string $locale,
        private string $providerId,
        private ?string $sourceReference = null,
        private ?string $updatedAt = null
    ) {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('A knowledge fact key must not be empty.');
        }
        if (!in_array($classification, KnowledgeClassification::ALL, true)) {
            throw new \InvalidArgumentException("Unknown knowledge classification: \"{$classification}\".");
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function classification(): string
    {
        return $this->classification;
    }

    public function isPublic(): bool
    {
        return $this->classification === KnowledgeClassification::PUBLIC;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function sourceReference(): ?string
    {
        return $this->sourceReference;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }
}
