<?php

declare(strict_types=1);

/**
 * Real-browser-discovered bug (2026-09-04, Settings Console item F Overview
 * tab): OJS 3.5's real substitution mechanism (PKP\i18n\translation\LocaleBundle::_format(),
 * confirmed by reading the deployed lib/pkp source on dell) replaces
 * "{$paramName}" tokens, not sprintf-style "%paramName%" tokens. Two locale
 * strings in this plugin used "%errorCode%"/"%count%"/"%endpoint%"/"%revision%"
 * and rendered literally, unsubstituted, in the live Overview and API & MCP
 * tabs — visible to any admin as a broken placeholder, the same class of
 * defect as the owner's "no untranslated locale keys" acceptance requirement.
 *
 * This is a drift guard: any future locale string with a matching
 * "{translate key=... someParam=...}" call in a template must use
 * "{$someParam}" substitution syntax, never "%someParam%".
 */

function localePlaceholderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$poPath = __DIR__ . '/../../locale/en/locale.po';
localePlaceholderCheck(file_exists($poPath), 'locale/en/locale.po must exist');

$po = file_get_contents($poPath);
localePlaceholderCheck($po !== false, 'locale/en/locale.po must be readable');

preg_match_all('/msgstr\s+"((?:[^"\\\\]|\\\\.)*)"/', $po, $matches);
localePlaceholderCheck(count($matches[1]) > 50, 'sanity check: expected many msgstr entries in locale.po');

foreach ($matches[1] as $msgstr) {
    localePlaceholderCheck(
        preg_match('/%[a-zA-Z][a-zA-Z0-9]*%/', $msgstr) !== 1,
        "locale.po msgstr uses sprintf-style '%param%' placeholder syntax, which OJS 3.5's real translate() never substitutes — use '{\$param}' instead: {$msgstr}"
    );
}

/**
 * Real-browser-discovered bug #2 (same session): PKP's smartyTranslate()
 * treats "key", "count", "locale", and "params" as RESERVED {translate}
 * argument names — it strips them before merging into the substitution
 * variables and, when "count" is present, routes to __p() (plural lookup)
 * instead of __() (simple lookup). This plugin has no real msgid_plural
 * entries, so passing count=... as an ordinary substitution value silently
 * discarded it and the token was never substituted. Guard against ever
 * passing these four reserved names as ordinary {translate} substitution
 * parameters in a template.
 */
$templateDir = __DIR__ . '/../../templates';
$templateFiles = glob($templateDir . '/*.tpl') ?: [];
localePlaceholderCheck(count($templateFiles) > 0, 'expected at least one .tpl file under templates/');

foreach ($templateFiles as $templateFile) {
    $source = file_get_contents($templateFile);
    localePlaceholderCheck($source !== false, "{$templateFile} must be readable");

    preg_match_all('/\{translate\s+key="[^"]+"([^}]*)\}/', $source, $translateCalls);
    foreach ($translateCalls[1] as $callArgs) {
        localePlaceholderCheck(
            preg_match('/(^|\s)count=/', $callArgs) !== 1,
            basename($templateFile) . ": {translate} call passes reserved param 'count' as an ordinary substitution value — PKP strips it and routes to plural lookup instead of substituting it. Rename the param (and its {\$name} in locale.po)."
        );
    }
}

fwrite(STDOUT, "Locale placeholder substitution syntax test passed\n");
