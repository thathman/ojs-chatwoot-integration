<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function fbvIdSuffixCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Real-browser-discovered bug (2026-09-04, item H live acceptance):
 * PKP's real `_smartyFBVTextInput()`/`_smartyFBVTextArea()`
 * (`lib/pkp/classes/form/FormBuilderVocabulary.php`, verified against
 * the real deployed source on dell) unconditionally call
 * `$params['uniqId'] = uniqid();` and the real `textInput.tpl`/
 * `textarea.tpl` render `id="{$FBV_id}{$uniqId}"` — a real, random,
 * per-request suffix appended to the DOM id of EVERY `{fbvElement
 * type="text"}`, `password=true`, and `type="textarea"` field. The
 * field's `name` attribute is never suffixed. Any `$('#exactId')`
 * jQuery selector targeting one of these fields therefore matches
 * nothing in the real rendered page — confirmed live: after Generate/
 * Rotate on the API & MCP tab, the MCP Service Token field visibly
 * stayed blank instead of showing the freshly generated value, even
 * though the value was already correctly persisted server-side. Left
 * unfixed, this is a real risk: if an admin then clicked Save without
 * noticing, the field's stale value would silently overwrite the
 * just-generated credential.
 *
 * `{fbvElement type="select"}` and `type="checkbox"}` are NOT affected
 * — their own smarty handlers never inject a uniqId, confirmed against
 * the same real source (`_smartyFBVSelect()`/`_smartyFBVCheckbox()`).
 * Hand-written raw `<input>`/`<select>` elements in this template
 * (never routed through fbvElement) are also unaffected — they render
 * exactly the id given.
 *
 * This test is the drift guard: every JS reference to one of this
 * template's own type="text"/type="textarea"/password=true fbvElement
 * fields must select by `[name="..."]`, never `#id`.
 */
$tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");

// Real text/textarea/password fbvElement field ids declared in this
// template — every one of these gets a real random id suffix in the
// live DOM.
preg_match_all('/\{fbvElement\s+type="(?:text|textarea)"[^}]*\bid="([a-zA-Z0-9_]+)"/', $tpl, $matches);
$suffixedFieldIds = array_unique($matches[1]);
fbvIdSuffixCheck(count($suffixedFieldIds) > 5, 'sanity check: expected multiple real text/textarea fbvElement fields in this template');

foreach ($suffixedFieldIds as $fieldId) {
    fbvIdSuffixCheck(
        !preg_match('/\$\(\'#' . preg_quote($fieldId, '/') . '\'\)/', $tpl) && !preg_match('/[,\s]#' . preg_quote($fieldId, '/') . '(?=[,\s\'"])/', $tpl),
        "'{$fieldId}' is a real text/textarea/password fbvElement field — PKP appends a random id suffix to it in the live DOM, so no JS in this template may select it by '#{$fieldId}'; use '[name=\"{$fieldId}\"]' instead"
    );
}

// The specific real fix: Generate/Rotate on the API & MCP tab must
// target both service-credential fields by name, not id.
fbvIdSuffixCheck((bool) preg_match('/\$\(\'\[name="\'\s*\+\s*fieldName\s*\+\s*\'"\]\'\)\.val\(value\)/', $tpl), 'cwWireCredentialGenerate() must populate the credential field by [name=...], never by #id, after a real Generate/Rotate');

fwrite(STDOUT, "FBV text-field id-suffix regression tests passed\n");
