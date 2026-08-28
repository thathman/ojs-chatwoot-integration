<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

final class CompatibilityAdapterFactory
{
    public static function forVersion(string $version): ?OjsCompatibilityAdapterInterface
    {
        $adapters = [
            new Ojs35CompatibilityAdapter(),
        ];

        foreach ($adapters as $adapter) {
            if ($adapter->supportsVersion($version)) {
                return $adapter;
            }
        }

        return null;
    }
}
