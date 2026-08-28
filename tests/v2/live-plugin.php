<?php

declare(strict_types=1);

namespace PKP\plugins {
    class GenericPlugin
    {
        private array $testSettings = [];

        public function setTestSetting(int $contextId, string $key, mixed $value): void
        {
            $this->testSettings[$contextId][$key] = $value;
        }

        public function getSetting($contextId, $key)
        {
            return $this->testSettings[(int) $contextId][(string) $key] ?? null;
        }
    }
}

namespace PKP\core {
    class JSONMessage
    {
        public function __construct(public bool $status, public mixed $content = null) {}
    }
}

namespace PKP\facades {
    class Locale
    {
        public static function getLocale(): string { return 'en'; }
    }
}

namespace APP\core {
    class Application
    {
        public static function get(): self { return new self(); }
        public function getRequest(): object { return new \stdClass(); }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationPlugin.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

    function pluginCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeExportContext
    {
        public function getId(): int { return 7; }
    }

    final class FakeExportRequest
    {
        public function getContext(): object { return new FakeExportContext(); }
    }

    $plugin = new ChatwootIntegrationV2Plugin();
    $plugin->setTestSetting(7, 'chatwootBaseUrl', 'https://chat.example.test');
    $plugin->setTestSetting(7, 'chatwootWebsiteToken', 'public-widget-token');
    $plugin->setTestSetting(7, 'chatwootApiAccessToken', 'must-not-export');
    $plugin->setTestSetting(7, 'chatwootIdentityValidationSecret', 'must-not-export-either');
    $plugin->setTestSetting(7, 'enableWidget', true);

    $message = $plugin->exportSettings(new FakeExportRequest());
    pluginCheck($message->status === true, 'export should still succeed');
    pluginCheck(($message->content['contextId'] ?? null) === 7, 'export should preserve context id');

    $settings = $message->content['settings'] ?? [];
    pluginCheck(($settings['chatwootBaseUrl'] ?? '') === 'https://chat.example.test', 'non-secret base URL should export');
    pluginCheck(($settings['chatwootWebsiteToken'] ?? '') === 'public-widget-token', 'website token should preserve v1 export behavior');
    pluginCheck(($settings['enableWidget'] ?? null) === true, 'ordinary settings should export');
    pluginCheck(!array_key_exists('chatwootApiAccessToken', $settings), 'API access token must be removed');
    pluginCheck(!array_key_exists('chatwootIdentityValidationSecret', $settings), 'identity secret must be removed');

    $redacted = $message->content['redactedKeys'] ?? [];
    sort($redacted);
    pluginCheck($redacted === ['chatwootApiAccessToken', 'chatwootIdentityValidationSecret'], 'export should identify redacted keys without exposing their values');

    $method = new \ReflectionMethod($plugin, 'addChatwootWidget');
    pluginCheck($method->getDeclaringClass()->getName() === ChatwootIntegrationV2Plugin::class, 'live plugin should intercept widget rendering for v2 context capture');

    $index = file_get_contents($root . '/index.php');
    pluginCheck(str_contains((string) $index, 'ChatwootIntegrationV2Plugin'), 'plugin wrapper should instantiate the transitional v2 shell');

    fwrite(STDOUT, "Live plugin tests passed\n");
}
