<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har022Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-022: an email address is not a guaranteed-unique contact key in
 * Chatwoot — duplicate contacts sharing one email can exist (manual
 * creation, imports, an email that changed OJS-account ownership).
 * findContactByEmail() used to always return the first exact-email
 * match, risking a real OJS event attaching to an unrelated duplicate
 * contact. Guzzle is not available in this local test harness
 * (ChatwootApiService's real HTTP behavior is live-verified via the
 * CLI harness on dell elsewhere this session), so this proves the
 * fix's real wiring at the source level: the identifier-preference
 * logic exists and both real call sites now pass the stable OJS
 * identifier they already have available, rather than omitting it.
 */
$source = (string) file_get_contents("{$root}/ChatwootApiService.php");

$methodStart = strpos($source, 'function findContactByEmail(');
har022Check($methodStart !== false, 'findContactByEmail() must exist');
har022Check(
    (bool) preg_match('/function findContactByEmail\(\$email,\s*string \$identifier = \'\'\)/', $source),
    'findContactByEmail() must accept an optional $identifier parameter, defaulting to empty string for backward compatibility'
);
$methodBody = substr($source, $methodStart, (int) strpos($source, "\n    }\n", $methodStart) - $methodStart);

har022Check(str_contains($methodBody, '$matches[] = $contact;'), 'findContactByEmail() must collect every real email match, not just the first, before deciding which to return');
$identifierCheckPos = strpos($methodBody, "\$identifier !== ''");
$fallbackPos = strpos($methodBody, 'return $matches[0];');
har022Check($identifierCheckPos !== false && $fallbackPos !== false && $identifierCheckPos < $fallbackPos, 'the identifier-preference check must run before falling back to the first email match');
har022Check(str_contains($methodBody, "(string) (\$contact['identifier'] ?? '') === \$identifier"), 'the identifier check must compare against the real contact\'s own identifier field, not a fabricated proxy');

// ================================================================
// Real wiring: both real call sites must pass the stable OJS
// identifier they already have available at the call site, not omit
// it and silently fall back to email-only matching every time.
// ================================================================
$v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
har022Check(
    (bool) preg_match('/findContactByEmail\(\(string\) \$payload\[\'email\'\],\s*\(string\) \(\$payload\[\'identifier\'\] \?\? \'\'\)\)/', $v1Source),
    'the legacy v1 event path must pass its own payload identifier into findContactByEmail(), not just the email'
);

$v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
har022Check(
    (bool) preg_match('/findContactByEmail\(\$author\[\'email\'\],\s*\(string\) \$author\[\'userId\'\]\)/', $v2Source),
    'the v2 event-delivery path must pass the resolved author\'s real OJS userId into findContactByEmail(), not just the email'
);

fwrite(STDOUT, "HAR-022 contact-identity-prefers-stable-identifier tests passed\n");
