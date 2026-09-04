<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Health;

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ProviderHealth;

/**
 * Settings Console item F (owner directive 2026-09-04): turns
 * SupportGatewayHealthSummary's already-real facts into the named
 * states the owner requires — Healthy / Configured / Optional-Off /
 * Not-configured / Never-checked / Stale / Degraded / Failed /
 * Action-required — for a real card dashboard, replacing the old flat
 * text-dump `<ul>`.
 *
 * Deliberately does NOT invent new tracked state this summary doesn't
 * already have (e.g. "last verified healthy" timestamps) — that is
 * AUD-008/observability's separate, larger job. This class only
 * classifies what is already known, honestly: a module that is merely
 * "configured" is never claimed "healthy" unless a real health signal
 * (Knowledge/Captain/queue) says so.
 */
final class OverviewCardStates
{
    public const HEALTHY = 'healthy';
    public const CONFIGURED = 'configured';
    public const OPTIONAL_OFF = 'optional_off';
    public const NOT_CONFIGURED = 'not_configured';
    public const NEVER_CHECKED = 'never_checked';
    public const DEGRADED = 'degraded';
    public const FAILED = 'failed';
    public const ACTION_REQUIRED = 'action_required';

    /**
     * @param array<string,mixed> $health SupportGatewayHealthSummary::toArray()
     * @param bool $identityValidationConfigured Not part of the health
     *   summary (it is a plain connection setting, not a health signal)
     *   — passed in from the real chatwootIdentityValidationSecret value.
     *
     * @return array<int,array{key:string,state:string}>
     */
    public static function build(array $health, bool $identityValidationConfigured): array
    {
        return [
            ['key' => 'chatwoot', 'state' => $health['chatwootConfigured'] ? self::NEVER_CHECKED : self::NOT_CONFIGURED],
            ['key' => 'identity', 'state' => $identityValidationConfigured ? self::CONFIGURED : self::OPTIONAL_OFF],
            ['key' => 'knowledge', 'state' => self::fromReportState($health['knowledgeState'] ?? null, [
                KnowledgeHealthReport::STATE_HEALTHY => self::HEALTHY,
                KnowledgeHealthReport::STATE_DEGRADED => self::DEGRADED,
                KnowledgeHealthReport::STATE_EMPTY => self::NOT_CONFIGURED,
                KnowledgeHealthReport::STATE_FAILED => self::FAILED,
            ])],
            ['key' => 'captain', 'state' => self::fromReportState($health['captainState'] ?? null, [
                CaptainProvisioningHealthReport::STATE_HEALTHY => self::HEALTHY,
                CaptainProvisioningHealthReport::STATE_DEGRADED => self::DEGRADED,
                CaptainProvisioningHealthReport::STATE_NOT_PROVISIONED => self::OPTIONAL_OFF,
            ])],
            ['key' => 'eventBridge', 'state' => self::eventBridgeState($health)],
            ['key' => 'verification', 'state' => $health['verificationConfigured'] ? self::CONFIGURED : self::NOT_CONFIGURED],
            ['key' => 'supportApi', 'state' => $health['supportApiConfigured'] ? self::CONFIGURED : self::NOT_CONFIGURED],
            ['key' => 'mcp', 'state' => $health['mcpConfigured'] ? self::CONFIGURED : self::OPTIONAL_OFF],
            ['key' => 'integrations', 'state' => self::aggregateProviderState($health['paymentProviderHealth'] ?? [])],
        ];
    }

    /**
     * Overview shows one aggregate Integrations card (worst real state
     * wins); the full per-provider breakdown is item I's own tab.
     *
     * @param array<string,string> $paymentProviderHealth providerId => ProviderHealth::*
     */
    public static function aggregateProviderState(array $paymentProviderHealth): string
    {
        if ($paymentProviderHealth === []) {
            return self::OPTIONAL_OFF;
        }
        $states = array_map(self::providerState(...), $paymentProviderHealth);
        foreach ([self::FAILED, self::ACTION_REQUIRED, self::DEGRADED] as $badState) {
            if (in_array($badState, $states, true)) {
                return $badState;
            }
        }
        if (in_array(self::NEVER_CHECKED, $states, true)) {
            return self::NEVER_CHECKED;
        }
        return in_array(self::HEALTHY, $states, true) ? self::HEALTHY : self::OPTIONAL_OFF;
    }

    /** @param string $providerHealth One real ProviderHealth::* constant. */
    public static function providerState(string $providerHealth): string
    {
        return match ($providerHealth) {
            ProviderHealth::AVAILABLE => self::HEALTHY,
            ProviderHealth::DEGRADED => self::DEGRADED,
            ProviderHealth::UNAVAILABLE, ProviderHealth::INCOMPATIBLE_VERSION => self::FAILED,
            ProviderHealth::DISABLED, ProviderHealth::NOT_INSTALLED => self::OPTIONAL_OFF,
            default => self::NEVER_CHECKED,
        };
    }

    private static function eventBridgeState(array $health): string
    {
        $deadLetters = (int) ($health['deadLetterCount'] ?? 0);
        if ($deadLetters > 0) {
            return self::ACTION_REQUIRED;
        }
        $retrying = (int) ($health['queueHealth']['retryingCount'] ?? 0);
        if ($retrying > 0) {
            return self::DEGRADED;
        }
        return self::HEALTHY;
    }

    /** @param array<string,string> $map real report state constant => our display state */
    private static function fromReportState(?string $reportState, array $map): string
    {
        if ($reportState === null) {
            return self::NOT_CONFIGURED;
        }
        return $map[$reportState] ?? self::NEVER_CHECKED;
    }
}
