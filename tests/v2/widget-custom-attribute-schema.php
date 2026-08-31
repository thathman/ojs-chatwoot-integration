<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\WidgetCustomAttributeSchema;

function widgetCustomAttributeSchemaCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// CWO-003: WidgetCustomAttributeSchema must never drift from the real
// $attrs[...] assignments in the legacy widget source — checked
// bidirectionally, using the exact same extraction CWO-004's test already
// verifies is matching the real code (tests/v2/custom-attribute-authorization-independence.php).
// ================================================================
// Scoped to addChatwootWidget()'s own method body only — the legacy file
// has a second, unrelated local `$attrs` variable inside
// buildSubmissionAttributes() (the v1 event-note payload, a completely
// different surface with its own submission_id/workflow_stage/etc. keys
// that must not be conflated with the widget's client-side attributes).
$legacyPluginSource = (string) file_get_contents($root . '/ChatwootIntegrationPlugin.php');
$widgetMethodStart = strpos($legacyPluginSource, 'function addChatwootWidget(');
widgetCustomAttributeSchemaCheck($widgetMethodStart !== false, 'must be able to locate addChatwootWidget() in the legacy source');
$widgetMethodEnd = strpos($legacyPluginSource, 'function addChatwootWidgetFromFooterHook', $widgetMethodStart);
$widgetMethodBody = $widgetMethodEnd !== false
    ? substr($legacyPluginSource, $widgetMethodStart, $widgetMethodEnd - $widgetMethodStart)
    : substr($legacyPluginSource, $widgetMethodStart);

$realCustomAttributeKeys = [];
if (preg_match_all("/\\\$attrs\\['([a-z_]+)'\\]/", $widgetMethodBody, $bracketMatches)) {
    $realCustomAttributeKeys = array_merge($realCustomAttributeKeys, $bracketMatches[1]);
}
if (preg_match_all('/\\$attrs\\s*=\\s*\\[([^\\]]+)\\]/', $widgetMethodBody, $literalBlocks)) {
    foreach ($literalBlocks[1] as $block) {
        if (preg_match_all("/'([a-z_]+)'\\s*=>/", $block, $literalKeys)) {
            $realCustomAttributeKeys = array_merge($realCustomAttributeKeys, $literalKeys[1]);
        }
    }
}
$realCustomAttributeKeys = array_values(array_unique($realCustomAttributeKeys));

widgetCustomAttributeSchemaCheck(
    count($realCustomAttributeKeys) >= 10,
    'sanity: must find a real, substantial set of custom-attribute keys in the legacy widget source — if this count drops, the regex or the source moved and this test is no longer checking the real list'
);

// Every real key must be classified.
$unclassified = array_values(array_filter($realCustomAttributeKeys, fn (string $key): bool => !WidgetCustomAttributeSchema::isKnown($key)));
widgetCustomAttributeSchemaCheck(
    $unclassified === [],
    'every real $attrs key the legacy widget actually sends must be classified in WidgetCustomAttributeSchema — unclassified: ' . implode(', ', $unclassified)
);

// No schema entry may be stale (declared but never actually sent).
$stale = array_values(array_diff(WidgetCustomAttributeSchema::knownKeys(), $realCustomAttributeKeys));
widgetCustomAttributeSchemaCheck(
    $stale === [],
    'WidgetCustomAttributeSchema must never declare a key the legacy widget does not actually send — stale: ' . implode(', ', $stale)
);

// Every classification must be one of the two real sensitivity levels and
// carry a type — never an empty/placeholder entry.
foreach (WidgetCustomAttributeSchema::knownKeys() as $key) {
    $classification = WidgetCustomAttributeSchema::classification($key);
    widgetCustomAttributeSchemaCheck(is_array($classification), "classification({$key}) must return an array for every known key");
    widgetCustomAttributeSchemaCheck(
        in_array($classification['sensitivity'] ?? null, ['public', 'user_derived'], true),
        "key '{$key}' must have a real sensitivity classification (public|user_derived)"
    );
    widgetCustomAttributeSchemaCheck(($classification['type'] ?? '') !== '', "key '{$key}' must declare a non-empty type");
}

widgetCustomAttributeSchemaCheck(WidgetCustomAttributeSchema::classification('not_a_real_key') === null, 'an unrecognized key must return null, never a fabricated classification');

// Identity must never be classified here — it travels only through
// setUser()'s own dedicated parameters, never a custom attribute.
foreach (WidgetCustomAttributeSchema::FORBIDDEN_KEYS as $forbiddenKey) {
    widgetCustomAttributeSchemaCheck(!WidgetCustomAttributeSchema::isKnown($forbiddenKey), "'{$forbiddenKey}' is identity-shaped and must never be a known/allowed custom-attribute key");
    widgetCustomAttributeSchemaCheck(!in_array($forbiddenKey, $realCustomAttributeKeys, true), "sanity: '{$forbiddenKey}' must not actually appear as a real \$attrs key in the legacy widget source either");
}

fwrite(STDOUT, "Widget custom attribute schema tests passed\n");
