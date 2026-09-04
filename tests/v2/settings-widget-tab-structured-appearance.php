<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function cwWidgetTabCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Settings Console Widget tab (owner directive 2026-09-04): raw
 * widgetSettingsJson editing is no longer part of ordinary setup —
 * replaced with structured controls for every real
 * window.chatwootSettings key the deployed Chatwoot SDK actually
 * reads (verified against the real bundle at
 * support.airixmedia.com/packs/js/sdk.js during this fix: position
 * left/right, type standard/expanded_bubble, darkMode light/dark/
 * auto, useBrowserLanguage, showPopoutButton,
 * showUnreadMessagesDialog, hideMessageBubble all confirmed present
 * in that real minified bundle — never invented).
 */
$source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
$widgetMethodStart = strpos($source, 'function addChatwootWidget(');
cwWidgetTabCheck($widgetMethodStart !== false, 'addChatwootWidget() must exist');
$widgetMethodEnd = strpos($source, "\n    public function addChatwootWidgetFromFooterHook", $widgetMethodStart);
$widgetMethodBody = substr($source, $widgetMethodStart, $widgetMethodEnd - $widgetMethodStart);

cwWidgetTabCheck(!str_contains($widgetMethodBody, "position:'right', type:'standard', launcherTitle:'Chat with us'"), 'the old hardcoded appearance defaults must be gone — replaced by the structured settings');
foreach (['widgetPosition', 'widgetLauncherStyle', 'widgetLauncherTitle', 'widgetLanguageMode', 'widgetFixedLocale', 'widgetTheme', 'widgetShowPopoutButton', 'widgetShowUnreadDialog', 'widgetHideMessageBubble'] as $key) {
    cwWidgetTabCheck(str_contains($widgetMethodBody, "'{$key}'"), "addChatwootWidget() must read the real {$key} setting");
}

// The Advanced JSON override must still merge on top of the structured
// defaults, in the same relative position as before this fix — a real
// escape hatch, never removed, never given priority over the runtime
// window.chatwootSettings a page's own script might set.
cwWidgetTabCheck(
    (bool) preg_match('/Object\.assign\(\s*\$widgetDefaultsJson,\s*\$widgetSettingsFromConfigJson,\s*window\.chatwootSettings \|\| \{\}\s*\)/', $widgetMethodBody),
    'the structured defaults must merge first, the Advanced override JSON second, and any already-set window.chatwootSettings last — same layering as before this fix'
);

// SettingsRegistry: the new keys exist on the widget tab, and
// widgetSettingsJson moved to advanced (owner directive item 13).
foreach (['widgetPosition', 'widgetLauncherStyle', 'widgetLauncherTitle', 'widgetLanguageMode', 'widgetFixedLocale', 'widgetTheme', 'widgetShowPopoutButton', 'widgetShowUnreadDialog', 'widgetHideMessageBubble'] as $key) {
    $def = SettingsRegistry::get($key);
    cwWidgetTabCheck($def !== null, "{$key} must be a real registered setting");
    cwWidgetTabCheck($def->tab === 'widget', "{$key} must live on the Widget tab");
}
$jsonDef = SettingsRegistry::get('widgetSettingsJson');
cwWidgetTabCheck($jsonDef !== null && $jsonDef->tab === 'advanced', 'widgetSettingsJson must have moved to the Advanced tab — raw JSON is no longer part of ordinary setup');

// Template wiring: the new fields must actually be in the widget
// panel (not the advanced one), and widgetSettingsJson must now be in
// the advanced panel — settings-form-tabs.php's own drift guard
// already enforces "every SettingsRegistry key in its correct tab
// panel," this just adds the specific, named assertion for this slice.
$templateSource = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
$widgetPanelStart = strpos($templateSource, 'id="cwPanel-widget"');
$widgetPanelEnd = strpos($templateSource, 'id="cwPanel-automation"');
$widgetPanel = substr($templateSource, $widgetPanelStart, $widgetPanelEnd - $widgetPanelStart);
cwWidgetTabCheck(str_contains($widgetPanel, 'id="widgetPosition"'), 'the Widget panel must contain the real widgetPosition control');
cwWidgetTabCheck(!str_contains($widgetPanel, 'id="widgetSettingsJson"'), 'widgetSettingsJson must no longer render in the Widget panel');

$advancedPanelStart = strpos($templateSource, 'id="cwPanel-advanced"');
$advancedPanel = substr($templateSource, $advancedPanelStart);
cwWidgetTabCheck(str_contains($advancedPanel, 'id="widgetSettingsJson"'), 'widgetSettingsJson must render in the Advanced panel as a documented override');

// The local preview must never boot the real Chatwoot SDK/iframe —
// its own JS block (cwUpdateWidgetPreview) must contain no reference
// to the real SDK boot call.
cwWidgetTabCheck(str_contains($templateSource, 'cwWidgetPreviewStage'), 'the Widget tab must include a local preview stage element');
$previewFnStart = strpos($templateSource, 'function cwUpdateWidgetPreview()');
cwWidgetTabCheck($previewFnStart !== false, 'cwUpdateWidgetPreview() must exist');
$previewFnBody = substr($templateSource, $previewFnStart, (int) strpos($templateSource, '{rdelim}', $previewFnStart) - $previewFnStart);
cwWidgetTabCheck(!str_contains($previewFnBody, 'chatwootSDK') && !str_contains($previewFnBody, 'sdk.js'), 'the local preview function must never reference the real Chatwoot SDK — it is a pure CSS/HTML approximation');

fwrite(STDOUT, "Widget tab structured appearance tests passed\n");
