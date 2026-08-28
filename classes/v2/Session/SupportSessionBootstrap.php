<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Session;

/**
 * One-time browser bootstrap returned only when a live OJS session creates a
 * fresh support identity. The raw binding token is intentionally absent from
 * SupportSession persistence and must never be logged.
 */
final class SupportSessionBootstrap
{
    public function __construct(
        private string $sessionRef,
        private string $bindingToken,
        private string $assuranceLevel,
        private int $bindingExpiresAt,
        private int $sessionExpiresAt
    ) {
    }

    public function sessionRef(): string { return $this->sessionRef; }
    public function bindingToken(): string { return $this->bindingToken; }
    public function assuranceLevel(): string { return $this->assuranceLevel; }
    public function bindingExpiresAt(): int { return $this->bindingExpiresAt; }
    public function sessionExpiresAt(): int { return $this->sessionExpiresAt; }

    /**
     * Ephemeral transport payload. This is a bearer bootstrap, not an
     * authorization claim, and it must be exchanged server-side before use.
     */
    public function browserPayload(): array
    {
        return [
            'contract' => 'one_time_binding',
            'sessionRef' => $this->sessionRef,
            'bindingToken' => $this->bindingToken,
            'assurance' => $this->assuranceLevel,
            'bindingExpiresAt' => gmdate('c', $this->bindingExpiresAt),
            'sessionExpiresAt' => gmdate('c', $this->sessionExpiresAt),
        ];
    }
}
