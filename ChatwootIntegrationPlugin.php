<?php

namespace APP\plugins\generic\chatwootIntegration;

require_once __DIR__ . '/ChatwootIntegrationBasePlugin.php';
require_once __DIR__ . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

/**
 * TST-014: real, live-verified fix for a confirmed OJS core behavior —
 * `PKP\plugins\PluginRegistry::instantiatePlugin()`'s enabled-plugin load
 * path (used for every real page dispatch, via
 * `Dispatcher::dispatch()` -> `PluginRegistry::loadCategory('generic', true)`
 * -> `loadFromDatabase()`) instantiates a plugin by GUESSING its class name
 * from the installation directory —
 * `APP\plugins\{category}\{dir}\` . ucfirst($dir) . 'Plugin' — never via
 * this repo's own `index.php` wrapper. Before this fix, that guess resolved
 * to `ChatwootIntegrationBasePlugin`'s old name at this exact FQCN+file
 * path, so every real page request silently ran the legacy v1-only class:
 * v1 features (widget injection, settings) kept working, but v2's
 * `LoadHandler` hook (Support API, MCP gateway, Support Knowledge pages)
 * was never registered at all — confirmed live via a real upgrade against
 * ojs-demo.airixmedia.com (RUN-001): those routes 404'd while an unrelated
 * plugin's own custom page on the same journal worked fine.
 *
 * This file exists solely so that guessed classname resolves to the real,
 * fully-featured plugin. It must never contain logic of its own — add new
 * behavior to `ChatwootIntegrationV2Plugin` (or, for logic every version
 * shares, `ChatwootIntegrationBasePlugin`), never here.
 */
class ChatwootIntegrationPlugin extends ChatwootIntegrationV2Plugin
{
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\chatwootIntegration\\ChatwootIntegrationPlugin', '\\ChatwootIntegrationPlugin');
}
