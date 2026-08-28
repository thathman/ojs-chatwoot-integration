<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

/**
 * Maps the current OJS location to a support UX hint.
 *
 * An intent is never an authorization decision. It only helps Chatwoot/Captain
 * start the conversation in the right support mode.
 */
final class SupportIntentResolver
{
    public function resolve(SupportContext $context): string
    {
        $page = strtolower(trim($context->page()));
        $operation = strtolower(trim($context->operation()));
        $route = $page . ':' . $operation;

        if (in_array($page, ['login', 'register', 'user', 'profile'], true)) {
            return 'account_access';
        }

        if ($page === 'submission' || str_contains($route, 'submission:wizard')) {
            return 'submission_help';
        }

        if (in_array($page, ['reviewer', 'review'], true)) {
            return 'review_help';
        }

        if (in_array($page, ['workflow', 'authorDashboard'], true) || str_contains($route, 'workflow:')) {
            return 'manuscript_help';
        }

        if ($page === 'payment' || str_contains($route, 'payment')) {
            return 'payment_help';
        }

        if ($page === 'article') {
            return 'article_help';
        }

        return 'journal_information';
    }
}
