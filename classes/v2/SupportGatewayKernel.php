<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2;

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\CompatibilityAdapterFactory;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ContextResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;

/**
 * Minimal composition root for v2 services.
 *
 * Phase 0 intentionally keeps this small. New services are added here only
 * after their contracts are defined and tested so the plugin class remains a
 * thin OJS adapter instead of becoming another monolith.
 */
final class SupportGatewayKernel
{
    private function __construct(
        private string $ojsVersion,
        private ContextResolver $contextResolver
    ) {
    }

    public static function forOjsVersion(string $ojsVersion): ?self
    {
        $adapter = CompatibilityAdapterFactory::forVersion($ojsVersion);
        if (!$adapter) {
            return null;
        }

        return new self($ojsVersion, new ContextResolver($adapter));
    }

    public function ojsVersion(): string
    {
        return $this->ojsVersion;
    }

    public function resolveContext($request, string $locale = ''): ?SupportContext
    {
        return $this->contextResolver->resolve($request, $locale);
    }
}
