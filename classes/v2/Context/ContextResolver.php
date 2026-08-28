<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

final class ContextResolver
{
    public function __construct(private OjsCompatibilityAdapterInterface $adapter)
    {
    }

    public function resolve($request, string $locale = ''): ?SupportContext
    {
        $context = $this->adapter->getContext($request);
        if (!is_object($context) || !method_exists($context, 'getId')) {
            return null;
        }

        $contextId = (int) $context->getId();
        if ($contextId <= 0) {
            return null;
        }

        $path = '';
        if (method_exists($context, 'getPath')) {
            $path = trim((string) $context->getPath());
        } elseif (method_exists($context, 'getData')) {
            $path = trim((string) ($context->getData('urlPath') ?: $context->getData('path')));
        }

        $user = $this->adapter->getUser($request);
        $userId = null;
        $roleIds = [];
        if (is_object($user) && method_exists($user, 'getId')) {
            $candidate = (int) $user->getId();
            if ($candidate > 0) {
                $userId = $candidate;
                $roleIds = $this->adapter->getRoleIds($user, $contextId);
            }
        }

        return new SupportContext(
            $contextId,
            $path,
            $userId,
            $roleIds,
            $this->adapter->getRequestedPage($request),
            $this->adapter->getRequestedOperation($request),
            $locale
        );
    }
}
