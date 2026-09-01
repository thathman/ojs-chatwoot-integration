<?php

declare(strict_types=1);

// ================================================================
// TST-017: real acceptance testing on ojs-demo.airixmedia.com found that
// under stock Apache + mod_php (`apache2handler` SAPI — this plugin's own
// Docker/demo deployment target), a real Bearer token sent by a real
// caller was rejected with AUTHENTICATION_FAILED even though the header
// was actually sent: `$_SERVER['HTTP_AUTHORIZATION']` and
// `REDIRECT_HTTP_AUTHORIZATION` were both empty (Apache only forwards
// Authorization into $_SERVER when the vhost sets `CGIPassAuth On`, which
// this plugin's install docs never required), while `getallheaders()` saw
// it correctly. This test proves
// ServiceTokenAuthenticator::resolveAuthorizationHeader() falls back to
// getallheaders() when $_SERVER carries nothing — reproducing the exact
// real environment (both $_SERVER keys unset, a normal header map
// present) rather than asserting against a mock that assumes the bug
// away.
//
// Namespace-local getallheaders() override: PHP resolves an unqualified
// function call in the *current* namespace before falling back to the
// global one, so defining it here only affects ServiceTokenAuthenticator's
// own file (same namespace) — production code calling the real global
// getallheaders() under real Apache is unaffected.
// ================================================================

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

function getallheaders(): array
{
    return ['Authorization' => 'Bearer real-token-value'];
}

require_once dirname(__DIR__, 2) . '/classes/v2/Http/ServiceTokenAuthenticator.php';

function tst017Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

$resolved = ServiceTokenAuthenticator::resolveAuthorizationHeader();
tst017Check($resolved === 'Bearer real-token-value', 'getallheaders() fallback must be used when $_SERVER carries no Authorization key (the real ojs-demo.airixmedia.com Apache+mod_php symptom)');
tst017Check(ServiceTokenAuthenticator::verify('real-token-value', $resolved), 'a token surfaced only via the getallheaders() fallback must still verify successfully');

// $_SERVER still wins when it is actually populated (a differently
// configured host that does pass Authorization through) — the fallback
// must not override a value that is already present.
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer from-server-superglobal';
tst017Check(ServiceTokenAuthenticator::resolveAuthorizationHeader() === 'Bearer from-server-superglobal', '$_SERVER must take precedence over getallheaders() when both are present');
unset($_SERVER['HTTP_AUTHORIZATION']);

fwrite(STDOUT, "PASS: tst-017-apache-authorization-header-fallback\n");
