<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\OverviewCardStates;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ProviderHealth;

function overviewCardStatesCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Owner directive 2026-09-04, item F: real-browser evidence showed the
 * Overview tab as a flat text-dump <ul> with no differentiated states —
 * configured was indistinguishable from healthy, optional/off from
 * degraded. OverviewCardStates is the pure logic behind the real card
 * dashboard that replaces it. Fully unit-testable (no OJS runtime, no
 * Guzzle needed) — real behavioral evidence, not just structural.
 */
function baseHealth(): array
{
    return [
        'chatwootConfigured' => false,
        'supportApiConfigured' => false,
        'mcpConfigured' => false,
        'verificationConfigured' => false,
        'knowledgeState' => null,
        'captainState' => null,
        'paymentProviderHealth' => [],
        'deadLetterCount' => 0,
        'pendingEventCount' => 0,
        'queueHealth' => null,
    ];
}

function cardState(array $cards, string $key): string
{
    foreach ($cards as $card) {
        if ($card['key'] === $key) {
            return $card['state'];
        }
    }
    throw new \RuntimeException("no card for key {$key}");
}

// ================================================================
// Part 1: "not configured" must never be presented as "degraded" or
// "healthy" — the owner's explicit anti-pattern.
// ================================================================
$cards = OverviewCardStates::build(baseHealth(), false);
overviewCardStatesCheck(cardState($cards, 'chatwoot') === OverviewCardStates::NOT_CONFIGURED, 'an unconfigured Chatwoot connection must show Not configured, not Degraded');
overviewCardStatesCheck(cardState($cards, 'identity') === OverviewCardStates::OPTIONAL_OFF, 'an unconfigured but genuinely optional Identity Validation must show Optional / Off, not Not configured or Degraded');
overviewCardStatesCheck(cardState($cards, 'mcp') === OverviewCardStates::OPTIONAL_OFF, 'unconfigured MCP (a genuinely optional module) must show Optional / Off, never Degraded');
overviewCardStatesCheck(cardState($cards, 'eventBridge') === OverviewCardStates::HEALTHY, 'an empty, error-free event queue must show Healthy');

// ================================================================
// Part 2: "configured" must never be silently promoted to "healthy"
// without a real health signal saying so.
// ================================================================
$configuredHealth = baseHealth();
$configuredHealth['chatwootConfigured'] = true;
$configuredHealth['supportApiConfigured'] = true;
$configuredHealth['verificationConfigured'] = true;
$cards = OverviewCardStates::build($configuredHealth, true);
overviewCardStatesCheck(cardState($cards, 'chatwoot') === OverviewCardStates::NEVER_CHECKED, 'a configured-but-never-verified Chatwoot connection must show Never checked, not Healthy — configuration is not proof of health');
overviewCardStatesCheck(cardState($cards, 'identity') === OverviewCardStates::CONFIGURED, 'a configured Identity Validation secret must show Configured');
overviewCardStatesCheck(cardState($cards, 'supportApi') === OverviewCardStates::CONFIGURED, 'a configured Support API must show Configured, not Healthy — no live signal proves that yet');
overviewCardStatesCheck(cardState($cards, 'verification') === OverviewCardStates::CONFIGURED, 'a configured Verification/mail module must show Configured, not Healthy');

// ================================================================
// Part 3: Knowledge/Captain states pass through their own real report
// states faithfully (healthy really means healthy here, because these
// two DO have a real live health signal already).
// ================================================================
$reportHealth = baseHealth();
$reportHealth['knowledgeState'] = KnowledgeHealthReport::STATE_HEALTHY;
$reportHealth['captainState'] = CaptainProvisioningHealthReport::STATE_DEGRADED;
$cards = OverviewCardStates::build($reportHealth, false);
overviewCardStatesCheck(cardState($cards, 'knowledge') === OverviewCardStates::HEALTHY, 'a real healthy KnowledgeHealthReport must map to Healthy');
overviewCardStatesCheck(cardState($cards, 'captain') === OverviewCardStates::DEGRADED, 'a real degraded CaptainProvisioningHealthReport must map to Degraded');

$notProvisioned = baseHealth();
$notProvisioned['captainState'] = CaptainProvisioningHealthReport::STATE_NOT_PROVISIONED;
$cards = OverviewCardStates::build($notProvisioned, false);
overviewCardStatesCheck(cardState($cards, 'captain') === OverviewCardStates::OPTIONAL_OFF, 'Captain not provisioned (a real, legitimately optional state) must show Optional / Off, not Not configured or Degraded');

// ================================================================
// Part 4: Event Bridge/queue — dead letters always mean action is
// required; retrying-but-no-dead-letters is merely degraded.
// ================================================================
$deadLetterHealth = baseHealth();
$deadLetterHealth['deadLetterCount'] = 3;
$cards = OverviewCardStates::build($deadLetterHealth, false);
overviewCardStatesCheck(cardState($cards, 'eventBridge') === OverviewCardStates::ACTION_REQUIRED, 'a non-zero dead-letter count must show Action required');

$retryingHealth = baseHealth();
$retryingHealth['queueHealth'] = ['retryingCount' => 2];
$cards = OverviewCardStates::build($retryingHealth, false);
overviewCardStatesCheck(cardState($cards, 'eventBridge') === OverviewCardStates::DEGRADED, 'retrying-but-no-dead-letters must show Degraded, not Action required or Healthy');

// ================================================================
// Part 5: Integrations aggregate — worst real provider state wins,
// no providers installed is Optional / Off, not Not configured.
// ================================================================
overviewCardStatesCheck(OverviewCardStates::aggregateProviderState([]) === OverviewCardStates::OPTIONAL_OFF, 'no installed providers must show Optional / Off');
overviewCardStatesCheck(
    OverviewCardStates::aggregateProviderState(['paystack' => ProviderHealth::AVAILABLE, 'flutterwave' => ProviderHealth::DEGRADED]) === OverviewCardStates::DEGRADED,
    'one degraded provider among healthy ones must make the aggregate Degraded — the worst real state wins'
);
overviewCardStatesCheck(
    OverviewCardStates::aggregateProviderState(['paystack' => ProviderHealth::AVAILABLE]) === OverviewCardStates::HEALTHY,
    'all-available providers must aggregate to Healthy'
);
overviewCardStatesCheck(
    OverviewCardStates::aggregateProviderState(['bachs' => ProviderHealth::NOT_INSTALLED]) === OverviewCardStates::OPTIONAL_OFF,
    'a not-installed provider must aggregate to Optional / Off, not Not configured'
);
overviewCardStatesCheck(
    OverviewCardStates::aggregateProviderState(['bachs' => ProviderHealth::UNAVAILABLE]) === OverviewCardStates::FAILED,
    'an unavailable provider must aggregate to Failed'
);

// ================================================================
// Part 6: template wiring — a real card grid, not the old flat <ul>
// text dump, and one card per real overview key the owner listed.
// ================================================================
$tpl = (string) file_get_contents($root . '/templates/settingsForm.tpl');
overviewCardStatesCheck(str_contains($tpl, 'cwOverviewGrid'), 'the Overview tab must render a real card grid');
overviewCardStatesCheck(str_contains($tpl, '{foreach from=$overviewCards'), 'the card grid must be rendered from a real $overviewCards loop');
$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
overviewCardStatesCheck(str_contains($formSource, 'OverviewCardStates::build('), 'ChatwootSettingsForm must build the overview cards through the real, tested OverviewCardStates class, not inline ad hoc logic');

fwrite(STDOUT, "Overview card states tests passed\n");
