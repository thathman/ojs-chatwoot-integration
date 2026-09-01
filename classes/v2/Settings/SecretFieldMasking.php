<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Settings;

/**
 * Standard masked-secret settings-form pattern, shared by every secret
 * field the admin settings form renders (`chatwootIdentityValidationSecret`,
 * `chatwootApiAccessToken`, `chatwootSupportApiToken`, `mcpServiceToken`).
 *
 * A secret is never rendered back to the browser in full once saved — the
 * form only ever displays `MASK` for an already-set value. Submitting the
 * mask back unchanged (the common case: the admin edited an unrelated
 * field and simply resubmitted the form) must not overwrite the real
 * stored secret with the literal mask string; submitting anything else
 * (including an empty string, which explicitly clears the credential)
 * replaces it.
 */
final class SecretFieldMasking
{
    public const MASK = '********';

    /** What the settings form should ever put in a secret field's value attribute. */
    public static function displayValue(string $storedValue): string
    {
        return $storedValue !== '' ? self::MASK : '';
    }

    /** What should actually be persisted, given what the form just submitted. */
    public static function resolveSavedValue(string $submittedValue, string $storedValue): string
    {
        return $submittedValue === self::MASK ? $storedValue : $submittedValue;
    }
}
