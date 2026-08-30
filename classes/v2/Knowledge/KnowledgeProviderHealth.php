<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Per-provider health state recorded by KnowledgeCompiler::compile().
 * Deliberately only two values: whether `collect()` completed without
 * throwing. There is no third "degraded"/"not_applicable" provider state
 * yet — a provider that legitimately has nothing to say (an optional
 * sibling plugin absent) simply returns an empty array and is `OK`, not a
 * distinct state, because no provider registered today declares itself
 * optional/required. Introduce that distinction only when a real
 * optional third-party KnowledgeProvider registers through a hook (the
 * same shape `SupportProviderRegistry` already uses for payment
 * providers) and the difference actually matters.
 */
final class KnowledgeProviderHealth
{
    public const OK = 'ok';
    public const FAILED = 'failed';

    private function __construct()
    {
    }
}
