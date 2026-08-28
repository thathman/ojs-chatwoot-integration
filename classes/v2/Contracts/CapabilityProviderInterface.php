<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;

/**
 * Narrow extension contract for capability providers.
 *
 * Providers may nominate capabilities, but the Policy Engine remains the final
 * authority and re-applies verification, relationship, feature and journal
 * policy constraints to every returned capability.
 */
interface CapabilityProviderInterface
{
    public function providerId(): string;

    /** @return string[] Capabilities this provider is allowed to nominate. */
    public function declaredCapabilities(): array;

    /** @return string[] Candidate capabilities for this request. */
    public function candidateCapabilities(CapabilityRequest $request): array;
}
