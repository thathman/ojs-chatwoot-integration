<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har023Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-023: TemplateManager::fetch fires once per template/partial
 * rendered within a single real page load (many times), and
 * TemplateManager::display fires once more — addChatwootWidget() is
 * hooked to both, plus a footer hook that routes through the same
 * method. Live-confirmed on the real demo site
 * (https://ojs-demo.airixmedia.com/ajdsi) before this fix: the
 * rendered page contained the widget's `chatwoot:ready` listener and
 * `__chatwootLoaded` boot function TWICE — two separate `<script>`
 * blocks injected into one page. `window.__chatwootLoaded` correctly
 * dedupes the actual SDK boot (`chatwootSDK.run` appeared only once),
 * but each injected copy's own `chatwoot:ready` listener still fires
 * independently once the SDK becomes ready, calling
 * setUser()/setCustomAttributes() once per copy instead of once per
 * page — real, observed multiplicity, not a hypothetical.
 */
$source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");

har023Check(str_contains($source, 'private bool $widgetInjectedThisRequest = false;'), 'ChatwootIntegrationBasePlugin must track whether the widget has already been injected once in this request');

$widgetMethodStart = strpos($source, 'function addChatwootWidget(');
har023Check($widgetMethodStart !== false, 'addChatwootWidget() must exist');
$widgetMethodEnd = strpos($source, "\n    public function addChatwootWidgetFromFooterHook", $widgetMethodStart);
har023Check($widgetMethodEnd !== false, 'must be able to bound addChatwootWidget() by the next method');
$widgetMethodBody = substr($source, $widgetMethodStart, $widgetMethodEnd - $widgetMethodStart);

$guardCheckPos = strpos($widgetMethodBody, 'if ($this->widgetInjectedThisRequest) return false;');
har023Check($guardCheckPos !== false, 'addChatwootWidget() must bail out early once the widget has already been injected this request');

$guardSetPos = strpos($widgetMethodBody, '$this->widgetInjectedThisRequest = true;');
har023Check($guardSetPos !== false, 'addChatwootWidget() must actually set the guard once it commits to injecting');
har023Check($guardCheckPos < $guardSetPos, 'the guard must be checked before it is set, obviously, but also confirms the check happens on every call while the set happens only on the one call that actually injects');

$addHeaderPos = strpos($widgetMethodBody, "addHeader('chatwootWidgetFrontend'");
har023Check($addHeaderPos !== false, 'addChatwootWidget() must still call addHeader() for the real injection path');
har023Check($guardSetPos < $addHeaderPos, 'the guard must be set immediately before the real injection (addHeader/output append), never after — a call that bails out on a later condition must never have marked injection as already done');

// The footer hook must route through the same guarded method, not
// duplicate its own independent injection logic.
$footerHookStart = strpos($source, 'function addChatwootWidgetFromFooterHook(');
har023Check($footerHookStart !== false, 'addChatwootWidgetFromFooterHook() must exist');
$footerHookBody = substr($source, $footerHookStart, (int) strpos($source, "\n    }\n", $footerHookStart) - $footerHookStart);
har023Check(str_contains($footerHookBody, '$this->addChatwootWidget('), 'the footer hook must delegate to the same guarded addChatwootWidget(), never inject independently');

fwrite(STDOUT, "HAR-023 widget-single-injection-per-request tests passed\n");
