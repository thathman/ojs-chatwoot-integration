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

    public const SCOPES = [
        self::SCOPE_ACCOUNT_ACCESS,
        self::SCOPE_LOGIN,
        self::SCOPE_PASSWORD_RESET,
        self::SCOPE_PROFILE,
    ];

    public static function diagnose(string $scope, ?bool $disabled, ?string $dateValidated): DiagnosticResult
    {
        return match ($scope) {
            self::SCOPE_ACCOUNT_ACCESS => self::diagnoseAccountAccess($disabled),
            self::SCOPE_LOGIN => self::diagnoseLogin(),
            self::SCOPE_PASSWORD_RESET => self::diagnosePasswordReset(),
            self::SCOPE_PROFILE => self::diagnoseProfile($dateValidated),
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
}
