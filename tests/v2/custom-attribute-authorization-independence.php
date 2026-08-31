<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function customAttributeIndependenceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// CWO-004: verifies, against the real source tree, that v2's
// Identity->Relationship->Capability->Serializer chain never reads a
// Chatwoot client-side custom attribute back as an authorization input.
//
// The custom attributes below are exactly what v1's
// ChatwootIntegrationPlugin::addChatwootWidget() sends TO Chatwoot
// (outgoing context only) — verified by grepping ChatwootIntegrationPlugin.php
// itself for every `$attrs[...]` assignment. v2 never has a reason to read
// any of these back: every real v2 Support API endpoint instead reads
// only the server-verified conversation tuple
// (chatwootAccountId/chatwootContactId/chatwootConversationId) plus its
// own endpoint-specific params (submissionId, scope, email, pin, ...) —
// see SupportApiRequestResolver/SupportSessionService, whose whole design
// is "never trust a client-claimed identifier, only a verified
// server-side binding match."
// ================================================================

$legacyPluginSource = (string) file_get_contents($root . '/ChatwootIntegrationPlugin.php');
$realCustomAttributeKeys = [];
// `$attrs['key'] = ...` bracket-assignment style.
if (preg_match_all("/\\\$attrs\\['([a-z_]+)'\\]/", $legacyPluginSource, $bracketMatches)) {
    $realCustomAttributeKeys = array_merge($realCustomAttributeKeys, $bracketMatches[1]);
}
// `'key' => $value` array-literal style, restricted to lines building the
// $attrs array (the initial `$attrs = [...]` literal).
if (preg_match_all('/\\$attrs\\s*=\\s*\\[([^\\]]+)\\]/', $legacyPluginSource, $literalBlocks)) {
    foreach ($literalBlocks[1] as $block) {
        if (preg_match_all("/'([a-z_]+)'\\s*=>/", $block, $literalKeys)) {
            $realCustomAttributeKeys = array_merge($realCustomAttributeKeys, $literalKeys[1]);
        }
    }
}
$realCustomAttributeKeys = array_values(array_unique($realCustomAttributeKeys));

customAttributeIndependenceCheck(
    count($realCustomAttributeKeys) >= 10,
    'sanity: must find a real, substantial set of custom-attribute keys in the legacy widget source — if this count drops, the regex or the source moved and this test is no longer checking the real list'
);
foreach (['journal_id', 'roles', 'orcid', 'affiliation', 'active_submissions', 'is_masked'] as $expectedKey) {
    customAttributeIndependenceCheck(in_array($expectedKey, $realCustomAttributeKeys, true), "the real custom-attribute key '{$expectedKey}' must be present in the extracted list — confirms the regex is matching the real assignments, not silently matching nothing");
}

// Every v2 source file's real getUserVar()/getUserVars() calls: this is
// what v2 actually treats as caller-supplied input.
$v2Files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/classes/v2', FilesystemIterator::SKIP_DOTS));
$readParamNames = [];
foreach ($v2Files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match_all("/getUserVar\\('([a-zA-Z_]+)'\\)/", $source, $paramMatches)) {
        foreach ($paramMatches[1] as $name) {
            $readParamNames[$name] = true;
        }
    }
}
$readParamNames = array_keys($readParamNames);

customAttributeIndependenceCheck(count($readParamNames) > 0, 'sanity: v2 must read at least some real request params, or this check is vacuous');

$overlap = array_intersect($realCustomAttributeKeys, $readParamNames);
customAttributeIndependenceCheck(
    $overlap === [],
    'v2 must never read a Chatwoot custom-attribute key name back as a request parameter — found: ' . implode(', ', $overlap)
);

fwrite(STDOUT, "Custom attribute authorization independence tests passed\n");
