<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Runtime;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\SupportGatewayKernel;

/**
 * Safe runtime bridge between OJS requests and the v2 Support Gateway kernel.
 *
 * Unsupported or indeterminate OJS versions deliberately return null so the
 * existing v1 runtime can continue unchanged until a tested adapter exists.
 */
final class RuntimeContextBridge
{
    private OjsVersionResolver $versionResolver;
    private string $resolvedVersion = '';
    private ?SupportGatewayKernel $kernel = null;

    public function __construct(?OjsVersionResolver $versionResolver = null)
    {
        $this->versionResolver = $versionResolver ?? new OjsVersionResolver();
    }

    public function resolve($request, string $locale = ''): ?SupportContext
    {
        $version = $this->versionResolver->resolve();
        if ($version === '') {
            return null;
        }

        if ($version !== $this->resolvedVersion) {
            $this->resolvedVersion = $version;
            $this->kernel = SupportGatewayKernel::forOjsVersion($version);
        }

        if (!$this->kernel) {
            return null;
        }

        try {
            return $this->kernel->resolveContext($request, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bootstrapAuthenticatedSupportSession(SupportContext $context): ?SupportSessionBootstrap
    {
        if (!$this->kernel || !$context->isAuthenticated()) {
            return null;
        }

        try {
            return $this->kernel->bootstrapAuthenticatedSupportSession($context);
        } catch (\Throwable $e) {
            // Missing migration/storage or transient DB failures must not break
            // the legacy widget path. Diagnostics/audit wiring comes later.
            return null;
        }
    }

    public function resolvedVersion(): string
    {
        return $this->resolvedVersion;
    }
}
