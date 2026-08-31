<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSession;

/**
 * Result of the shared Support API resolution pipeline: service auth,
 * conversation-tuple parsing, support-session resolution, and live OJS
 * identity reload, all already done by the time an endpoint sees this.
 *
 * An "unverified" instance is not a failure — it is the safe, generic
 * result for an absent/expired/mismatched conversation, and must be
 * indistinguishable from every other reason a session isn't bound.
 */
final class SupportApiRequestContext
{
    private function __construct(
        private string $correlationId,
        private int $contextId,
        private bool $verified,
        private string $assurance,
        private SupportContext $identity,
        private ?SupportSession $session
    ) {
    }

    public static function unverified(string $correlationId, int $contextId, SupportContext $baseIdentity): self
    {
        return new self($correlationId, $contextId, false, 'v0', $baseIdentity, null);
    }

    public static function verifiedWith(
        string $correlationId,
        int $contextId,
        string $assurance,
        SupportContext $identity,
        SupportSession $session
    ): self {
        return new self($correlationId, $contextId, true, $assurance, $identity, $session);
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }
    public function contextId(): int
    {
        return $this->contextId;
    }
    public function verified(): bool
    {
        return $this->verified;
    }
    public function assurance(): string
    {
        return $this->assurance;
    }
    public function identity(): SupportContext
    {
        return $this->identity;
    }
    public function session(): ?SupportSession
    {
        return $this->session;
    }
}
