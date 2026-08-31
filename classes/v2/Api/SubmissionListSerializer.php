<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * Allowlist serializer for /ojsSupportGateway/submissions. Never accepts a
 * raw Submission/Publication object or ResourceRelationship::evidence() —
 * only the explicit fields below ever reach the response.
 */
final class SubmissionListSerializer
{
    /**
     * @param array<int,array{relationship:ResourceRelationship,title:string,supportState:string,actionRequired:?bool}> $entries
     *
     * @return array<string,mixed>
     */
    public static function verified(SupportApiRequestContext $context, array $entries, PaginationParams $pagination, bool $hasMore): array
    {
        return [
            'verified' => true,
            'assurance' => $context->assurance(),
            'submissions' => array_map([self::class, 'entry'], $entries),
            'pagination' => [
                'limit' => $pagination->limit,
                'offset' => $pagination->offset,
                'hasMore' => $hasMore,
            ],
        ];
    }

    /** Same generic shape/anti-enumeration rule as /status and /submissionVerify. */
    public static function unverified(SupportApiRequestContext $context): array
    {
        return [
            'verified' => $context->verified(),
            'assurance' => $context->assurance(),
            'submissions' => [],
            'pagination' => [
                'limit' => 0,
                'offset' => 0,
                'hasMore' => false,
            ],
        ];
    }

    /** @param array{relationship:ResourceRelationship,title:string,supportState:string,actionRequired:?bool} $entry */
    private static function entry(array $entry): array
    {
        return [
            'id' => $entry['relationship']->resourceId(),
            'title' => $entry['title'],
            'relationships' => $entry['relationship']->types(),
            'supportState' => $entry['supportState'],
            'actionRequired' => $entry['actionRequired'],
        ];
    }
}
