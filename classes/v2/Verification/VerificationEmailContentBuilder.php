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
    public static function subject(string $journalName): string
    {
        return "Support verification for {$journalName}";
    }

    public static function pinBody(string $journalName, string $pin, int $minutesValid): string
    {
        return "<p>A verification request was made for support with {$journalName}.</p>"
            . "<p>Your verification code is: <strong>{$pin}</strong></p>"
            . "<p>This code expires in {$minutesValid} minutes. If you did not request this, you can safely ignore this email.</p>";
    }

    public static function linkBody(string $journalName, string $url, int $minutesValid): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return "<p>A verification request was made for support with {$journalName}.</p>"
            . "<p><a href=\"{$safeUrl}\">Click here to verify</a></p>"
            . "<p>This link expires in {$minutesValid} minutes. If you did not request this, you can safely ignore this email.</p>";
    }
}
