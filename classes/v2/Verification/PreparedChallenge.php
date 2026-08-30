<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * A freshly created challenge plus the one-time plaintext secret (PIN or
 * link token) the caller needs to put in the outgoing email — never
 * persisted anywhere, including here beyond this single request's lifetime.
 */
final class PreparedChallenge
{
    public function __construct(
        private VerificationChallenge $challenge,
        private string $plaintextSecret
    ) {
    }

    public function challenge(): VerificationChallenge { return $this->challenge; }
    public function plaintextSecret(): string { return $this->plaintextSecret; }
}
