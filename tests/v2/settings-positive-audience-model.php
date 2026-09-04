<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function positiveAudienceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Owner directive 2026-09-04, item D: real-browser evidence showed the
 * Widget tab still presenting negative "Hide for X" checkboxes and an
 * optional "Enable Privacy Mode (Blind Review Protection)" checkbox.
 * ChatwootSettingsForm/settingsForm.tpl can't be exercised directly here
 * (PKP\form\Form and Smarty both need a live OJS runtime — see
 * settings-form-mcp-secret-masking.php's own note), so this is
 * source-level wiring evidence: the positive audienceAllow_* proxy
 * fields exist, invert correctly to/from the real hideForRole_ and
 * hideForGuests settings on both load and save, and the template never
 * renders a raw
 * negative checkbox or an optional privacy-mode toggle again.
 */
$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
$tpl = (string) file_get_contents($root . '/templates/settingsForm.tpl');

$audienceRoleMap = [
    'guest' => 'hideForGuests',
    'author' => 'hideForRole_65536',
    'reviewer' => 'hideForRole_4096',
    'reader' => 'hideForRole_1048576',
    'manager' => 'hideForRole_16',
    'subEditor' => 'hideForRole_17',
    'assistant' => 'hideForRole_4097',
    'siteAdmin' => 'hideForRole_1',
];

positiveAudienceCheck(str_contains($formSource, 'AUDIENCE_ROLE_KEYS'), 'ChatwootSettingsForm must declare a real audience-role mapping');
foreach ($audienceRoleMap as $audienceKey => $hideKey) {
    positiveAudienceCheck(
        (bool) preg_match("/'{$audienceKey}'\\s*=>\\s*'{$hideKey}'/", $formSource),
        "AUDIENCE_ROLE_KEYS must map '{$audienceKey}' to the real setting '{$hideKey}' — every one of the 8 legacy roles must be covered"
    );
    positiveAudienceCheck(
        str_contains($tpl, "id=\"audienceAllow_{$audienceKey}\""),
        "settingsForm.tpl must render a positive audienceAllow_{$audienceKey} checkbox, not the raw negative {$hideKey} field"
    );
    positiveAudienceCheck(
        !preg_match("/id=\"{$hideKey}\"/", $tpl),
        "settingsForm.tpl must never render the raw negative {$hideKey} checkbox directly — only its positive audienceAllow_{$audienceKey} proxy"
    );
}

// initData() must derive each audienceAllow_* display value as the
// inverse of the stored hideFor* value — never render a stale/independent
// copy that could silently disagree with the real runtime gate.
$initDataStart = strpos($formSource, 'function initData(');
$initDataBody = substr($formSource, $initDataStart, (int) strpos($formSource, "\n    }\n", $initDataStart) - $initDataStart);
positiveAudienceCheck(str_contains($initDataBody, "'audienceAllow_' . \$audienceKey"), 'initData() must derive every audienceAllow_* display value from AUDIENCE_ROLE_KEYS, not hardcode each one separately');
positiveAudienceCheck(str_contains($initDataBody, '!self::isChecked'), 'initData() must invert the stored hideFor* value (checked=hidden) into the positive "allowed" display value');

// execute() must translate the submitted audienceAllow_* checkboxes back
// into the real hideFor* keys before SettingsRegistry::keys() are saved.
$executeStart = strpos($formSource, 'function execute(');
$executeBody = substr($formSource, $executeStart, (int) strpos($formSource, "\n    }\n", $executeStart) - $executeStart);
positiveAudienceCheck(str_contains($executeBody, '$this->setData($hideKey, !$allowed)'), 'execute() must write the inverse of each submitted audienceAllow_* checkbox back into its real hideFor* setting before saving');
positiveAudienceCheck(
    strpos($executeBody, 'AUDIENCE_ROLE_KEYS') < strpos($executeBody, 'SettingsRegistry::keys()'),
    'execute() must translate audienceAllow_* back into hideFor*/hideForGuests BEFORE the SettingsRegistry::keys() save loop runs, or the translated values would never actually persist'
);

/**
 * Real-browser-discovered defect (2026-09-04): splitting the old single
 * "list=true" fbvFormSection (enableWidget + hideFor* checkboxes) into
 * separate sections dropped list=true from the one still wrapping the
 * enableWidget checkbox — PKP's FormBuilderVocabulary throws "FBV: list
 * attribute not set on form section containing lists" (an uncaught
 * fatal with an empty response body and no application log line at all,
 * since it happens outside any Hook::call()-wrapped context) the moment
 * ANY fbvFormSection contains a checkbox/radio <li> element without
 * list=true, even a section with only one. Every fbvFormSection in the
 * template that contains an {fbvElement type="checkbox"...} or
 * type="radio" must declare list=true.
 */
preg_match_all('/\{fbvFormSection([^}]*)\}(.*?)\{\/fbvFormSection\}/s', $tpl, $sectionMatches, PREG_SET_ORDER);
positiveAudienceCheck(count($sectionMatches) > 5, 'sanity check: expected multiple fbvFormSection blocks in the template');
foreach ($sectionMatches as $section) {
    [, $attrs, $body] = $section;
    $hasListElement = (bool) preg_match('/type="(?:checkbox|radio)"/', $body);
    if ($hasListElement) {
        positiveAudienceCheck(
            str_contains($attrs, 'list=true'),
            'every fbvFormSection containing a checkbox/radio element must declare list=true, or FormBuilderVocabulary throws an uncaught fatal — offending section attrs: ' . trim($attrs)
        );
    }
}

// The old optional privacy-mode checkbox must be gone from both the
// template and the registry — see har-006-shared-reviewer-masking.php
// for the runtime-invariant side of this fix.
positiveAudienceCheck(!str_contains($tpl, 'id="enablePrivacyMode"'), 'settingsForm.tpl must never render enablePrivacyMode as a checkbox — blind-review protection is a frozen invariant, not an optional control');
positiveAudienceCheck(str_contains($tpl, 'plugins.generic.chatwootIntegration.settings.blindReview.title'), 'settingsForm.tpl must show the frozen "Always enforced" blind-review status');
positiveAudienceCheck(!isset(SettingsRegistry::all()['enablePrivacyMode']), 'enablePrivacyMode must be removed from SettingsRegistry entirely');

// The 8 legacy keys must still be real, exportable registry keys (so
// export/import/global-profile round-tripping keeps working) even
// though the UI no longer renders them directly.
foreach ($audienceRoleMap as $hideKey) {
    positiveAudienceCheck(SettingsRegistry::get((string) $hideKey) !== null, "'{$hideKey}' must remain a real SettingsRegistry key even though the UI now renders its positive proxy instead");
}

fwrite(STDOUT, "Positive audience model tests passed\n");
