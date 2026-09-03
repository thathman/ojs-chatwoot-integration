<?php

declare(strict_types=1);

namespace PKP\core {
    /** Minimal double matching the real JSONMessage's (status, content) shape, established by tests/v2/live-plugin.php. */
    class JSONMessage
    {
        public function __construct(public bool $status, public mixed $content = null)
        {
        }
    }
}

namespace PKP\plugins {
    /** Same minimal in-memory GenericPlugin double established by tests/v2/settings-small-002-export-import-completeness.php. */
    class GenericPlugin
    {
        /** @var array<int,array<string,mixed>> */
        public array $settings = [];

        public function getSetting($contextId, $key)
        {
            return $this->settings[(int) $contextId][(string) $key] ?? null;
        }

        public function updateSetting($contextId, $key, $value, $type = null)
        {
            $this->settings[(int) $contextId][(string) $key] = $value;
        }

        public function getEnabled($contextId = null)
        {
            return true;
        }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    if (!function_exists('__')) {
        function __($key, $params = [])
        {
            return $key;
        }
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

    function har008Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class Har008FakeContext
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    final class Har008FakeRequest
    {
        public function __construct(private Har008FakeContext $context)
        {
        }
        public function getContext(): Har008FakeContext
        {
            return $this->context;
        }
    }

    // ================================================================
    // HAR-008: a journal's real trust-plane credentials must never end
    // up readable from context 0 (the global-defaults fallback source)
    // just because that journal saved a global profile — and a
    // DIFFERENT journal applying that global profile must never
    // silently receive the first journal's credential.
    // ================================================================
    $plugin = new ChatwootIntegrationBasePlugin();
    $journalA = 7;
    $journalB = 9;

    $secretValue = 'real-journal-a-chatwoot-token';
    $nonSecretValue = 'https://journal-a.example.com';

    $plugin->settings[$journalA]['chatwootApiAccessToken'] = $secretValue;
    $plugin->settings[$journalA]['chatwootBaseUrl'] = $nonSecretValue;
    $plugin->settings[$journalA]['enableWidget'] = true;

    $result = $plugin->saveGlobalProfile(new Har008FakeRequest(new Har008FakeContext($journalA)));
    har008Check($result->status === true, 'saveGlobalProfile() must still succeed');

    har008Check(
        ($plugin->settings[0]['chatwootApiAccessToken'] ?? 'NOT_SET') === 'NOT_SET',
        'chatwootApiAccessToken must NEVER be copied into context 0 by saveGlobalProfile() — it is a non-global-eligible trust-plane credential (HAR-008)'
    );
    har008Check(
        ($plugin->settings[0]['chatwootBaseUrl'] ?? null) === $nonSecretValue,
        'an ordinary, non-secret setting must still be copied into context 0 by saveGlobalProfile() — the fix must not be overbroad'
    );

    // Simulate an operator manually placing a secret into context 0 anyway
    // (e.g. a pre-fix database, or a direct DB edit) — applyGlobalProfile()
    // for a DIFFERENT journal must still never pull it in.
    $plugin->settings[0]['chatwootApiAccessToken'] = 'a-leftover-context-0-secret';

    $applyResult = $plugin->applyGlobalProfile(new Har008FakeRequest(new Har008FakeContext($journalB)));
    har008Check($applyResult->status === true, 'applyGlobalProfile() must still succeed');

    har008Check(
        !isset($plugin->settings[$journalB]['chatwootApiAccessToken']),
        'Journal B must never receive a Chatwoot API token via applyGlobalProfile() (HAR-008) — a credential must never authorize a journal it was not issued to'
    );
    har008Check(
        ($plugin->settings[$journalB]['chatwootBaseUrl'] ?? null) === $nonSecretValue,
        'Journal B must still receive ordinary, non-secret global-profile settings via applyGlobalProfile()'
    );

    // Every key the fix skips must be exactly SettingsRegistry::nonGlobalEligibleKeys() — no more, no less.
    foreach (SettingsRegistry::nonGlobalEligibleKeys() as $key) {
        har008Check(SettingsRegistry::get($key)?->secret === true, "'{$key}' must be a real secret to be excluded from global-profile propagation");
    }

    fwrite(STDOUT, "HAR-008 global-profile credential isolation tests passed\n");
}
