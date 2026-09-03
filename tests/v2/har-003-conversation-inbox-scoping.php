<?php

declare(strict_types=1);

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

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;

    function har003Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-003: sendChatwootEvent() (and the v2 equivalent event-delivery
     * path) called getContactConversations() and then blindly used
     * conversations[0] — a Chatwoot contact can have conversations in
     * unrelated inboxes/channels (WhatsApp, email, another website), so
     * the first entry returned is not necessarily one the configured OJS
     * inbox owns. An OJS event/private note/customer message could land
     * in a completely unrelated inbox. This proves the new shared
     * selectConversationForInbox() actually enforces inbox_id membership
     * against real conversation-shaped arrays, and both real call sites
     * use it instead of conversations[0].
     */
    $plugin = new ChatwootIntegrationBasePlugin();
    $select = new \ReflectionMethod($plugin, 'selectConversationForInbox');

    $conversations = [
        ['id' => 101, 'inbox_id' => 7],  // unrelated WhatsApp inbox
        ['id' => 102, 'inbox_id' => 9],  // unrelated email inbox
        ['id' => 103, 'inbox_id' => 15], // the configured OJS website inbox
    ];

    // ================================================================
    // Part 1: real, executable behavior — must find the conversation
    // matching the configured inbox even when it is not first, and must
    // never return an unrelated conversation.
    // ================================================================
    $match = $select->invoke($plugin, $conversations, 15);
    har003Check($match !== null && (int) $match['id'] === 103, 'must select the conversation belonging to the configured inbox, not conversations[0]');

    $noMatch = $select->invoke($plugin, $conversations, 42);
    har003Check($noMatch === null, 'must return null (fail closed) when no conversation belongs to the configured inbox — never fall back to an unrelated one');

    $unconfigured = $select->invoke($plugin, $conversations, 0);
    har003Check($unconfigured === null, 'an unconfigured inbox (<= 0) has nothing to prove membership against — must fail closed, never guess conversations[0]');

    $empty = $select->invoke($plugin, [], 15);
    har003Check($empty === null, 'no conversations at all must also fail closed');

    // ================================================================
    // Part 2: real wiring — both real call sites must use the shared
    // selector instead of the old conversations[0] shortcut.
    // ================================================================
    $baseSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

    har003Check(str_contains($baseSource, 'protected function selectConversationForInbox('), 'ChatwootIntegrationBasePlugin must declare the shared selectConversationForInbox()');
    har003Check(!str_contains($baseSource, 'conversations[0]'), 'ChatwootIntegrationBasePlugin must no longer reference conversations[0] anywhere — that was the actual HAR-003 bug');
    har003Check(!str_contains($v2Source, 'conversations[0]'), 'ChatwootIntegrationV2Plugin must no longer reference conversations[0] anywhere — that was the actual HAR-003 bug');

    $sendEventStart = strpos($baseSource, 'function sendChatwootEvent(');
    har003Check($sendEventStart !== false, 'sendChatwootEvent() must exist');
    $sendEventBody = substr($baseSource, $sendEventStart, (int) strpos($baseSource, "\n    }\n", $sendEventStart) - $sendEventStart);
    har003Check(str_contains($sendEventBody, '$this->selectConversationForInbox('), 'sendChatwootEvent() must call the shared selectConversationForInbox(), not trust conversations[0]');

    fwrite(STDOUT, "HAR-003 conversation inbox scoping tests passed\n");
}
