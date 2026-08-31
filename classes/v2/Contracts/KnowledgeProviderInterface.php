<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;

/**
 * A source of anonymous-safe journal knowledge (docs/v2/KNOWLEDGE_DIAGNOSTICS.md).
 *
 * A KnowledgeProvider MUST NOT depend on a SupportSession, Chatwoot
 * conversation, OJS user, submission relationship, or any V2/V3 capability
 * — everything it returns must be safe for an anonymous, unauthenticated
 * visitor to read. Private/live facts belong in the Support API, never
 * here. `collect()` must never throw in normal operation; KnowledgeCompiler
 * isolates a provider that does throw, but a provider that relies on that
 * isolation to hide broken behavior is a bug, not a feature.
 *
 * A PaymentSupportProviderInterface (or any other private-obligation
 * provider) must never be reused as a KnowledgeProviderInterface — they
 * are different trust contracts. A journal's configured fee *policy* may
 * be public knowledge; a specific submission's paid/unpaid/waived
 * *obligation* never is.
 */
interface KnowledgeProviderInterface
{
    /** Stable identifier, e.g. "core.journal". */
    public function providerId(): string;

    /**
     * @param object $context The OJS Context (journal); duck-typed, never assumed to be a specific class.
     * @param object $request The OJS PKPRequest, needed only for building public URLs.
     *
     * @return KnowledgeFact[]
     */
    public function collect($context, $request, string $locale): array;
}
