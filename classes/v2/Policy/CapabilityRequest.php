<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;
use InvalidArgumentException;

/**
 * Normalized authorization input for the capability engine.
 *
 * Browser/Chatwoot attributes are not accepted here as authority. Callers must
 * supply a server-resolved SupportContext and, for resource capabilities, a
 * server-resolved ResourceRelationship.
 */
final class CapabilityRequest
{
    public const CONSUMER_CHATWOOT_CAPTAIN_PUBLIC = 'chatwoot_captain_public';
    public const CONSUMER_CHATWOOT_HUMAN_SUPPORT = 'chatwoot_human_support';
    public const CONSUMER_MCP_PUBLIC_SUPPORT = 'mcp_public_support';
    public const CONSUMER_MCP_STAFF = 'mcp_staff';

    /** @var array<string,int> */
    private const ASSURANCE_LEVELS = [
        'v0' => 0,
        'v1' => 1,
        'v2' => 2,
        'v3' => 3,
        'v4' => 4,
    ];

    /** @var string[] */
    private const CONSUMERS = [
        self::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
        self::CONSUMER_CHATWOOT_HUMAN_SUPPORT,
        self::CONSUMER_MCP_PUBLIC_SUPPORT,
        self::CONSUMER_MCP_STAFF,
    ];

    /** @var array<string,bool> */
    private array $featureFlags;

    /** @var array<string,bool> */
    private array $journalPolicy;

    public function __construct(
        private string $consumerPlane,
        private string $verificationAssurance,
        private SupportContext $supportContext,
        private ?ResourceRelationship $relationship = null,
        array $featureFlags = [],
        array $journalPolicy = []
    ) {
        $this->consumerPlane = strtolower(trim($this->consumerPlane));
        if (!in_array($this->consumerPlane, self::CONSUMERS, true)) {
            throw new InvalidArgumentException('Unknown capability consumer plane.');
        }

        $this->verificationAssurance = strtolower(trim($this->verificationAssurance));
        if (!array_key_exists($this->verificationAssurance, self::ASSURANCE_LEVELS)) {
            throw new InvalidArgumentException('Unknown verification assurance level.');
        }

        $this->featureFlags = $this->normalizeBooleanMap($featureFlags);
        $this->journalPolicy = $this->normalizeBooleanMap($journalPolicy);
    }

    public function consumerPlane(): string { return $this->consumerPlane; }
    public function verificationAssurance(): string { return $this->verificationAssurance; }
    public function verificationLevel(): int { return self::ASSURANCE_LEVELS[$this->verificationAssurance]; }
    public function supportContext(): SupportContext { return $this->supportContext; }
    public function relationship(): ?ResourceRelationship { return $this->relationship; }

    public function featureEnabled(string $key, bool $default = false): bool
    {
        return $this->featureFlags[$key] ?? $default;
    }

    public function policyAllows(string $key, bool $default = false): bool
    {
        return $this->journalPolicy[$key] ?? $default;
    }

    /** @return array<string,bool> */
    public function featureFlags(): array { return $this->featureFlags; }

    /** @return array<string,bool> */
    public function journalPolicy(): array { return $this->journalPolicy; }

    /** @return array<string,bool> */
    private function normalizeBooleanMap(array $input): array
    {
        $normalized = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || trim($key) === '') {
                continue;
            }
            $normalized[trim($key)] = (bool) $value;
        }
        ksort($normalized);
        return $normalized;
    }
}
