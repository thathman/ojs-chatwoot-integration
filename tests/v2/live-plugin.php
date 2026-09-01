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

        public function getEnabled($contextId = null)
        {
            return (bool) ($this->testSettings[(int) $contextId]['enableWidget'] ?? false);
        }
    }
}

namespace PKP\core {
    class JSONMessage
    {
        public function __construct(public bool $status, public mixed $content = null)
        {
        }
    }
}

namespace PKP\scheduledTask {
    abstract class ScheduledTask
    {
        public function getName(): string
        {
            return 'test-task';
        }
        abstract protected function executeActions(): bool;
        public function execute(): bool
        {
            return $this->executeActions();
        }
        public function addExecutionLogEntry(string $message, ?string $type = null): void
        {
        }
    }

    /** Mirrors only the fluent surface ChatwootIntegrationV2Plugin::registerSchedules() actually calls. */
    final class PKPScheduler
    {
        public array $addedTasks = [];
        public function addSchedule(ScheduledTask $task): FakeScheduleEvent
        {
            $this->addedTasks[] = $task;
            return new FakeScheduleEvent();
        }
    }

    final class FakeScheduleEvent
    {
        public ?string $name = null;
        public bool $withoutOverlapping = false;
        public function daily(): static
        {
            return $this;
        }
        public function everyFiveMinutes(): static
        {
            return $this;
        }
        public function name(string $name): static
        {
            $this->name = $name;
            return $this;
        }
        public function withoutOverlapping(): static
        {
            $this->withoutOverlapping = true;
            return $this;
        }
    }
}

namespace PKP\plugins\interfaces {
    interface HasTaskScheduler
    {
        public function registerSchedules(\PKP\scheduledTask\PKPScheduler $scheduler): void;
    }
}

namespace PKP\facades {
    class Locale
    {
        public static function getLocale(): string
        {
            return 'en';
        }
    }
}

namespace APP\core {
    class Application
    {
        public static function get(): self
        {
            return new self();
        }
        public function getRequest(): object
        {
            return new \stdClass();
        }
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
        public function getId(): int
        {
            return 7;
        }
    }

    final class FakeExportRequest
    {
        public function getContext(): object
        {
            return new FakeExportContext();
        }
    }

    $plugin = new ChatwootIntegrationV2Plugin();
    $plugin->setTestSetting(7, 'chatwootBaseUrl', 'https://chat.example.test');
    $plugin->setTestSetting(7, 'chatwootWebsiteToken', 'public-widget-token');
    $plugin->setTestSetting(7, 'chatwootApiAccessToken', 'must-not-export');
    $plugin->setTestSetting(7, 'chatwootIdentityValidationSecret', 'must-not-export-either');
    $plugin->setTestSetting(7, 'enableWidget', true);
    $plugin->setTestSetting(7, 'chatwootInboxId', 4);
    $plugin->setTestSetting(7, 'chatwootSupportApiToken', 'must-not-export-support-token');

    $message = $plugin->exportSettings(new FakeExportRequest());
    pluginCheck($message->status === true, 'export should still succeed');
    pluginCheck(($message->content['contextId'] ?? null) === 7, 'export should preserve context id');

    $settings = $message->content['settings'] ?? [];
    pluginCheck(($settings['chatwootBaseUrl'] ?? '') === 'https://chat.example.test', 'non-secret base URL should export');
    pluginCheck(($settings['chatwootWebsiteToken'] ?? '') === 'public-widget-token', 'website token should preserve v1 export behavior');
    pluginCheck(($settings['enableWidget'] ?? null) === true, 'ordinary settings should export');
    pluginCheck(!array_key_exists('chatwootApiAccessToken', $settings), 'API access token must be removed');
    pluginCheck(!array_key_exists('chatwootIdentityValidationSecret', $settings), 'identity secret must be removed');
    pluginCheck(!array_key_exists('chatwootSupportApiToken', $settings), 'support API token must be removed');

    $redacted = $message->content['redactedKeys'] ?? [];
    sort($redacted);
    pluginCheck(
        $redacted === ['chatwootApiAccessToken', 'chatwootIdentityValidationSecret', 'chatwootSupportApiToken'],
        'export should identify redacted keys without exposing their values'
    );

    $method = new \ReflectionMethod($plugin, 'addChatwootWidget');
    pluginCheck($method->getDeclaringClass()->getName() === ChatwootIntegrationV2Plugin::class, 'live plugin should intercept widget rendering for v2 context capture');

    $index = file_get_contents($root . '/index.php');
    pluginCheck(str_contains((string) $index, 'new \\APP\\plugins\\generic\\chatwootIntegration\\ChatwootIntegrationPlugin()'), 'plugin wrapper should instantiate the real, conventionally-named ChatwootIntegrationPlugin (TST-014 fix), not reach for the v2 class directly');

    $usable = new \ReflectionMethod($plugin, 'supportGatewayUsable');
    pluginCheck(
        $usable->invoke($plugin, 7) === true,
        'binding ticket should be mintable once the full support channel config is present'
    );

    $incompletePlugin = new ChatwootIntegrationV2Plugin();
    $incompletePlugin->setTestSetting(9, 'enableWidget', true);
    $incompletePlugin->setTestSetting(9, 'chatwootBaseUrl', 'https://chat.example.test');
    $incompletePlugin->setTestSetting(9, 'chatwootWebsiteToken', 'public-widget-token');
    // chatwootApiAccessToken, chatwootIdentityValidationSecret and chatwootInboxId left unset.
    $usableIncomplete = new \ReflectionMethod($incompletePlugin, 'supportGatewayUsable');
    pluginCheck(
        $usableIncomplete->invoke($incompletePlugin, 9) === false,
        'binding ticket must never be minted when server-verification config is incomplete'
    );

    $disabledPlugin = new ChatwootIntegrationV2Plugin();
    $disabledPlugin->setTestSetting(11, 'enableWidget', false);
    $disabledPlugin->setTestSetting(11, 'chatwootBaseUrl', 'https://chat.example.test');
    $disabledPlugin->setTestSetting(11, 'chatwootWebsiteToken', 'public-widget-token');
    $disabledPlugin->setTestSetting(11, 'chatwootApiAccessToken', 'server-token');
    $disabledPlugin->setTestSetting(11, 'chatwootIdentityValidationSecret', 'hmac-secret');
    $disabledPlugin->setTestSetting(11, 'chatwootInboxId', 4);
    $usableDisabled = new \ReflectionMethod($disabledPlugin, 'supportGatewayUsable');
    pluginCheck(
        $usableDisabled->invoke($disabledPlugin, 11) === false,
        'binding ticket must never be minted when the widget itself is disabled'
    );

    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    $widgetMethod = substr(
        $pluginSource,
        strpos($pluginSource, 'function addChatwootWidget'),
        strpos($pluginSource, 'private function bootstrapAuthenticatedSupportSession') - strpos($pluginSource, 'function addChatwootWidget')
    );
    pluginCheck(
        preg_match('/\}\s*catch[^}]*\}\s*return parent::addChatwootWidget/s', $widgetMethod) === 1,
        'legacy v1 widget rendering must run unconditionally even if v2 support-session bootstrap throws'
    );

    // ================================================================
    // Scheduled-task wiring (IDN-017): the plugin must actually register
    // the purge task with OJS's real scheduler, not just define the class.
    // ================================================================
    pluginCheck($plugin instanceof \PKP\plugins\interfaces\HasTaskScheduler, 'plugin must implement HasTaskScheduler so PKPScheduler::registerPluginSchedules() discovers it automatically');

    $scheduler = new \PKP\scheduledTask\PKPScheduler();
    $plugin->registerSchedules($scheduler);
    pluginCheck(count($scheduler->addedTasks) === 3, 'registerSchedules() must register exactly three tasks');
    pluginCheck(
        $scheduler->addedTasks[0] instanceof \APP\plugins\generic\chatwootIntegration\classes\v2\Task\PurgeExpiredSupportDataTask,
        'the first registered task must be the real PurgeExpiredSupportDataTask, not a stand-in'
    );
    pluginCheck(
        $scheduler->addedTasks[1] instanceof \APP\plugins\generic\chatwootIntegration\classes\v2\Task\CaptainSyncScheduledTask,
        'the second registered task must be the real CaptainSyncScheduledTask, not a stand-in'
    );
    pluginCheck(
        $scheduler->addedTasks[2] instanceof \APP\plugins\generic\chatwootIntegration\classes\v2\Task\DeliverQueuedSupportEventsTask,
        'the third registered task must be the real DeliverQueuedSupportEventsTask, not a stand-in'
    );

    fwrite(STDOUT, "Live plugin tests passed\n");
}
