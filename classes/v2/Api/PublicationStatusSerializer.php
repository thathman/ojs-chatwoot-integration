<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/publicationStatus
 * (ojs_get_publication_status in docs/v2/API_MCP_SPEC.md §7.8).
 *
 * Deliberately does not accept or expose ResourceRelationship::evidence(),
 * a raw Submission/Publication/Issue object, or reviewer identities/internal
 * discussions — only the explicit fields below ever reach the response.
 */
final class PublicationStatusSerializer
{
    /**
     * @param array{volume:?int,number:?int,year:?int}|null $issue
     *
     * @return array<string,mixed>
     */
    public static function verified(
        ResourceRelationship $relationship,
        string $status,
        ?string $doi,
        ?string $publicUrl,
        ?array $issue,
        array $availableActions
    ): array {
        return [
            'verified' => true,
            'resourceVerified' => true,
            'assurance' => 'v3',
            'resource' => [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
            ],
            'relationships' => $relationship->types(),
            'status' => $status,
            'doi' => $doi,
            'publicUrl' => $publicUrl,
            'issue' => $issue,
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
