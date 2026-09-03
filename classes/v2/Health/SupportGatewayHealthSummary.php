<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Health;

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;

/**
 * One consolidated operational snapshot for the admin Support Gateway
 * health section, built by SupportGatewayHealthAggregator. Read-only
 * value object — never mutated after construction.
 */
final class SupportGatewayHealthSummary
{
    public const STATE_HEALTHY = 'healthy';
    public const STATE_DEGRADED = 'degraded';
    public const STATE_FAILED = 'failed';

    /** @param array<string,string> $paymentProviderHealth providerId => ProviderHealth::* */
    public function __construct(
        private bool $chatwootConfigured,
        private bool $supportApiConfigured,
        private bool $mcpConfigured,
        private bool $verificationConfigured,
        private ?KnowledgeHealthReport $knowledgeHealth,
        private ?CaptainProvisioningHealthReport $captainHealth,
        private array $paymentProviderHealth,
        private int $deadLetterCount,
        private int $pendingEventCount,
        private string $overallState,
        private ?EventQueueHealthReport $queueHealth = null
    ) {
    }

    public function chatwootConfigured(): bool
    {
        return $this->chatwootConfigured;
    }

    public function supportApiConfigured(): bool
    {
        return $this->supportApiConfigured;
    }

    public function mcpConfigured(): bool
    {
        return $this->mcpConfigured;
    }

    public function verificationConfigured(): bool
    {
        return $this->verificationConfigured;
    }

    public function knowledgeHealth(): ?KnowledgeHealthReport
    {
        return $this->knowledgeHealth;
    }

    public function captainHealth(): ?CaptainProvisioningHealthReport
    {
        return $this->captainHealth;
    }

    /** @return array<string,string> */
    public function paymentProviderHealth(): array
    {
        return $this->paymentProviderHealth;
    }

    public function deadLetterCount(): int
    {
        return $this->deadLetterCount;
    }

    public function pendingEventCount(): int
    {
        return $this->pendingEventCount;
    }

    /** AUD-008/AUD-011: safe queue detail (retry/dead-letter breakdown, error codes) — null if the queue repository could not be read. */
    public function queueHealth(): ?EventQueueHealthReport
    {
        return $this->queueHealth;
    }

    public function overallState(): string
    {
        return $this->overallState;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'overallState' => $this->overallState,
            'chatwootConfigured' => $this->chatwootConfigured,
            'supportApiConfigured' => $this->supportApiConfigured,
            'mcpConfigured' => $this->mcpConfigured,
            'verificationConfigured' => $this->verificationConfigured,
            'knowledgeState' => $this->knowledgeHealth?->state(),
            'captainState' => $this->captainHealth?->overallState(),
            'paymentProviderHealth' => $this->paymentProviderHealth,
            'deadLetterCount' => $this->deadLetterCount,
            'pendingEventCount' => $this->pendingEventCount,
            'queueHealth' => $this->queueHealth?->toArray(),
        ];
    }
}
