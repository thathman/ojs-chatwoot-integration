<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2;

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\CompatibilityAdapterFactory;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ContextResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;

/**
 * Minimal composition root for v2 services.
 *
 * New services are added here only after their contracts are defined and
 * tested so the plugin class remains a thin OJS adapter instead of becoming
 * another monolith.
 */
final class SupportGatewayKernel
{
    private function __construct(
        private string $ojsVersion,
        private ContextResolver $contextResolver,
        private SubmissionRelationshipResolver $submissionRelationshipResolver
    ) {
    }

    public static function forOjsVersion(string $ojsVersion): ?self
    {
        $adapter = CompatibilityAdapterFactory::forVersion($ojsVersion);
        if (!$adapter) {
            return null;
        }

        return new self(
            $ojsVersion,
            new ContextResolver($adapter),
            new SubmissionRelationshipResolver(new OjsSubmissionRelationshipEvidenceProvider())
        );
    }

    public function ojsVersion(): string
    {
        return $this->ojsVersion;
    }

    public function resolveContext($request, string $locale = ''): ?SupportContext
    {
        return $this->contextResolver->resolve($request, $locale);
    }

    public function resolveSubmissionRelationship(SupportContext $context, $submission): ?ResourceRelationship
    {
        return $this->submissionRelationshipResolver->resolve($context, $submission);
    }
}
