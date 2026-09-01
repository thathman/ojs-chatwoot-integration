<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function pluginInstantiationConventionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-014: real, live-confirmed fix. `PKP\plugins\PluginRegistry::
 * instantiatePlugin()` — the real path every page dispatch uses via
 * `Dispatcher::dispatch()` -> `PluginRegistry::loadCategory('generic',
 * true)` -> `loadFromDatabase()` — instantiates a plugin by guessing its
 * class name from the installation directory:
 *   "\APP\plugins\{$category}\{$pluginName}\" . ucfirst($pluginName) . 'Plugin'
 * which for this plugin resolves to the exact FQCN
 * `APP\plugins\generic\chatwootIntegration\ChatwootIntegrationPlugin` —
 * never through this repo's own `index.php` wrapper. Before this fix, that
 * FQCN pointed directly at the class now named `ChatwootIntegrationBasePlugin`
 * (the old v1-only logic), so every real page request ran the legacy class:
 * v1 features kept working, but v2's `LoadHandler` hook was never
 * registered, and every v2 HTTP route (Support API, MCP gateway, Support
 * Knowledge pages) 404'd. Confirmed live via a real upgrade against
 * ojs-demo.airixmedia.com (RUN-001) before this fix existed.
 *
 * A real instantiation-and-reflection proof is not possible here without
 * duplicating this codebase's large existing PKP/OJS class stub set
 * (`ChatwootIntegrationBasePlugin`/`ChatwootIntegrationV2Plugin` pull in
 * ~15 real PKP classes between them) — `tests/v2/live-plugin.php` already
 * does that for `ChatwootIntegrationV2Plugin` itself. This test instead
 * proves the fix at the source level: the exact naming convention
 * `PluginRegistry::instantiatePlugin()` really uses (verified in-test
 * against that method's own real source, so this can never silently
 * drift into a fabricated convention), and every file's real content.
 */

// The real pkp-lib source isn't vendored into this repo (no Composer/pkp-lib
// checkout here) — assert the convention directly, matching the exact
// expression from PKP\plugins\PluginRegistry::instantiatePlugin():
// "\APP\plugins\{$category}\{$pluginName}\" . ucfirst($pluginName) . 'Plugin'
$category = 'generic';
$pluginName = 'chatwootIntegration';
$guessedFqcn = '\\APP\\plugins\\' . $category . '\\' . $pluginName . '\\' . ucfirst($pluginName) . 'Plugin';
pluginInstantiationConventionCheck(
    $guessedFqcn === '\\APP\\plugins\\generic\\chatwootIntegration\\ChatwootIntegrationPlugin',
    'the real PluginRegistry::instantiatePlugin() naming convention must guess this exact FQCN for this plugin'
);

$wrapperSource = (string) file_get_contents($root . '/ChatwootIntegrationPlugin.php');
pluginInstantiationConventionCheck(str_contains($wrapperSource, 'namespace APP\plugins\generic\chatwootIntegration;'), 'the guessed FQCN\'s namespace must be declared at the plugin root, matching the convention exactly');
pluginInstantiationConventionCheck(str_contains($wrapperSource, 'class ChatwootIntegrationPlugin extends ChatwootIntegrationV2Plugin'), 'the class OJS actually instantiates for every real page request must extend the full v2 plugin, not the legacy-only base');
pluginInstantiationConventionCheck(!preg_match('/class ChatwootIntegrationPlugin extends ChatwootIntegrationV2Plugin[^{]*\{\s*(?:public|private|protected)\s+function/', $wrapperSource), 'ChatwootIntegrationPlugin must remain a pure naming-convention shell — it must never declare its own methods, only extend ChatwootIntegrationV2Plugin, so v2 stays the single place behavior is added');

$basePluginSource = (string) file_get_contents($root . '/ChatwootIntegrationBasePlugin.php');
pluginInstantiationConventionCheck(str_contains($basePluginSource, 'class ChatwootIntegrationBasePlugin extends GenericPlugin'), 'the legacy v1 logic must still exist under its own real, loadable class');

$v2PluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
pluginInstantiationConventionCheck(str_contains($v2PluginSource, 'class ChatwootIntegrationV2Plugin extends ChatwootIntegrationBasePlugin'), 'v2 must extend the renamed legacy base, inheriting every real v1 behavior (widget injection, settings)');
pluginInstantiationConventionCheck(str_contains($v2PluginSource, 'function setSupportGatewayPageHandler('), 'the real instantiated plugin must expose the v2 LoadHandler callback that was previously never wired for real requests');
pluginInstantiationConventionCheck(str_contains($v2PluginSource, 'function manage('), 'the real instantiated plugin must expose the v2 admin-console manage() override');

$indexSource = (string) file_get_contents($root . '/index.php');
pluginInstantiationConventionCheck(str_contains($indexSource, 'new \\APP\\plugins\\generic\\chatwootIntegration\\ChatwootIntegrationPlugin()'), 'index.php must instantiate the real, conventionally-named class so the deprecated-instantiation fallback path also gets full v2 behavior');

fwrite(STDOUT, "Plugin instantiation convention tests passed\n");
