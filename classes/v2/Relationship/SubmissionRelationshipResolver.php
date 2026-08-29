<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Relationship;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SubmissionRelationshipEvidenceProviderInterface;

/**
 * Resolves all proven relationships for an identity/resource pair.
 *
 * A user may have more than one relationship. We intentionally do not collapse
 * this into a single "role", because multi-role OJS users are common and
 * privacy/capability policy must evaluate the actual resource relationship.
 */
final class SubmissionRelationshipResolver
{
    public function __construct(private SubmissionRelationshipEvidenceProviderInterface $provider)
    {
    }

    public function resolve(SupportContext $context, $submission): ?ResourceRelationship
    {
        if (!$context->isAuthenticated() || !is_object($submission) || !method_exists($submission, 'getId')) {
            return null;
        }

        $submissionId = (int) $submission->getId();
        if ($submissionId <= 0) {
            return null;
        }

        $submissionContextId = null;
        if (method_exists($submission, 'getData')) {
            $candidate = (int) $submission->getData('contextId');
            $submissionContextId = $candidate > 0 ? $candidate : null;
        }
        if ($submissionContextId !== null && $submissionContextId !== $context->contextId()) {
            return null;
        }

        $evidence = $this->provider->evidence($context, $submission);
        $types = [];
        foreach (['author', 'reviewer', 'editorial', 'manager', 'site_admin'] as $type) {
            if (($evidence[$type] ?? false) === true) {
                $types[] = $type;
            }
        }

        return new ResourceRelationship('submission', $submissionId, $types, $evidence);
    }
}
