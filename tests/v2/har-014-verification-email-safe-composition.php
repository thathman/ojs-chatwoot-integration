<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailContentBuilder;

function har014Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-014: $journalName is real, admin-configurable data (Setup >
 * Journal Name) — a journal manager can set it to contain HTML or
 * control characters, deliberately or by accident. It used to be
 * interpolated raw into both an HTML email body (a real injection
 * point into every recipient's mail client) and the mail subject
 * (a real header-injection surface via embedded CRLF). Proves both
 * are now neutralized with real malicious-shaped journal names.
 */

// ================================================================
// HTML injection via journal name — pinBody()/linkBody().
// ================================================================
$maliciousName = '<script>alert(1)</script><img src=x onerror=alert(2)>';

$pinBody = VerificationEmailContentBuilder::pinBody($maliciousName, '123456', 10);
har014Check(!str_contains($pinBody, '<script>'), 'pinBody() must never let a malicious journal name inject a raw <script> tag');
har014Check(!str_contains($pinBody, '<img'), 'pinBody() must never let a malicious journal name inject a raw, functional <img> tag with an event handler');
har014Check(str_contains($pinBody, '&lt;script&gt;'), 'pinBody() must HTML-escape the journal name, not merely strip it');
har014Check(str_contains($pinBody, '123456'), 'the real PIN must still be included');

$linkBody = VerificationEmailContentBuilder::linkBody($maliciousName, 'https://example.com/verify', 10);
har014Check(!str_contains($linkBody, '<script>'), 'linkBody() must never let a malicious journal name inject a raw <script> tag');
har014Check(str_contains($linkBody, '&lt;script&gt;'), 'linkBody() must HTML-escape the journal name, not merely strip it');

// ================================================================
// Mail header injection via journal name — subject().
// ================================================================
$headerInjectionName = "Real Journal\r\nBcc: attacker@example.com\r\nX-Injected: true";
$subject = VerificationEmailContentBuilder::subject($headerInjectionName);
har014Check(!str_contains($subject, "\r") && !str_contains($subject, "\n"), 'subject() must never let a malicious journal name embed a raw CRLF — that is exactly what enables mail header injection (smuggled Bcc/X-Injected headers)');
har014Check(str_contains($subject, 'Real Journal') && str_contains($subject, 'attacker@example.com'), 'the journal name text itself (including any embedded text after the stripped newlines) must still be present, just flattened onto one line, never silently dropped');

// A completely ordinary journal name must render exactly as before —
// this fix must not visibly mangle the normal case.
$normalName = 'Journal of Examples';
har014Check(VerificationEmailContentBuilder::subject($normalName) === 'Support verification for Journal of Examples', 'an ordinary journal name must produce the exact same subject as before this fix');
har014Check(str_contains(VerificationEmailContentBuilder::pinBody($normalName, '654321', 5), 'Journal of Examples'), 'an ordinary journal name must still render in the body, unescaped-looking to a human reader');

fwrite(STDOUT, "HAR-014 verification-email-safe-composition tests passed\n");
