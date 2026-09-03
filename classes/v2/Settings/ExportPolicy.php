<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Settings;

/**
 * Defines which plugin settings may leave OJS through an export operation.
 *
 * This policy is intentionally independent of HTTP/UI code so every future
 * export path (settings backup, diagnostics, support bundle) can share the
 * same deny-list instead of rediscovering secret handling independently.
 */
final class ExportPolicy
{
    /**
     * UX-024: sourced from the canonical SettingsRegistry instead of its
     * own independently-maintained key list — tests/v2/settings-registry.php
     * proves this agrees with every other secret-key list in the plugin.
     *
     * @return string[]
     */
    public static function sensitiveKeys(): array
    {
        return SettingsRegistry::secretKeys();
    }

    public static function isSensitive(string $key): bool
    {
        return in_array($key, self::sensitiveKeys(), true);
    }

    /**
     * Remove secret-bearing values from an export payload.
     *
     * Unknown keys are preserved here because the caller already owns the
     * allow-list of exportable settings. Security-sensitive callers that do
     * not have an allow-list should not use this method as their only filter.
     *
     * @param array<string,mixed> $settings
     *
     * @return array{settings: array<string,mixed>, redactedKeys: string[]}
     */
    public static function filter(array $settings): array
    {
        $safe = [];
        $redacted = [];

        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (self::isSensitive($key)) {
                $redacted[] = $key;
                continue;
            }
            $safe[$key] = $value;
        }

        sort($redacted);

        return [
            'settings' => $safe,
            'redactedKeys' => $redacted,
        ];
    }
}
