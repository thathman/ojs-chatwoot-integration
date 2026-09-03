<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SecretFieldMasking;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function settingsFormMaskingCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Admin/settings foundation: the first slice of the v2 "Support Gateway"
 * admin section (Connection + Support API + MCP credential groups).
 * ChatwootSettingsForm/settingsForm.tpl themselves cannot be exercised
 * here (PKP\form\Form and Smarty rendering both need a live OJS
 * runtime), so this covers the two things that ARE fully testable
 * without one: the pure secret-masking logic every secret field shares,
 * and source-level wiring assertions on the real form/template/export
 * files, so a regression in either is still caught here.
 */

// ================================================================
// SecretFieldMasking — the shared pure logic every secret field
// (chatwootIdentityValidationSecret, chatwootApiAccessToken,
// chatwootSupportApiToken, mcpServiceToken) is driven by.
// ================================================================
settingsFormMaskingCheck(SecretFieldMasking::displayValue('') === '', 'an empty stored secret must display as empty, never the mask, so a first-time admin knows nothing is set yet');
settingsFormMaskingCheck(SecretFieldMasking::displayValue('real-secret-value') === SecretFieldMasking::MASK, 'a real stored secret must display only as the mask, never the plaintext value, once saved');

settingsFormMaskingCheck(
    SecretFieldMasking::resolveSavedValue(SecretFieldMasking::MASK, 'real-secret-value') === 'real-secret-value',
    'resubmitting the mask unchanged must keep the real existing secret, never overwrite it with the literal mask string'
);
settingsFormMaskingCheck(
    SecretFieldMasking::resolveSavedValue('a-new-real-value', 'real-secret-value') === 'a-new-real-value',
    'submitting a genuinely new value must replace the stored secret'
);
settingsFormMaskingCheck(
    SecretFieldMasking::resolveSavedValue('', 'real-secret-value') === '',
    'submitting an explicitly empty value must clear the stored secret (a deliberate admin action), never be confused with "unchanged"'
);
settingsFormMaskingCheck(
    SecretFieldMasking::resolveSavedValue(SecretFieldMasking::MASK, '') === '',
    'resubmitting the mask when nothing was ever actually stored must resolve to empty, never to the literal mask string being saved as if it were a real secret'
);

// ================================================================
// Wiring: the real settings form must mask exactly the four real
// secrets, never the non-secret connection fields, and must apply
// masking before ever calling updateSetting() for those keys.
// ================================================================
$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');

settingsFormMaskingCheck(str_contains($formSource, 'use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SecretFieldMasking;'), 'ChatwootSettingsForm must use the real shared SecretFieldMasking helper, never a bespoke inline copy');

$secretKeysStart = strpos($formSource, 'private const SECRET_KEYS');
settingsFormMaskingCheck($secretKeysStart !== false, 'ChatwootSettingsForm must declare a real SECRET_KEYS list');
$secretKeysBlock = substr($formSource, $secretKeysStart, (int) strpos($formSource, '];', $secretKeysStart) - $secretKeysStart);
foreach (['chatwootIdentityValidationSecret', 'chatwootApiAccessToken', 'chatwootSupportApiToken', 'mcpServiceToken'] as $secretKey) {
    settingsFormMaskingCheck(str_contains($secretKeysBlock, "'{$secretKey}'"), "SECRET_KEYS must include the real secret \"{$secretKey}\"");
}
foreach (['chatwootBaseUrl', 'chatwootWebsiteToken', 'chatwootInboxId'] as $nonSecretKey) {
    settingsFormMaskingCheck(!str_contains($secretKeysBlock, "'{$nonSecretKey}'"), "SECRET_KEYS must never include the non-secret \"{$nonSecretKey}\" — masking a connection detail that isn't actually a secret would just make the admin re-enter it needlessly");
}

settingsFormMaskingCheck(str_contains($formSource, "'mcpServiceToken'"), 'mcpServiceToken must be a real, wired settings key (initData/readInputData/execute), not just declared as a secret');
settingsFormMaskingCheck(str_contains($formSource, 'SecretFieldMasking::displayValue($value)'), 'initData() must mask secret values through the real shared helper before ever assigning them to the template');
settingsFormMaskingCheck(str_contains($formSource, 'SecretFieldMasking::resolveSavedValue($submitted, $existing)'), 'execute() must resolve the real saved value through the real shared helper before updateSetting() ever runs, so a resubmitted mask can never overwrite a real secret');

$maskResolutionPos = strpos($formSource, 'SecretFieldMasking::resolveSavedValue($submitted, $existing)');
$updateSettingPos = strpos($formSource, '$plugin->updateSetting($contextId, $key, $this->getData($key), $type)');
settingsFormMaskingCheck($maskResolutionPos !== false && $updateSettingPos !== false && $maskResolutionPos < $updateSettingPos, 'secret values must be resolved through the masking helper before the settings-save loop runs, never after');

// ================================================================
// Wiring: mcpServiceToken must never be exportable/importable, exactly
// like the other real secrets already established this session.
// ================================================================
// UX-024: both exportKeys() (v1) and legacyExportKeys() (v2) now
// delegate directly to SettingsRegistry::exportableKeys(), which
// itself marks mcpServiceToken exportable: false — checking the
// single shared source is now the correct-tier assertion.
settingsFormMaskingCheck(!in_array('mcpServiceToken', SettingsRegistry::exportableKeys(), true), 'mcpServiceToken must never be exportable — it must be structurally impossible to export or import via the settings backup path, same as the MCP credential design already establishes elsewhere');

// ================================================================
// Wiring: the template must render every real secret as a password
// field (never plaintext-echoed text), and must display the real MCP
// endpoint/protocol revision, never a placeholder.
//
// NOTE (TST-023): `type="password"` is NOT a real pkp-lib FBV element
// type — FormBuilderVocabulary::smartyFBVElement()'s type switch has
// no 'password' case and hits `default: assert(false)`, an uncaught
// AssertionError under PHP 8 that produced a real, reproducible 500
// on the live settings modal. The correct, real pkp-lib incantation
// is `type="text" password=true` — _smartyFBVTextInput() reads the
// 'password' param, not the type, to decide masked rendering.
// ================================================================
$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
foreach (['chatwootIdentityValidationSecret', 'chatwootApiAccessToken', 'chatwootSupportApiToken', 'mcpServiceToken'] as $secretKey) {
    settingsFormMaskingCheck(str_contains($templateSource, "type=\"text\" password=true id=\"{$secretKey}\""), "the template must render \"{$secretKey}\" as a real masked password field via the real pkp-lib incantation (type=\"text\" password=true), never plaintext text and never the fake type=\"password\" that crashes FormBuilderVocabulary");
}
settingsFormMaskingCheck(str_contains($templateSource, 'type="text" id="chatwootBaseUrl"'), 'chatwootBaseUrl must remain a plain text field — it is not a secret');
settingsFormMaskingCheck(str_contains($templateSource, '$mcpEndpointUrl') && str_contains($templateSource, '$mcpProtocolRevision'), 'the template must display the real MCP endpoint URL and protocol revision, not a static placeholder');

$formPhpSource = $formSource;
settingsFormMaskingCheck(str_contains($formPhpSource, "PKPApplication::ROUTE_PAGE, \$context->getPath(), 'ojsMcpGateway'"), 'the real MCP endpoint URL shown to the admin must be built from the real ojsMcpGateway page route, never a hand-typed/hardcoded URL string');

fwrite(STDOUT, "Settings form MCP/secret masking tests passed\n");
