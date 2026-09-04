<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\IntegrationCatalog;

function integrationsCatalogCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Settings Console item I (Integrations tab, owner directive
 * 2026-09-04): "real installed sibling providers only ... never
 * duplicate their config." IntegrationCatalog::resolve() needs a real
 * OJS runtime (PluginRegistry) unavailable in this local harness — see
 * settings-form-mcp-secret-masking.php's own note on the same
 * constraint for ChatwootApiService — so this is source-level
 * verification: the catalog's fixed entries were confirmed against
 * the real dell filesystem/plugin classes during this session (see
 * the class's own docblock), and this test guards that the categories
 * used are real and the wiring reaches the template correctly. Live
 * behavioral confirmation (installed/enabled/version actually
 * resolving correctly) is a CLI-harness/browser check, not this file.
 */
$catalogSource = (string) file_get_contents("{$root}/classes/v2/Provider/IntegrationCatalog.php");

// Every real provider name the owner explicitly listed must be present
// (label text, case-insensitive substring match — the exact PHP array
// key format is checked separately below).
foreach (['Submission Fee', 'Request Waiver', 'Paystack', 'Flutterwave', 'Bachs', 'MultiPay', 'Required Submission Files', 'Contributor User Sync', 'Magic Login', 'Visibility Suite'] as $expectedLabel) {
    integrationsCatalogCheck(str_contains($catalogSource, "'{$expectedLabel}'"), "IntegrationCatalog must list the real, verified '{$expectedLabel}' sibling plugin");
}

// Only two real plugin categories are ever queried — 'generic' and
// 'paymethod' (the real category the 4 payment-gateway plugins live
// under, confirmed against the real dell filesystem) — never a
// speculative third category.
preg_match_all("/'category'\s*=>\s*'(\w+)'/", $catalogSource, $categoryMatches);
$usedCategories = array_unique($categoryMatches[1]);
sort($usedCategories);
integrationsCatalogCheck($usedCategories === ['generic', 'paymethod'], 'IntegrationCatalog must only ever query the two real plugin categories its entries actually live under');

// multipay's real plugin registry name has no "plugin" suffix
// (confirmed live via PluginRegistry::loadCategory('paymethod', ...)
// on dell — every other entry follows the ordinary convention).
integrationsCatalogCheck((bool) preg_match("/'name'\s*=>\s*'multipay'/", $catalogSource), "multipay's real PluginRegistry name is 'multipay', not 'multipayplugin' — must not follow the generic naming assumption for this one real exception");

// The resolver must never duplicate a sibling's own config/credentials
// — it may only report installed/enabled/version facts, never read or
// expose that plugin's own settings.
integrationsCatalogCheck(!str_contains($catalogSource, 'getSetting('), 'IntegrationCatalog must never read a sibling plugin\'s own settings — status + link-out only, per the owner\'s explicit "never duplicate their config" requirement');
integrationsCatalogCheck(str_contains($catalogSource, 'getEnabled($contextId)'), 'enabled state must be resolved per-context, not a site-wide guess');

// ================================================================
// Template/form wiring.
// ================================================================
$tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
$formSource = (string) file_get_contents("{$root}/ChatwootSettingsForm.php");
integrationsCatalogCheck(str_contains($formSource, 'IntegrationCatalog::resolve('), 'ChatwootSettingsForm must build the Integrations tab data through the real, single-source-of-truth catalog');
integrationsCatalogCheck(str_contains($tpl, 'id="cwPanel-integrations"'), 'a real Integrations tab panel must exist');
integrationsCatalogCheck(str_contains($tpl, '{foreach from=$integrationEntries'), 'the Integrations tab must render its rows from a real $integrationEntries loop, never a hand-typed table');
integrationsCatalogCheck(str_contains($tpl, 'pluginsPageUrl'), 'the Integrations tab must link out to the real journal Plugins page rather than trying to embed each sibling\'s own settings inline');

fwrite(STDOUT, "Integrations catalog tests passed\n");
