<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Verification;

/**
 * Pure crypto helper for the shared challenge engine. No OJS dependency —
 * kept independently testable.
 *
 * A 6-digit PIN has only ~1,000,000 possible values — far too small a
 * space to hash with bare SHA-256 the way the secure-link token is (a
 * stolen challenge table would be trivially brute-forceable offline).
 * PINs are instead hashed with a keyed HMAC using a pepper that is never
 * stored in the challenge table itself (see the plugin's
 * chatwootVerificationPepper setting) — brute-forcing a PIN therefore
 * requires the challenge table *and* the pepper together, not the table
 * alone. The primary defense against *online* guessing is still the
 * per-challenge attempt lockout (VerificationChallenge::isLockedOut()),
 * not the hash scheme.
 *
 * The secure-link token is high-entropy (256 bits) by construction, so a
 * plain SHA-256 digest of it is appropriate — verified against pkp-lib's
 * own convention (see SupportSessionService::randomToken()) rather than
 * inventing a new one.
 */
final class VerificationSecretHasher
{
    private const PIN_LENGTH = 6;
    private const LINK_TOKEN_BYTES = 32;

    public function generatePin(): string
    {
        return str_pad((string) random_int(0, 10 ** self::PIN_LENGTH - 1), self::PIN_LENGTH, '0', STR_PAD_LEFT);
    }

    public function hashPin(string $pepper, string $pin): string
    {
        return hash_hmac('sha256', $pin, $pepper);
    }

    public function verifyPin(string $pepper, string $pin, string $hash): bool
    {
        return hash_equals($this->hashPin($pepper, $pin), $hash);
    }

    public function generateLinkToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::LINK_TOKEN_BYTES)), '+/', '-_'), '=');
    }

    public function hashLinkToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verifyLinkToken(string $token, string $hash): bool
    {
        return hash_equals($this->hashLinkToken($token), $hash);
    }
}
