<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Policy;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\CapabilityProviderInterface;
use InvalidArgumentException;

/**
 * Final authority for v2 support capabilities.
 *
 * Providers can nominate only. Every nomination is checked again against the
 * central capability catalog. Unknown capabilities fail closed.
 */
final class CapabilityPolicyEngine
{
    /** @var CapabilityProviderInterface[] */
    private array $providers;

    /**
     * @param CapabilityProviderInterface[] $providers
     */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers ?: [new CoreCapabilityProvider()];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof CapabilityProviderInterface) {
                throw new InvalidArgumentException('Capability providers must implement CapabilityProviderInterface.');
            }
        }
    }

    public function evaluate(CapabilityRequest $request): CapabilityDecision
    {
        $candidates = [];
        $rejected = [];

        foreach ($this->providers as $provider) {
            $declared = array_values(array_unique(array_filter(array_map('strval', $provider->declaredCapabilities()))));
            foreach ($provider->candidateCapabilities($request) as $candidate) {
                if (!is_string($candidate) || $candidate === '') {
                    continue;
                }

                if (!in_array($candidate, $declared, true) || !CapabilityCatalog::knows($candidate)) {
                    $rejected[] = $provider->providerId() . ':' . $candidate;
                    continue;
                }

                $candidates[$candidate] = true;
            }
        }

        $allowed = [];
        $denied = [];

        foreach (CapabilityCatalog::all() as $capability) {
            if (!isset($candidates[$capability])) {
                $denied[$capability] = 'provider_not_enabled';
                continue;
            }

            $definition = CapabilityCatalog::definition($capability);
            if (!$definition) {
                $denied[$capability] = 'unknown_capability';
                continue;
            }

            if (
                ($definition['requiresAuthenticatedIdentity'] ?? false)
                && !$request->supportContext()->isAuthenticated()
            ) {
                $denied[$capability] = 'authentication_required';
                continue;
            }

            $minimumVerification = (int) ($definition['minVerification'] ?? 0);
            if ($request->verificationLevel() < $minimumVerification) {
                $denied[$capability] = 'verification_required';
                continue;
            }

            $relationships = $definition['relationships'] ?? [];
            if (is_array($relationships) && $relationships !== []) {
                $relationship = $request->relationship();
                $matches = false;
                if ($relationship) {
                    foreach ($relationships as $requiredRelationship) {
                        if ($relationship->has((string) $requiredRelationship)) {
                            $matches = true;
                            break;
                        }
                    }
                }
                if (!$matches) {
                    $denied[$capability] = 'relationship_required';
                    continue;
                }
            }

            $feature = $definition['feature'] ?? null;
            if (is_string($feature) && $feature !== '' && !$request->featureEnabled($feature, false)) {
                $denied[$capability] = 'feature_unavailable';
                continue;
            }

            $policy = $definition['policy'] ?? null;
            $policyDefault = (bool) ($definition['policyDefault'] ?? false);
            if (is_string($policy) && $policy !== '' && !$request->policyAllows($policy, $policyDefault)) {
                $denied[$capability] = 'journal_policy_denied';
                continue;
            }

            $allowed[] = $capability;
        }

        return new CapabilityDecision($allowed, $denied, $rejected);
    }
}
