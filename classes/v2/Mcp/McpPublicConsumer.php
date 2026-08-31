<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * MCP-002 ("public MCP consumer model"): builds the capability request for
 * an MCP tool call that has only passed McpAuthenticator — i.e., proven
 * nothing beyond "this is a legitimate MCP client." Always starts at `v0`
 * (unverified) under `CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT`,
 * exactly like an unauthenticated Chatwoot Captain call starts at v0 under
 * `CONSUMER_CHATWOOT_CAPTAIN_PUBLIC` — a new consumer plane must never
 * itself be a shortcut to more authority than any other public consumer
 * gets by default.
 *
 * Reaching V1+ assurance (an authenticated OJS identity) or V3 (a proven
 * resource relationship) still requires going through the same identity/
 * relationship/verification chain every other adapter uses — this class
 * only ever produces the floor a public MCP call starts from, never a
 * shortcut past it.
 */
final class McpPublicConsumer
{
    public static function baseCapabilityRequest(
        SupportContext $unverifiedContext,
        ?ResourceRelationship $relationship = null,
        array $featureFlags = [],
        array $journalPolicy = []
    ): CapabilityRequest {
        return new CapabilityRequest(
            CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
            'v0',
            $unverifiedContext,
            $relationship,
            $featureFlags,
            $journalPolicy
        );
    }
}
