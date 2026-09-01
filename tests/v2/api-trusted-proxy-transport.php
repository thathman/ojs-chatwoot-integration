<?php

declare(strict_types=1);

namespace PKP\config {
    /**
     * Fakes pkp-lib's real Config::getVar($section, $key, $default) surface
     * — same stub shape as tests/v2/mail-configuration-diagnostic.php — so
     * API-007's trusted-proxy gate can be exercised without a live
     * config.inc.php.
     */
    final class Config
    {
        /** @var array<string,array<string,mixed>> */
        public static array $vars = [];

        public static function getVar(string $section, string $key, mixed $default = null): mixed
        {
            return self::$vars[$section][$key] ?? $default;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
    use PKP\config\Config;

    function apiTrustedProxyCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * API-007: real behavioral proof of transportSecure()'s trusted-proxy
     * gate. Calls the actual private method via reflection (it is pure —
     * $_SERVER + one Config::getVar() read, no OJS runtime dependency) so
     * this is a real execution proof, not a string match on the source.
     */
    function callTransportSecure(): bool
    {
        $resolver = new SupportApiRequestResolver(new RuntimeContextBridge());
        $method = new \ReflectionMethod(SupportApiRequestResolver::class, 'transportSecure');
        return $method->invoke($resolver);
    }

    $originalServer = $_SERVER;

    // ================================================================
    // A direct HTTPS connection is always secure, regardless of the
    // trusted-proxy setting or any forwarded header.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    $_SERVER['HTTPS'] = 'on';
    Config::$vars = ['general' => ['trust_x_forwarded_for' => false]];
    apiTrustedProxyCheck(callTransportSecure() === true, 'a real direct HTTPS connection must always be treated as secure');

    // ================================================================
    // Plain HTTP with no forwarded header, and no trusted proxy declared,
    // must never be treated as secure.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    Config::$vars = ['general' => ['trust_x_forwarded_for' => false]];
    apiTrustedProxyCheck(callTransportSecure() === false, 'plain HTTP with no trusted proxy declared and no forwarded header must be rejected');

    // ================================================================
    // The core issue this closes: an attacker who reaches this box over
    // plain HTTP and forges X-Forwarded-Proto: https must NOT be believed
    // when the admin has not declared a trusted reverse proxy in front of
    // this OJS install.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    Config::$vars = ['general' => ['trust_x_forwarded_for' => false]];
    apiTrustedProxyCheck(callTransportSecure() === false, 'a caller-forged X-Forwarded-Proto must never be trusted without a configured trusted proxy — this is the real vulnerability API-007 closes');

    // ================================================================
    // With a trusted reverse proxy actually declared (the real OJS core
    // config flag this reuses), the same forwarded header is honored.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    Config::$vars = ['general' => ['trust_x_forwarded_for' => true]];
    apiTrustedProxyCheck(callTransportSecure() === true, 'X-Forwarded-Proto must be honored once the admin has declared a trusted reverse proxy');

    // ================================================================
    // Even with a trusted proxy declared, a forwarded header claiming
    // plain http must never be upgraded to secure.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
    Config::$vars = ['general' => ['trust_x_forwarded_for' => true]];
    apiTrustedProxyCheck(callTransportSecure() === false, 'a trusted proxy reporting plain http must still be rejected');

    // ================================================================
    // The undeclared/default case: OJS core's own real default is `true`
    // when the config key is entirely absent (documented backwards-
    // compatibility default in PKPRequest::getRemoteAddr()), so an
    // untouched config.inc.php still honors a genuine reverse-proxy
    // deployment without requiring a new admin action.
    // ================================================================
    $_SERVER = $originalServer;
    unset($_SERVER['HTTPS']);
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    Config::$vars = [];
    apiTrustedProxyCheck(callTransportSecure() === true, 'an entirely unset trust_x_forwarded_for must fall back to the real OJS core default (true) for backwards compatibility');

    $_SERVER = $originalServer;

    $resolverSource = (string) file_get_contents($root . '/classes/v2/Api/SupportApiRequestResolver.php');
    apiTrustedProxyCheck(str_contains($resolverSource, "Config::getVar('general', 'trust_x_forwarded_for', true)"), 'the resolver must gate X-Forwarded-Proto trust on the real, existing OJS core trusted-proxy flag, never a new unchecked plugin setting');

    fwrite(STDOUT, "API trusted-proxy transport tests passed\n");
}
