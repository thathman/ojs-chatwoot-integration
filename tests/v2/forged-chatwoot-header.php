<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function forgedChatwootHeaderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// SEC-002: forged Chatwoot header test.
//
// This plugin has no inbound Chatwoot webhook receiver at all — every
// Support API call is Captain-initiated and gated on a service Bearer
// token (ServiceTokenAuthenticator), never a Chatwoot-signed header.
// Conversation/contact identity is instead re-derived by calling back
// into Chatwoot's own API with the server-side token
// (ChatwootConversationVerifier::verify()) and checking Chatwoot's own
// `meta.hmac_verified` response field — never a client-supplied header.
//
// `CanonicalToolCatalog`'s own docblock records that Chatwoot's
// `X-Chatwoot-Account-Id`/`X-Chatwoot-Conversation-Id`/etc. metadata
// headers were analyzed and deliberately never used for identity. This
// test verifies that claim holds across the entire real source tree, not
// just in one comment — if a future call site ever reads one of these
// headers for identity/authorization, this test fails and forces a
// conscious security review rather than a silent trust regression.
// ================================================================

$v2Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/classes/v2', FilesystemIterator::SKIP_DOTS));

$forbiddenHeaderPatterns = [
    'HTTP_X_CHATWOOT_ACCOUNT_ID',
    'HTTP_X_CHATWOOT_CONVERSATION_ID',
    'HTTP_X_CHATWOOT_CONTACT_ID',
    'HTTP_X_CHATWOOT_INBOX_ID',
    "\$_SERVER['HTTP_X_CHATWOOT",
    '$_SERVER["HTTP_X_CHATWOOT',
];

$violations = [];
foreach ($v2Files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    foreach ($forbiddenHeaderPatterns as $pattern) {
        if (str_contains($source, $pattern)) {
            $violations[] = $file->getFilename() . ': ' . $pattern;
        }
    }
}

forgedChatwootHeaderCheck(
    $violations === [],
    'no file under classes/v2/ may ever read a Chatwoot-supplied X-Chatwoot-* header as identity/authorization evidence — found: ' . implode('; ', $violations)
);

// Sanity: confirm the search itself actually works, by checking a
// pattern this codebase intentionally does use for a *different*,
// legitimately client-suppliable header (X-Correlation-Id, which is only
// ever a diagnostic label, never trusted for identity — see
// CorrelationId::fromRequestOrGenerate()).
$correlationIdSource = (string) file_get_contents($root . '/classes/v2/Api/CorrelationId.php');
forgedChatwootHeaderCheck(
    str_contains($correlationIdSource, 'HTTP_X_CORRELATION_ID'),
    'sanity: the search mechanism itself must actually detect a known real header read, or the negative result above proves nothing'
);

// Confirm real conversation identity comes from re-fetching via the
// server-side API token, never from trusting the caller's claimed
// account/contact/conversation ids directly.
$verifierSource = (string) file_get_contents($root . '/classes/v2/Chatwoot/ChatwootConversationVerifier.php');
forgedChatwootHeaderCheck(str_contains($verifierSource, 'hmac_verified'), 'conversation verification must check Chatwoot\'s own hmac_verified response field, not a caller-supplied claim');
forgedChatwootHeaderCheck(
    str_contains($verifierSource, 'never be accepted') || str_contains($verifierSource, 'never accepted'),
    'the verifier\'s own docblock must record that client-supplied account/contact ids are never trusted directly'
);

fwrite(STDOUT, "Forged Chatwoot header tests passed\n");
