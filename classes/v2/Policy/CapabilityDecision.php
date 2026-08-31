<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

/**
 * Final, auditable result from the Policy Engine.
 */
final class CapabilityDecision
{
    /** @var string[] */
    private array $allowed;

    /** @var array<string,string> */
    private array $denied;

    /** @var string[] */
    private array $rejectedProviderCapabilities;

    public function __construct(array $allowed, array $denied, array $rejectedProviderCapabilities = [])
    {
        $this->allowed = array_values(array_unique(array_map('strval', $allowed)));
        sort($this->allowed);

        $normalizedDenied = [];
        foreach ($denied as $capability => $reason) {
            if (!is_string($capability) || $capability === '') {
                continue;
            }
            $normalizedDenied[$capability] = (string) $reason;
        }
        ksort($normalizedDenied);
        $this->denied = $normalizedDenied;

        $this->rejectedProviderCapabilities = array_values(array_unique(array_map('strval', $rejectedProviderCapabilities)));
        sort($this->rejectedProviderCapabilities);
    }

    public function allows(string $capability): bool
    {
        return in_array($capability, $this->allowed, true);
    }

    /** @return string[] */
    public function allowed(): array
    {
        return $this->allowed;
    }

    /** @return array<string,string> */
    public function denied(): array
    {
        return $this->denied;
    }

    public function denialReason(string $capability): ?string
    {
        return $this->denied[$capability] ?? null;
    }

    /** @return string[] */
    public function rejectedProviderCapabilities(): array
    {
        return $this->rejectedProviderCapabilities;
    }

    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'denied' => $this->denied,
            'rejectedProviderCapabilities' => $this->rejectedProviderCapabilities,
        ];
    }
}
