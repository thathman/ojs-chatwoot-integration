<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

/** Provider health states (docs/v2/AIRIX360_INTEGRATIONS.md §3). */
final class ProviderHealth
{
    public const AVAILABLE = 'available';
    public const DISABLED = 'disabled';
    public const NOT_INSTALLED = 'not_installed';
    public const INCOMPATIBLE_VERSION = 'incompatible_version';
    public const DEGRADED = 'degraded';
    public const UNAVAILABLE = 'unavailable';
    public const UNKNOWN = 'unknown';

    private function __construct()
    {
    }
}
