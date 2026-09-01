<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

/**
 * Best-effort fixed-window rate limiter for the Support API.
 *
 * ponytail: uses APCu when available and fails open (allows the request)
 * when it isn't, because this is defense-in-depth, not the primary security
 * boundary — the Bearer service token plus exact bound-conversation match
 * is. APCu is per-worker, so this ceiling does not hold across multiple
 * PHP-FPM workers or machines; upgrade to a shared store (DB table/Redis)
 * if real abuse patterns show that ceiling matters.
 *
 * See docs/v2/SECURITY_PRIVACY.md §4.4 (API-007): the documented deployment
 * model expects the primary rate-limit ceiling to be enforced at the
 * fronting reverse-proxy/load-balancer layer, which holds across every
 * worker/machine; this class is the second, independent layer.
 */
final class RateLimiter
{
    public function __construct(
        private int $maxRequests = 30,
        private int $windowSeconds = 60
    ) {
    }

    public function allow(string $key): bool
    {
        if (!function_exists('apcu_fetch') || !function_exists('apcu_store') || !function_exists('apcu_inc')) {
            return true;
        }

        $bucket = 'chatwoot_support_api_rl_' . hash('sha256', $key) . '_' . intdiv(time(), $this->windowSeconds);

        $count = apcu_fetch($bucket);
        if ($count === false) {
            apcu_store($bucket, 1, $this->windowSeconds + 5);
            return true;
        }

        if ((int) $count >= $this->maxRequests) {
            return false;
        }

        apcu_inc($bucket);
        return true;
    }
}
