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
     * Stock Apache + mod_php (the `apache2handler` SAPI this plugin's own
     * Docker/demo deployments run under) does not populate
     * `$_SERVER['HTTP_AUTHORIZATION']` — Apache treats the Authorization
     * header specially and only forwards it to `$_SERVER` when the vhost
     * sets `CGIPassAuth On`, which OJS's own install docs do not require.
     * Confirmed live on ojs-demo.airixmedia.com (TST-017): a real Bearer
     * token was rejected because `HTTP_AUTHORIZATION`/
     * `REDIRECT_HTTP_AUTHORIZATION` were both empty even though the header
     * was actually sent — `getallheaders()` saw it correctly. Both real
     * call sites (Support API, MCP gateway) must resolve the header this
     * way rather than reading `$_SERVER` directly.
     */
    public static function resolveAuthorizationHeader(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $allHeaders = [];
        if (function_exists(__NAMESPACE__ . '\\getallheaders')) {
            $allHeaders = getallheaders();
        } elseif (\function_exists('getallheaders')) {
            $allHeaders = \getallheaders();
        } elseif (\function_exists('apache_request_headers')) {
            $allHeaders = \apache_request_headers();
        }

        foreach ($allHeaders as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

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
