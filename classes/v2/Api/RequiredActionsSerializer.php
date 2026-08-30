<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/requiredActions
 * (ojs_get_required_actions in docs/v2/API_MCP_SPEC.md §7.6).
 *
 * Deliberately does not accept or expose ResourceRelationship::evidence(),
 * a raw Submission/ReviewAssignment object, or reviewer identities/internal
 * discussions — only the explicit fields below ever reach the response.
 */
final class RequiredActionsSerializer
{
    /** @return array<string,mixed> */
    public static function verified(ResourceRelationship $relationship, array $requiredActions, array $availableActions): array
    {
        return [
            'verified' => true,
            'resourceVerified' => true,
            'assurance' => 'v3',
            'resource' => [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
            ],
            'relationships' => $relationship->types(),
            'requiredActions' => $requiredActions,
            'availableActions' => $availableActions,
        ];
    }

    /**
     * The single generic shape for every reason a resource could not be
     * verified — same anti-enumeration rule as the other submission-scoped
     * serializers.
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
