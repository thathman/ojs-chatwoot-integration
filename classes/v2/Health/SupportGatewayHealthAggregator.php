<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Health;

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ProviderHealth;

/**
 * Pure aggregation of already-computed health signals into one
 * SupportGatewayHealthSummary. Deliberately takes every input as an
 * already-built value (never fetches anything itself, never makes a
 * network call) so it is fully unit-testable and so the caller stays in
 * full control of when/whether each underlying check actually runs.
 *
 * Overall-state rule, deterministic and evidence-only (never a
 * synthetic "healthy" guess):
 *
 *   Chatwoot not configured at all               -> failed (nothing
 *                                                     else here can be
 *                                                     trusted without it)
 *   Knowledge health is failed                     -> failed
 *   any payment provider reports a genuine failure
 *   (degraded/unavailable/unknown, never a
 *   legitimately-not-applicable state)              -> failed
 *   Knowledge health is degraded/empty,
 *   Captain health is degraded,
 *   Support API/MCP/verification not configured,
 *   or dead letters are accumulating (> 0)          -> degraded
 *   otherwise                                       -> healthy
 *
 * Captain's own "not_provisioned" state is deliberately treated as
 * neutral, not degraded — a fresh install genuinely has nothing
 * provisioned yet, which is not evidence of a problem.
 */
final class SupportGatewayHealthAggregator
{
    /**
     * @param array<string,string> $paymentProviderHealth providerId => ProviderHealth::*
     */
    public static function build(
        bool $chatwootConfigured,
        bool $supportApiConfigured,
        bool $mcpConfigured,
        bool $verificationConfigured,
        ?KnowledgeHealthReport $knowledgeHealth,
        ?CaptainProvisioningHealthReport $captainHealth,
        array $paymentProviderHealth,
        int $deadLetterCount
    ): SupportGatewayHealthSummary {
        $genuinelyFailedProviders = array_filter(
            $paymentProviderHealth,
            static fn (string $health): bool => !in_array($health, [ProviderHealth::AVAILABLE, ProviderHealth::DISABLED, ProviderHealth::NOT_INSTALLED, ProviderHealth::INCOMPATIBLE_VERSION], true)
        );

        if (!$chatwootConfigured || $knowledgeHealth?->state() === KnowledgeHealthReport::STATE_FAILED || $genuinelyFailedProviders !== []) {
            $overallState = SupportGatewayHealthSummary::STATE_FAILED;
        } elseif (
            in_array($knowledgeHealth?->state(), [KnowledgeHealthReport::STATE_DEGRADED, KnowledgeHealthReport::STATE_EMPTY], true)
            || $captainHealth?->overallState() === CaptainProvisioningHealthReport::STATE_DEGRADED
            || !$supportApiConfigured
            || !$mcpConfigured
            || !$verificationConfigured
            || $deadLetterCount > 0
        ) {
            $overallState = SupportGatewayHealthSummary::STATE_DEGRADED;
        } else {
            $overallState = SupportGatewayHealthSummary::STATE_HEALTHY;
        }

        return new SupportGatewayHealthSummary(
            $chatwootConfigured,
            $supportApiConfigured,
            $mcpConfigured,
            $verificationConfigured,
            $knowledgeHealth,
            $captainHealth,
            $paymentProviderHealth,
            $deadLetterCount,
            $overallState
        );
    }
}
