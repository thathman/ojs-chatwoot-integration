<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;

interface SubmissionRelationshipEvidenceProviderInterface
{
    /**
     * Return normalized relationship evidence for one user/submission pair.
     *
     * @return array<string,bool> Keys may include author, reviewer, editorial,
     *                            manager and site_admin.
     */
    public function evidence(SupportContext $context, $submission): array;
}
