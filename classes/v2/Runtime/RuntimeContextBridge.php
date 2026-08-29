<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Runtime;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\SupportGatewayKernel;

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
        if ($version === '') return null;

        if ($version !== $this->resolvedVersion) {
            $this->resolvedVersion = $version;
            $this->kernel = SupportGatewayKernel::forOjsVersion($version);
        }
        if (!$this->kernel) return null;

        try {
            return $this->kernel->resolveContext($request, $locale);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bootstrapAuthenticatedSupportSession(SupportContext $context): ?SupportSessionBootstrap
    {
        if (!$this->kernel || !$context->isAuthenticated()) return null;
        try {
            return $this->kernel->bootstrapAuthenticatedSupportSession($context);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function bindAuthenticatedSupportSession(
        string $bindingToken,
        int $contextId,
        int $userId,
        string $chatwootAccountId,
        string $chatwootContactId,
        string $chatwootConversationId
    ): ?SupportSession {
        if (!$this->kernel || $contextId <= 0 || $userId <= 0) return null;

        try {
            return $this->kernel->bindAuthenticatedSupportSession(
                $bindingToken,
                $contextId,
                $userId,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function resolvedVersion(): string { return $this->resolvedVersion; }
}
