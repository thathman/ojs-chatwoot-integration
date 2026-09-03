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

    function har018Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-018: `skipBackendPages` was a real, saved, UI-configurable
     * setting that no runtime code ever read — a placebo. Its
     * companion `isBackendPage()` private method existed but was never
     * called either. Both are now wired: addChatwootWidget() consults
     * skipBackendPages (via getEffectiveSetting(), same as every other
     * runtime-read setting) and calls isBackendPage() with the same
     * already-safely-resolved $requestedPage string the pre-existing
     * excludedPages check uses — never $request->getRequestedPage()
     * directly, which would reintroduce the exact PKPComponentRouter
     * crash TST-020 fixed.
     */
    $plugin = new ChatwootIntegrationBasePlugin();
    $isBackendPage = new \ReflectionMethod($plugin, 'isBackendPage');

    // ================================================================
    // Part 1: real, executable behavior of the (now correctly typed)
    // isBackendPage() — a pure string-membership check.
    // ================================================================
    foreach (['management', 'admin', 'workflow', 'reviewer', 'submission', 'authorDashboard'] as $backendPage) {
        har018Check($isBackendPage->invoke($plugin, $backendPage) === true, "'{$backendPage}' must be classified as a backend page");
    }
    foreach (['', 'index', 'article', 'issue', 'search', 'about'] as $frontendPage) {
        har018Check($isBackendPage->invoke($plugin, $frontendPage) === false, "'{$frontendPage}' must never be classified as a backend page");
    }

    // ================================================================
    // Part 2: real wiring — addChatwootWidget() must actually consult
    // skipBackendPages and isBackendPage(), gated behind the same
    // $isPageRequest guard the pre-existing excludedPages check uses
    // (never a direct $request->getRequestedPage() call).
    // ================================================================
    $source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $widgetMethodStart = strpos($source, 'function addChatwootWidget(');
    har018Check($widgetMethodStart !== false, 'addChatwootWidget() must exist');
    $widgetMethodBody = substr($source, $widgetMethodStart, (int) strpos($source, "\n    }\n", $widgetMethodStart) - $widgetMethodStart);

    har018Check(str_contains($widgetMethodBody, "getEffectiveSetting(\$contextId, 'skipBackendPages'"), 'addChatwootWidget() must read the real skipBackendPages effective setting — a saved-but-unread setting is a placebo');
    har018Check(str_contains($widgetMethodBody, 'isBackendPage($requestedPage)'), 'addChatwootWidget() must call isBackendPage() with the already-safely-resolved $requestedPage string, never $request directly');

    $skipBackendPagesPos = strpos($widgetMethodBody, 'skipBackendPages');
    $isPageRequestGuardPos = strpos($widgetMethodBody, '$isPageRequest) {');
    har018Check($skipBackendPagesPos !== false && $isPageRequestGuardPos !== false && $skipBackendPagesPos > $isPageRequestGuardPos, 'the skipBackendPages check must be inside the same $isPageRequest guard the excludedPages check uses, never evaluated on a component/AJAX request');

    // isBackendPage() itself must never call $request->getRequestedPage()
    // directly (that exact call, outside this refactor, is what TST-020
    // found crashes on a non-page-routed request).
    $isBackendPageStart = strpos($source, 'function isBackendPage(');
    har018Check($isBackendPageStart !== false, 'isBackendPage() must exist');
    $isBackendPageBody = substr($source, $isBackendPageStart, (int) strpos($source, "\n    }\n", $isBackendPageStart) - $isBackendPageStart);
    har018Check(!str_contains($isBackendPageBody, '$request->getRequestedPage()'), 'isBackendPage() must never call $request->getRequestedPage() directly — TST-020 already proved this crashes outside a real PKPPageRouter request');
    har018Check(str_contains($isBackendPageBody, 'string $requestedPage'), 'isBackendPage() must take the already-resolved page string as its parameter, not $request');

    fwrite(STDOUT, "HAR-018 skipBackendPages wiring tests passed\n");
}
