<?php

declare(strict_types=1);

// ================================================================
// TST-023: real live acceptance testing (SETTINGS-001) found that
// opening the real settings modal in a real browser produced a real,
// reproducible HTTP 500 with NO logged fatal anywhere (dell's
// log_errors was Off, masking it completely — see
// docs/v2/ACCEPTANCE_TEST_MATRIX.md for that separate infra finding).
//
// Root cause, confirmed live via a temporary debug patch to pkp-lib's
// FormBuilderVocabulary::smartyFBVElement(): PKP's FBV template
// system has NEVER had a 'password' case in its
// strtolower($params['type']) switch (confirmed against the real
// pkp/pkp-lib stable-3_5_0 source) — only 'autocomplete', 'button',
// 'submit', 'checkbox', 'checkboxgroup', 'file', 'hidden', 'keyword',
// 'interests', 'radio', 'email', 'search', 'tel', 'text', 'url',
// 'select', 'textarea'. Anything else hits `default: assert(false)`,
// which throws an uncaught AssertionError (zend.assertions=1,
// assert.exception=On — PHP 8 defaults) and produces exactly the
// empty-body 500 observed live.
//
// settingsForm.tpl used `type="password"` (not a real FBV type) for
// all four SECRET_KEYS fields (chatwootIdentityValidationSecret,
// chatwootApiAccessToken, chatwootSupportApiToken, mcpServiceToken).
// The correct, real pkp-lib incantation is `type="text" password=true`
// — _smartyFBVTextInput() checks isset($params['password']) to decide
// FBV_isPassword, not the element's declared type. This means the
// real settings modal had never rendered end-to-end past the first
// secret field in any live browser; only source-tree tests validated
// the masking *logic*, never a live Smarty render.
//
// This test asserts against the real source tree that every secret
// field uses the real, working incantation, and that the broken
// `type="password"` spelling never regresses.
// ================================================================

function tst023Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$templateSource = (string) file_get_contents("{$root}/templates/settingsForm.tpl");

tst023Check(
    !str_contains($templateSource, 'type="password"'),
    'settingsForm.tpl must never use type="password" — pkp-lib\'s FormBuilderVocabulary has no "password" case in its FBV type switch and hits `default: assert(false)`, a real live 500 with no logged fatal when dell\'s log_errors is Off'
);

$secretFieldIds = [
    'chatwootIdentityValidationSecret',
    'chatwootApiAccessToken',
    'chatwootSupportApiToken',
    'mcpServiceToken',
];

foreach ($secretFieldIds as $id) {
    tst023Check(
        (bool) preg_match('/\{fbvElement\s+type="text"\s+password=true\s+id="' . preg_quote($id, '/') . '"/', $templateSource),
        "the {$id} field must use the real pkp-lib masked-input incantation: type=\"text\" password=true"
    );
}

fwrite(STDOUT, "PASS: tst-023-fbv-password-field-type\n");
