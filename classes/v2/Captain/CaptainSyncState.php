<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * Local ownership/fingerprint record for one provisioned Chatwoot Captain
 * resource, keyed by (contextId, locale, resourceType) —
 * docs/v2/KNOWLEDGE_DIAGNOSTICS.md §6. This record, not a name/URL match,
 * is the only proof this codebase ever treats as "we created this remote
 * resource." Immutable; every mutation returns a new instance.
 */
final class CaptainSyncState
{
    public const RESOURCE_DOCUMENT = 'captain_document';

    public function __construct(
        private int $contextId,
        private string $locale,
        private string $resourceType,
        private ?string $remoteResourceId,
        private ?string $lastSuccessfulFingerprint,
        private ?int $lastSuccessfulSyncAt,
        private ?string $lastErrorCode,
        private int $updatedAt
    ) {
    }

    public static function created(int $contextId, string $locale, string $resourceType, string $remoteResourceId, string $fingerprint, int $now): self
    {
        return new self($contextId, $locale, $resourceType, $remoteResourceId, $fingerprint, $now, null, $now);
    }

    /** No remote resource was created/adopted — a reason code only (e.g. an unmanaged document already exists at the target URL). */
    public static function unresolved(int $contextId, string $locale, string $resourceType, string $reasonCode, int $now): self
    {
        return new self($contextId, $locale, $resourceType, null, null, null, $reasonCode, $now);
    }

    public function withSuccess(string $fingerprint, int $now): self
    {
        return new self($this->contextId, $this->locale, $this->resourceType, $this->remoteResourceId, $fingerprint, $now, null, $now);
    }

    public function withError(string $reasonCode, int $now): self
    {
        return new self($this->contextId, $this->locale, $this->resourceType, $this->remoteResourceId, $this->lastSuccessfulFingerprint, $this->lastSuccessfulSyncAt, $reasonCode, $now);
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function remoteResourceId(): ?string
    {
        return $this->remoteResourceId;
    }

    public function lastSuccessfulFingerprint(): ?string
    {
        return $this->lastSuccessfulFingerprint;
    }

    public function lastSuccessfulSyncAt(): ?int
    {
        return $this->lastSuccessfulSyncAt;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function updatedAt(): int
    {
        return $this->updatedAt;
    }

    public function isOwned(): bool
    {
        return $this->remoteResourceId !== null;
    }
}
