<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * Settings Console item G / HAR-014 remainder: the two real OJS
 * `email_key` values for the verification PIN and secure-link emails.
 * Prefixed `CHATWOOT_SUPPORT_` to avoid any collision with a real core
 * or another plugin's key — `email_key` is a global namespace across
 * the whole installation (`email_templates_default_data.email_key` has
 * no plugin-scoping column).
 */
final class VerificationEmailTemplateKeys
{
    public const PIN = 'CHATWOOT_SUPPORT_VERIFICATION_PIN';
    public const LINK = 'CHATWOOT_SUPPORT_VERIFICATION_LINK';

    /** @return string[] Allowlisted `{$var}` placeholders this key's subject/body may use — see VerificationEmailTemplateService::compose(). */
    public static function allowedVariables(string $key): array
    {
        return match ($key) {
            self::PIN => ['journalName', 'pinCode', 'expiryMinutes'],
            self::LINK => ['journalName', 'verificationLink', 'expiryMinutes'],
            default => [],
        };
    }

    /** @return string[] Both real keys, for iteration (Verification tab status, migration seeding). */
    public static function all(): array
    {
        return [self::PIN, self::LINK];
    }
}
