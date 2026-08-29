<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

/**
 * Verifies the Bearer service token Chatwoot Captain sends on Support API
 * calls. Never trusts any Chatwoot-supplied identity by itself (see
 * SECURITY_PRIVACY.md 4.1) — this only proves the caller holds a configured
 * secret; conversation binding is verified independently afterwards.
 */
final class ServiceTokenAuthenticator
{
    /**
     * $configuredTokens may be a comma-separated list so an admin can rotate
     * the secret without downtime: configure "new,old", update Captain's
     * tool config to the new token, then remove the old one.
     */
    public static function verify(string $configuredTokens, ?string $authorizationHeader): bool
    {
        $configuredTokens = trim($configuredTokens);
        if ($configuredTokens === '' || !is_string($authorizationHeader)) {
            return false;
        }

        $header = trim($authorizationHeader);
        if (!str_starts_with($header, 'Bearer ')) {
            return false;
        }

        $provided = trim(substr($header, 7));
        if ($provided === '') {
            return false;
        }

        $matched = false;
        foreach (explode(',', $configuredTokens) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && hash_equals($candidate, $provided)) {
                $matched = true;
            }
        }

        return $matched;
    }
}
