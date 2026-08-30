<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Read-only, request-time health snapshot of one KnowledgeCompilation
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md). Deliberately NOT the Captain
 * synchronization ledger — there is no `lastSyncedAt`/`lastCaptainFingerprint`/
 * `stale` here, because those require a persisted sync record that does
 * not exist yet (introduced only when Captain Document sync starts).
 * `fingerprint` here is real: it is exactly the current compilation's
 * fingerprint, not a claim about anything previously synced.
 *
 * `conflicts` exposes only safe metadata (key, winner/loser source) —
 * never the losing fact's value, so a stale/possibly-sensitive official
 * page never gets a second copy of its content echoed back through health
 * output.
 */
final class KnowledgeHealthReport
{
    public const STATE_HEALTHY = 'healthy';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_EMPTY = 'empty';
    public const STATE_FAILED = 'failed';

    /**
     * @param string[] $healthyProviders
     * @param string[] $failedProviders
     * @param array<int,array{key:string,winnerSource:string,loserSource:string}> $conflicts
     * @param string[] $generatedRoutes
     */
    public function __construct(
        private int $contextId,
        private string $locale,
        private string $fingerprint,
        private string $state,
        private int $publicFactCount,
        private int $providerCount,
        private array $healthyProviders,
        private array $failedProviders,
        private int $excludedPrivateCount,
        private int $excludedUnsupportedCount,
        private int $conflictCount,
        private array $conflicts,
        private array $generatedRoutes
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

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function publicFactCount(): int
    {
        return $this->publicFactCount;
    }

    public function providerCount(): int
    {
        return $this->providerCount;
    }

    /** @return string[] */
    public function healthyProviders(): array
    {
        return $this->healthyProviders;
    }

    /** @return string[] */
    public function failedProviders(): array
    {
        return $this->failedProviders;
    }

    public function excludedPrivateCount(): int
    {
        return $this->excludedPrivateCount;
    }

    public function excludedUnsupportedCount(): int
    {
        return $this->excludedUnsupportedCount;
    }

    public function conflictCount(): int
    {
        return $this->conflictCount;
    }

    /** @return array<int,array{key:string,winnerSource:string,loserSource:string}> */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /** @return string[] */
    public function generatedRoutes(): array
    {
        return $this->generatedRoutes;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'contextId' => $this->contextId,
            'locale' => $this->locale,
            'fingerprint' => $this->fingerprint,
            'state' => $this->state,
            'publicFactCount' => $this->publicFactCount,
            'providerCount' => $this->providerCount,
            'healthyProviders' => $this->healthyProviders,
            'failedProviders' => $this->failedProviders,
            'excludedPrivateCount' => $this->excludedPrivateCount,
            'excludedUnsupportedCount' => $this->excludedUnsupportedCount,
            'conflictCount' => $this->conflictCount,
            'conflicts' => $this->conflicts,
            'generatedRoutes' => $this->generatedRoutes,
        ];
    }
}
