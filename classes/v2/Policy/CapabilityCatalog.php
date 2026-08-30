<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

/**
 * Central policy metadata for v2 public/support capabilities.
 *
 * Staff/editorial mutation capabilities are intentionally absent from this
 * first implementation. Unknown capabilities are therefore denied.
 */
final class CapabilityCatalog
{
    /** @var array<string,array<string,mixed>> */
    private const DEFINITIONS = [
        'journal.read_public_info' => [
            'minVerification' => 0,
            'requiresAuthenticatedIdentity' => false,
            'relationships' => [],
            'feature' => null,
            'policy' => 'public_support',
            'policyDefault' => true,
        ],
        'support.escalate' => [
            'minVerification' => 0,
            'requiresAuthenticatedIdentity' => false,
            'relationships' => [],
            'feature' => null,
            'policy' => 'support_escalation',
            'policyDefault' => true,
        ],
        'account.read_own_support_state' => [
            'minVerification' => 2,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => [],
            'feature' => null,
            'policy' => 'account_support',
            'policyDefault' => true,
        ],
        'account.diagnose_own' => [
            'minVerification' => 2,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => [],
            'feature' => null,
            'policy' => 'account_support',
            'policyDefault' => true,
        ],
        'submission.list_own' => [
            'minVerification' => 2,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => [],
            'feature' => null,
            'policy' => 'submission_support',
            'policyDefault' => true,
        ],
        'submission.diagnose_own' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author', 'reviewer'],
            'feature' => null,
            'policy' => 'submission_support',
            'policyDefault' => true,
        ],
        'submission.read_own_support_status' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author', 'reviewer'],
            'feature' => null,
            'policy' => 'submission_support',
            'policyDefault' => true,
        ],
        'submission.read_own_required_actions' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author', 'reviewer'],
            'feature' => null,
            'policy' => 'submission_support',
            'policyDefault' => true,
        ],
        'submission.read_own_publication_status' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author', 'reviewer'],
            'feature' => null,
            'policy' => 'publication_support',
            'policyDefault' => true,
        ],
        'submission.read_own_payment_status' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author'],
            'feature' => 'payment_status',
            'policy' => 'payment_support',
            'policyDefault' => false,
        ],
        'submission.read_author_visible_files' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['author'],
            'feature' => 'author_visible_files',
            'policy' => 'file_support',
            'policyDefault' => false,
        ],
        'review.read_own_assignment' => [
            'minVerification' => 3,
            'requiresAuthenticatedIdentity' => true,
            'relationships' => ['reviewer'],
            'feature' => null,
            'policy' => 'reviewer_support',
            'policyDefault' => true,
        ],
    ];

    /** @return string[] */
    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    /** @return array<string,mixed>|null */
    public static function definition(string $capability): ?array
    {
        return self::DEFINITIONS[$capability] ?? null;
    }

    public static function knows(string $capability): bool
    {
        return array_key_exists($capability, self::DEFINITIONS);
    }
}
