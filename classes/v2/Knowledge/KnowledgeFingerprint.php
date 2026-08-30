<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Deterministic fingerprint over normalized *semantic* facts
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §5) — never over rendered HTML, so a
 * cosmetic template change never looks like a knowledge change. Only
 * `locale`+`key`+`value` feed the fingerprint: provenance fields
 * (`source`, `sourceReference`, `providerId`, `updatedAt`) can legitimately
 * change without the underlying public fact changing, and must not cause
 * a spurious Captain resync.
 */
final class KnowledgeFingerprint
{
    /** @param KnowledgeFact[] $facts */
    public static function compute(array $facts): string
    {
        $canonical = [];
        foreach ($facts as $fact) {
            $canonical[] = $fact->locale() . "\x00" . $fact->key() . "\x00" . $fact->value();
        }

        // Sorted so fact ORDER never affects the fingerprint — only fact CONTENT does.
        sort($canonical, SORT_STRING);

        return hash('sha256', implode("\n", $canonical));
    }
}
