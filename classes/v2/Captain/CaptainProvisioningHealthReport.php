<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * Drift/health snapshot across every expected Captain resource (one
 * Document, `CanonicalToolCatalog`'s Custom Tools, `CanonicalScenarioCatalog`'s
 * Scenarios) for one context/locale — docs/v2/KNOWLEDGE_DIAGNOSTICS.md §6
 * "report drift between expected and actual Chatwoot configuration."
 *
 * Deliberately a pure read over the local `CaptainSyncState` records this
 * codebase already writes during provisioning — it never calls the
 * Chatwoot API itself, so it is always cheap and safe to build (no
 * network dependency, no risk of it becoming another thing that needs
 * "unavailable" handling).
 */
final class CaptainProvisioningHealthReport
{
    public const STATE_HEALTHY = 'healthy';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_NOT_PROVISIONED = 'not_provisioned';

    /** @param CaptainResourceHealth[] $resources */
    public function __construct(
        private int $contextId,
        private string $locale,
        private array $resources,
        private string $overallState
    ) {
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return CaptainResourceHealth[] */
    public function resources(): array
    {
        return $this->resources;
    }

    public function overallState(): string
    {
        return $this->overallState;
    }

    public function countByState(string $state): int
    {
        return count(array_filter($this->resources, static fn (CaptainResourceHealth $r): bool => $r->state() === $state));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'contextId' => $this->contextId,
            'locale' => $this->locale,
            'overallState' => $this->overallState,
            'resources' => array_map(static fn (CaptainResourceHealth $r): array => $r->toArray(), $this->resources),
        ];
    }
}
