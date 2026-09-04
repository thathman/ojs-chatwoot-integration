<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Settings;

/**
 * Settings Console item H (API & MCP tab, owner directive 2026-09-04):
 * "secure generate/rotate workflow" for the two plugin-owned service
 * credentials (`chatwootSupportApiToken`, `mcpServiceToken`) — before
 * this, an admin had to invent and paste their own bearer token by
 * hand into a plain password field, with no guarantee of real entropy
 * and no supported way to change it without picking a brand-new value
 * themselves.
 *
 * Pure, stateless: generates a real cryptographically random value
 * only. Never touches settings storage itself (the caller decides when
 * to persist it) and never logs or returns anything but the fresh
 * value — there is nothing here to accidentally leak a previously
 * generated secret.
 */
final class ServiceCredentialGenerator
{
    /** @var string[] The only two real settings this generator may ever produce a value for — see SettingsRegistry's own secret classification for both. */
    private const ALLOWED_KEYS = ['chatwootSupportApiToken', 'mcpServiceToken'];

    public static function isAllowedKey(string $key): bool
    {
        return in_array($key, self::ALLOWED_KEYS, true);
    }

    /** 64 real hex characters (32 random bytes) — long enough that guessing is infeasible, short enough to remain a normal-looking bearer token. */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
