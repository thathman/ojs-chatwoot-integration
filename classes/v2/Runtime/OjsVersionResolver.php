<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Runtime;

use PKP\db\DAORegistry;

/**
 * Reads the installed OJS version through the same VersionDAO mechanism used
 * by OJS core plugins. No version is inferred from plugin metadata or Git.
 */
final class OjsVersionResolver
{
    public function resolve(): string
    {
        try {
            $versionDao = DAORegistry::getDAO('VersionDAO');
            if (!is_object($versionDao) || !method_exists($versionDao, 'getCurrentVersion')) {
                return '';
            }

            $version = $versionDao->getCurrentVersion();
            if (!is_object($version) || !method_exists($version, 'getVersionString')) {
                return '';
            }

            return trim((string) $version->getVersionString());
        } catch (\Throwable $e) {
            return '';
        }
    }
}
