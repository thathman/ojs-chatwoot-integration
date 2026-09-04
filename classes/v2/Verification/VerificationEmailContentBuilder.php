<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * Pure content composition for the verification email — no OJS dependency,
 * kept independently testable. Fixed English strings for now, matching the
 * same convention SupportStateMapper::explain() already uses, rather than
 * PKP's `__()` locale function, which would require a full OJS locale
 * bootstrap to test; full localization is a future improvement (see
 * docs/v2/TASKLIST.md IDN-007).
 *
 * One major privacy point, enforced structurally by this class's own
 * signature: no parameter here accepts a manuscript title, submission ID,
 * or any resource detail. Verification proves the OJS account only.
 */
final class VerificationEmailContentBuilder
{
    /**
     * HAR-014: journal name is admin-configurable, real-world data — a
     * journal manager can set it to contain HTML or control characters
     * (deliberately or by accident), and it was previously interpolated
     * raw into both an HTML email body and a mail subject header. Strip
     * CRLF/control characters before use in the subject header (header
     * injection is exactly what a raw newline there would enable), and
     * separately HTML-escape it before use in either HTML body.
     */
    public static function safeSubjectText(string $text): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F]+/', ' ', $text));
    }

    public static function subject(string $journalName): string
    {
        return 'Support verification for ' . self::safeSubjectText($journalName);
    }

    public static function pinBody(string $journalName, string $pin, int $minutesValid): string
    {
        $safeJournalName = htmlspecialchars($journalName, ENT_QUOTES, 'UTF-8');
        $safePin = htmlspecialchars($pin, ENT_QUOTES, 'UTF-8');
        return "<p>A verification request was made for support with {$safeJournalName}.</p>"
            . "<p>Your verification code is: <strong>{$safePin}</strong></p>"
            . "<p>This code expires in {$minutesValid} minutes. If you did not request this, you can safely ignore this email.</p>";
    }

    public static function linkBody(string $journalName, string $url, int $minutesValid): string
    {
        $safeJournalName = htmlspecialchars($journalName, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return "<p>A verification request was made for support with {$safeJournalName}.</p>"
            . "<p><a href=\"{$safeUrl}\">Click here to verify</a></p>"
            . "<p>This link expires in {$minutesValid} minutes. If you did not request this, you can safely ignore this email.</p>";
    }
}
