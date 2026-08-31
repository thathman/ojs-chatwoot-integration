<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/** One expected Captain resource's provisioning/drift state (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §6). */
final class CaptainResourceHealth
{
    public const STATE_OWNED = 'owned';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_CONFLICT = 'conflict';
    public const STATE_FAILED = 'failed';
    public const STATE_NOT_PROVISIONED = 'not_provisioned';

    public function __construct(
        private string $resourceType,
        private string $resourceKey,
        private string $title,
        private string $state,
        private ?string $lastErrorCode,
        private ?int $lastSuccessfulSyncAt
    ) {
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceKey(): string
    {
        return $this->resourceKey;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function lastSuccessfulSyncAt(): ?int
    {
        return $this->lastSuccessfulSyncAt;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'resourceType' => $this->resourceType,
            'resourceKey' => $this->resourceKey,
            'title' => $this->title,
            'state' => $this->state,
            'lastErrorCode' => $this->lastErrorCode,
            'lastSuccessfulSyncAt' => $this->lastSuccessfulSyncAt,
        ];
    }
}
