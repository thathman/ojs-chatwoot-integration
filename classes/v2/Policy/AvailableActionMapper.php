<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

/**
 * Converts authoritative capabilities into stable support-facing action names.
 * Captain/agents should consume this output instead of guessing permissions.
 */
final class AvailableActionMapper
{
    /** @var array<string,string> */
    private const ACTIONS = [
        'journal.read_public_info' => 'view_journal_information',
        'account.read_own_support_state' => 'view_account_support_state',
        'submission.list_own' => 'list_my_submissions',
        'submission.read_own_support_status' => 'view_status',
        'submission.read_own_required_actions' => 'view_required_actions',
        'submission.read_own_publication_status' => 'view_publication_status',
        'submission.read_own_payment_status' => 'view_payment_status',
        'submission.read_author_visible_files' => 'view_author_visible_files',
        'review.read_own_assignment' => 'view_review_assignment',
        'support.escalate' => 'contact_editorial_office',
    ];

    /** @return string[] */
    public function map(CapabilityDecision $decision): array
    {
        $actions = [];
        foreach ($decision->allowed() as $capability) {
            if (isset(self::ACTIONS[$capability])) {
                $actions[] = self::ACTIONS[$capability];
            }
        }
        $actions = array_values(array_unique($actions));
        sort($actions);
        return $actions;
    }
}
