<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

/**
 * Builds a CaptainProvisioningHealthReport by comparing the expected
 * resource set (one Document, every CanonicalToolCatalog tool, every
 * CanonicalScenarioCatalog scenario) against the local CaptainSyncState
 * records already written during provisioning.
 *
 * State classification per resource:
 *   no local record at all                         -> not_provisioned
 *   owned, no recorded error                        -> owned
 *   owned, but the last sync/update attempt errored -> degraded (a prior
 *                                                       success still
 *                                                       exists remotely,
 *                                                       just stale)
 *   never owned, error mentions "unmanaged"          -> conflict
 *   never owned, any other error (or create failed)  -> failed
 */
final class CaptainProvisioningHealthService
{
    public function __construct(private SupportKnowledgeSyncRepositoryInterface $syncRepository)
    {
    }

    public function buildReport(int $contextId, string $locale): CaptainProvisioningHealthReport
    {
        $resources = [];
        $resources[] = $this->classify(
            $contextId,
            $locale,
            CaptainSyncState::RESOURCE_DOCUMENT,
            '',
            'Support Knowledge Document'
        );

        foreach (CanonicalToolCatalog::all() as $tool) {
            $resources[] = $this->classify($contextId, $locale, CaptainSyncState::RESOURCE_CUSTOM_TOOL, $tool->key(), $tool->title());
        }

        foreach (CanonicalScenarioCatalog::all() as $scenario) {
            $resources[] = $this->classify($contextId, $locale, CaptainSyncState::RESOURCE_SCENARIO, $scenario->key(), $scenario->title());
        }

        return new CaptainProvisioningHealthReport($contextId, $locale, $resources, $this->overallState($resources));
    }

    private function classify(int $contextId, string $locale, string $resourceType, string $resourceKey, string $title): CaptainResourceHealth
    {
        $state = $this->syncRepository->find($contextId, $locale, $resourceType, $resourceKey);

        if ($state === null) {
            return new CaptainResourceHealth($resourceType, $resourceKey, $title, CaptainResourceHealth::STATE_NOT_PROVISIONED, null, null);
        }

        if ($state->isOwned()) {
            $health = $state->lastErrorCode() === null ? CaptainResourceHealth::STATE_OWNED : CaptainResourceHealth::STATE_DEGRADED;
            return new CaptainResourceHealth($resourceType, $resourceKey, $title, $health, $state->lastErrorCode(), $state->lastSuccessfulSyncAt());
        }

        $errorCode = $state->lastErrorCode() ?? '';
        $health = str_contains($errorCode, 'unmanaged') ? CaptainResourceHealth::STATE_CONFLICT : CaptainResourceHealth::STATE_FAILED;
        return new CaptainResourceHealth($resourceType, $resourceKey, $title, $health, $state->lastErrorCode(), null);
    }

    /** @param CaptainResourceHealth[] $resources */
    private function overallState(array $resources): string
    {
        $total = count($resources);
        $notProvisioned = count(array_filter($resources, static fn (CaptainResourceHealth $r): bool => $r->state() === CaptainResourceHealth::STATE_NOT_PROVISIONED));
        if ($notProvisioned === $total) {
            return CaptainProvisioningHealthReport::STATE_NOT_PROVISIONED;
        }

        $owned = count(array_filter($resources, static fn (CaptainResourceHealth $r): bool => $r->state() === CaptainResourceHealth::STATE_OWNED));
        if ($owned === $total) {
            return CaptainProvisioningHealthReport::STATE_HEALTHY;
        }

        return CaptainProvisioningHealthReport::STATE_DEGRADED;
    }
}
