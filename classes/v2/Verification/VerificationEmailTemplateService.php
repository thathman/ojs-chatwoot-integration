<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

use APP\facades\Repo;

/**
 * Settings Console item G / HAR-014 remainder: routes verification
 * subject/body composition through the real OJS EmailTemplate system
 * (`AddVerificationEmailTemplatesMigration` seeds the default rows)
 * instead of `VerificationEmailContentBuilder`'s fixed English strings
 * directly — this is what makes the template a real, journal-manager-
 * editable, localizable OJS EmailTemplate (Settings > Workflow >
 * Emails), with real locale fallback for free via
 * `EmailTemplate::getLocalizedData()` (`DataObject::getBestLocalizedData()`
 * — preferred locale, then context primary locale, then site primary
 * locale, then first available).
 *
 * Security: an edited template body is still just data, never
 * evaluated as Smarty/PHP. `compose()` performs ONLY a strict,
 * allowlisted `{$var}` substitution (see VerificationEmailTemplateKeys::
 * allowedVariables()) — an unrecognized `{$anything}` token in an
 * admin-edited body is left as literal text, never expanded, and never
 * causes any code path other than a plain string replace. The journal
 * name (real admin-configurable, attacker-reachable data per HAR-014)
 * is always HTML-escaped for the body and CRLF-stripped for the
 * subject, exactly as VerificationEmailContentBuilder already proved
 * safe — this class reuses that exact escaping, never re-implements it.
 */
final class VerificationEmailTemplateService
{
    /**
     * @param array<string,string> $variables Real values for this key's
     *   allowed placeholders (see VerificationEmailTemplateKeys::allowedVariables())
     *   — journalName must be the raw, unescaped value; this method
     *   applies the correct escaping per placeholder itself.
     *
     * @return array{subject:string,body:string}
     */
    public static function compose(int $contextId, string $key, array $variables): array
    {
        $template = Repo::emailTemplate()->getByKey($contextId, $key);
        $rawSubject = $template?->getLocalizedData('subject');
        $rawBody = $template?->getLocalizedData('body');

        $subject = is_string($rawSubject) && trim($rawSubject) !== '' ? $rawSubject : self::fallbackSubject($key);
        $body = is_string($rawBody) && trim($rawBody) !== '' ? $rawBody : self::fallbackBody($key);

        return [
            'subject' => self::substitute($subject, $key, $variables, false),
            'body' => self::substitute($body, $key, $variables, true),
        ];
    }

    /**
     * Whether a journal manager has customized this template through
     * the real OJS Email Templates UI — never true for the seeded
     * default alone. `EmailTemplate::Collector::getDefaultQueryBuilder()`
     * (verified against the real deployed lib/pkp source) selects
     * `NULL as email_id` for the virtual default-only row; only a real
     * per-context override row in `email_templates` has a real id. Used
     * only by the Verification tab's status display — compose() never
     * needs this distinction, since getLocalizedData() already merges
     * both correctly.
     */
    public static function isCustomized(int $contextId, string $key): bool
    {
        $template = Repo::emailTemplate()->getByKey($contextId, $key);
        return $template !== null && $template->getId() !== null;
    }

    private static function substitute(string $text, string $key, array $variables, bool $isHtmlBody): string
    {
        foreach (VerificationEmailTemplateKeys::allowedVariables($key) as $variableName) {
            $value = (string) ($variables[$variableName] ?? '');
            $safeValue = $isHtmlBody
                ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                : VerificationEmailContentBuilder::safeSubjectText($value);
            $text = str_replace('{$' . $variableName . '}', $safeValue, $text);
        }
        // The subject line itself must never carry a raw CRLF regardless
        // of which placeholders were actually used — the admin-edited
        // template text surrounding the placeholders is also real,
        // attacker-reachable data once a journal manager can edit it.
        return $isHtmlBody ? $text : VerificationEmailContentBuilder::safeSubjectText($text);
    }

    private static function fallbackSubject(string $key): string
    {
        return 'Support verification for {$journalName}';
    }

    private static function fallbackBody(string $key): string
    {
        return match ($key) {
            VerificationEmailTemplateKeys::PIN =>
                '<p>A verification request was made for support with {$journalName}.</p>'
                . '<p>Your verification code is: <strong>{$pinCode}</strong></p>'
                . '<p>This code expires in {$expiryMinutes} minutes. If you did not request this, you can safely ignore this email.</p>',
            VerificationEmailTemplateKeys::LINK =>
                '<p>A verification request was made for support with {$journalName}.</p>'
                . '<p><a href="{$verificationLink}">Click here to verify</a></p>'
                . '<p>This link expires in {$expiryMinutes} minutes. If you did not request this, you can safely ignore this email.</p>',
            default => '',
        };
    }
}
