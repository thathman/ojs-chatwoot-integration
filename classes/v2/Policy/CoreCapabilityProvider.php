<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\CapabilityProviderInterface;

/**
 * Core v2 support capabilities for the first supported user planes: authors
 * and reviewers. Editorial/staff actions are deliberately not granted here.
 */
final class CoreCapabilityProvider implements CapabilityProviderInterface
{
    public function providerId(): string
    {
        return 'core';
    }

    public function declaredCapabilities(): array
    {
        return CapabilityCatalog::all();
    }

    public function candidateCapabilities(CapabilityRequest $request): array
    {
        $capabilities = [
            'journal.read_public_info',
            'support.escalate',
        ];

        if ($request->supportContext()->isAuthenticated()) {
            $capabilities[] = 'account.read_own_support_state';
            $capabilities[] = 'account.diagnose_own';
            $capabilities[] = 'submission.list_own';
        }

        $relationship = $request->relationship();
        if (!$relationship || $relationship->isEmpty()) {
            return $capabilities;
        }

        if ($relationship->has('author')) {
            $capabilities[] = 'submission.read_own_support_status';
            $capabilities[] = 'submission.read_own_required_actions';
            $capabilities[] = 'submission.read_own_publication_status';
            $capabilities[] = 'submission.read_own_payment_status';
            $capabilities[] = 'submission.read_author_visible_files';
        }

        if ($relationship->has('reviewer')) {
            $capabilities[] = 'submission.read_own_support_status';
            $capabilities[] = 'submission.read_own_required_actions';
            $capabilities[] = 'submission.read_own_publication_status';
            $capabilities[] = 'review.read_own_assignment';
        }

        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities);
        return $capabilities;
    }
}
