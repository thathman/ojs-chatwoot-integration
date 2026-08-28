<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

final class Ojs35CompatibilityAdapter implements OjsCompatibilityAdapterInterface
{
    public function versionFamily(): string
    {
        return '3.5';
    }

    public function supportsVersion(string $version): bool
    {
        return preg_match('/^3\.5\./', trim($version)) === 1;
    }

    public function getContext($request)
    {
        return is_object($request) && method_exists($request, 'getContext')
            ? $request->getContext()
            : null;
    }

    public function getUser($request)
    {
        return is_object($request) && method_exists($request, 'getUser')
            ? $request->getUser()
            : null;
    }

    public function getRoleIds($user, int $contextId): array
    {
        if (!is_object($user) || !method_exists($user, 'getRoles')) {
            return [];
        }

        $ids = [];
        foreach ($user->getRoles($contextId) as $role) {
            if (is_object($role) && method_exists($role, 'getId')) {
                $ids[] = (int) $role->getId();
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    public function getRequestedPage($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedPage')
            ? trim((string) $request->getRequestedPage())
            : '';
    }

    public function getRequestedOperation($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedOp')
            ? trim((string) $request->getRequestedOp())
            : '';
    }
}
