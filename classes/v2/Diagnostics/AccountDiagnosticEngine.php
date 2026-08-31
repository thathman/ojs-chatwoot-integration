<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics;

/**
 * ojs_diagnose_account (docs/v2/API_MCP_SPEC.md §7.9). Deliberately scoped
 * to the currently V2-authenticated user's own account — never an
 * email/username Captain supplies, which would make this an account
 * enumeration oracle. Every diagnosis is derived from fields verified
 * against pkp-lib stable-3_5_0 classes/user/User.php
 * (getDisabled()/getDateValidated()); this engine never re-derives or
 * guesses account state beyond what those fields directly prove.
 */
final class AccountDiagnosticEngine
{
    public const SCOPE_ACCOUNT_ACCESS = 'account_access';
    public const SCOPE_LOGIN = 'login';
    public const SCOPE_PASSWORD_RESET = 'password_reset';
    public const SCOPE_PROFILE = 'profile';
    public const SCOPE_MAIL_CONFIGURATION = 'mail_configuration';

    public const SCOPES = [
        self::SCOPE_ACCOUNT_ACCESS,
        self::SCOPE_LOGIN,
        self::SCOPE_PASSWORD_RESET,
        self::SCOPE_PROFILE,
        self::SCOPE_MAIL_CONFIGURATION,
    ];

    /** @param array{driver:string,sandboxForced:bool,smtpHostConfigured:bool}|null $mailConfig */
    public static function diagnose(string $scope, ?bool $disabled, ?string $dateValidated, ?array $mailConfig = null): DiagnosticResult
    {
        return match ($scope) {
            self::SCOPE_ACCOUNT_ACCESS => self::diagnoseAccountAccess($disabled),
            self::SCOPE_LOGIN => self::diagnoseLogin(),
            self::SCOPE_PASSWORD_RESET => self::diagnosePasswordReset(),
            self::SCOPE_PROFILE => self::diagnoseProfile($dateValidated),
            self::SCOPE_MAIL_CONFIGURATION => self::diagnoseMailConfiguration($mailConfig),
            default => DiagnosticResult::unknown('UNKNOWN_SCOPE', 'This diagnostic scope is not recognized.'),
        };
    }

    private static function diagnoseAccountAccess(?bool $disabled): DiagnosticResult
    {
        if ($disabled === true) {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'ACCOUNT_DISABLED',
                'This account has been disabled.',
                ['USER_DISABLED_FLAG_TRUE'],
                ['contact_editorial_office']
            );
        }
        if ($disabled === false) {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'ACCOUNT_ACTIVE',
                'This account is active.',
                ['USER_DISABLED_FLAG_FALSE']
            );
        }
        return DiagnosticResult::unknown('INSUFFICIENT_EVIDENCE', "This account's active/disabled state could not be determined.");
    }

    /**
     * Reaching this diagnostic at all requires an authenticated OJS session
     * (V2 assurance) — that is itself direct proof login currently works.
     * A past login failure (before the current session existed) leaves no
     * evidence this codebase can read, so this scope only ever confirms the
     * present, never explains a prior failure.
     */
    private static function diagnoseLogin(): DiagnosticResult
    {
        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'LOGIN_OK',
            'This account is currently logged in successfully.',
            ['V2_SESSION_AUTHENTICATED']
        );
    }

    /**
     * No OJS evidence about email delivery, spam filtering, or reset-link
     * validity is available to this codebase — never guess whether a reset
     * email was sent, received, or worked.
     */
    private static function diagnosePasswordReset(): DiagnosticResult
    {
        return DiagnosticResult::unknown(
            'INSUFFICIENT_EVIDENCE',
            'Whether a password reset email was sent or delivered cannot be determined from here.'
        );
    }

    /**
     * A null dateValidated is ambiguous — it can mean the email genuinely
     * was never validated, or that the account predates this field, or was
     * created directly by an admin bypassing validation. That ambiguity
     * makes it unsafe to confirm a problem from absence alone; only a
     * present value is confirmable evidence.
     */
    private static function diagnoseProfile(?string $dateValidated): DiagnosticResult
    {
        if ($dateValidated !== null && $dateValidated !== '') {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'EMAIL_VALIDATED',
                "This account's email address has been validated.",
                ['USER_DATE_VALIDATED_PRESENT']
            );
        }
        return DiagnosticResult::unknown(
            'INSUFFICIENT_EVIDENCE',
            "This account's email validation status could not be confirmed.",
            ['USER_DATE_VALIDATED_EMPTY']
        );
    }

    /**
     * DIA-011: a config-shape check, distinct from diagnosePasswordReset()'s
     * delivery-evidence gap — this never claims a specific email was sent
     * or received, only whether the server is configured in a way that
     * could ever send mail at all. Mirrors the real transport-selection
     * logic pkp-lib itself uses (see
     * Ojs35CompatibilityAdapter::getMailTransportConfiguration()).
     */
    private static function diagnoseMailConfiguration(?array $mailConfig): DiagnosticResult
    {
        if ($mailConfig === null || $mailConfig['driver'] === '') {
            return DiagnosticResult::unknown('INSUFFICIENT_EVIDENCE', 'This server\'s mail configuration could not be read.');
        }

        if ($mailConfig['sandboxForced']) {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'MAIL_SENDING_DISABLED',
                'This server is running in sandbox mode: outgoing mail is logged, not actually sent.',
                ['SANDBOX_MODE_FORCES_LOG_MAILER'],
                ['contact_editorial_office']
            );
        }

        if ($mailConfig['driver'] === 'log') {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'MAIL_SENDING_DISABLED',
                'This server is configured to log outgoing mail instead of sending it.',
                ['EMAIL_DEFAULT_DRIVER_LOG'],
                ['contact_editorial_office']
            );
        }

        if ($mailConfig['driver'] === 'smtp' && !$mailConfig['smtpHostConfigured']) {
            return new DiagnosticResult(
                DiagnosticResult::STATUS_CONFIRMED,
                'MAIL_MISCONFIGURED',
                'This server is configured to send mail via SMTP but no SMTP server is set; mail will fail to send.',
                ['EMAIL_DEFAULT_DRIVER_SMTP', 'SMTP_SERVER_EMPTY'],
                ['contact_editorial_office']
            );
        }

        return new DiagnosticResult(
            DiagnosticResult::STATUS_CONFIRMED,
            'MAIL_CONFIGURED',
            'This server appears configured to send outgoing mail.',
            ['EMAIL_DEFAULT_DRIVER_' . strtoupper($mailConfig['driver'])]
        );
    }
}
