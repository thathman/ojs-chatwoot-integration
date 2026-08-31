<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/submissionSupport
 * (ojs_get_submission_support in docs/v2/API_MCP_SPEC.md §7.5).
 *
 * Deliberately does not accept or expose ResourceRelationship::evidence(),
 * a raw Submission/Publication object, or reviewer identities/internal
 * discussions — only the explicit fields below ever reach the response.
 */
final class SubmissionSupportSerializer
{
    /** @return array<string,mixed> */
    public static function verified(
        ResourceRelationship $relationship,
        string $title,
        string $supportState,
        string $workflowExplanation,
        array $availableActions,
        ?string $stateConfidence = null
    ): array {
        $payload = [
            'verified' => true,
            'resourceVerified' => true,
            'assurance' => 'v3',
            'resource' => [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
            ],
            'relationships' => $relationship->types(),
            'title' => $title,
            'supportState' => $supportState,
            'workflowExplanation' => $workflowExplanation,
            'availableActions' => $availableActions,
        ];

        if ($stateConfidence !== null) {
            $payload['stateConfidence'] = $stateConfidence;
        }

        return $payload;
    }

    /**
     * The single generic shape for every reason a resource could not be
     * verified — same anti-enumeration rule as SubmissionVerificationSerializer.
     *
     * @return array<string,mixed>
     */
    public static function unverified(SupportApiRequestContext $context, array $availableActions): array
    {
        return [
            'verified' => $context->verified(),
            'resourceVerified' => false,
            'assurance' => $context->assurance(),
            'availableActions' => $availableActions,
        ];
    }
}
