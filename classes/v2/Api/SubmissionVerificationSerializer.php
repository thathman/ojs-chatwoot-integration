<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/submissionVerify.
 *
 * Deliberately does not accept or expose ResourceRelationship::evidence() —
 * that field is internal debugging detail (workflow stage role IDs, raw
 * assignment lookups) and must never reach a Captain-facing response. Only
 * the resource type/id and the normalized relationship type list are
 * surfaced, and only when the resource relationship is actually verified.
 */
final class SubmissionVerificationSerializer
{
    /**
     * $resourceAssurance is a request-time-only value (always "v3" today) —
     * it is deliberately never read from or written back onto the
     * SupportSession. Resource assurance is scoped to this one submission
     * for this one request; the conversation's own stored assurance stays
     * at whatever level bind() established (see docs/v2/ADRS.md — V3 must
     * not become a reusable blanket claim for the whole conversation).
     *
     * @return array<string,mixed>
     */
    public static function verified(
        ResourceRelationship $relationship,
        string $resourceAssurance,
        array $availableActions
    ): array {
        return [
            'verified' => true,
            'resourceVerified' => true,
            'assurance' => $resourceAssurance,
            'resource' => [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
            ],
            'relationships' => $relationship->types(),
            'availableActions' => $availableActions,
        ];
    }

    /**
     * The single generic shape for every reason a resource could not be
     * verified — missing, wrong journal, no relationship, guessed ID, or the
     * conversation itself never reached V2. These must stay indistinguishable
     * from each other; see docs/v2/SECURITY_PRIVACY.md and the /status
     * endpoint's identical anti-enumeration rule.
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
