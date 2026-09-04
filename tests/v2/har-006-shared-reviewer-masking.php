<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    function har006Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-006: before this, the widget-injection path
     * (ChatwootIntegrationBasePlugin::addChatwootWidget()) and the v2
     * bind handshake (ChatwootIntegrationV2Plugin::bindSupportSessionRequest())
     * computed reviewer-masking independently — the widget used the
     * resource-aware ReviewerMaskingPolicy (POL-011/CWO-016), bind
     * still used the original journal-wide-role-only check
     * ($privacy && in_array(Role::ROLE_ID_REVIEWER, ...)). A multi-role
     * user (author on Submission A, reviewer on Submission B) could
     * therefore see an unmasked widget while bind computed a masked
     * expected identifier for that exact same real request — a real
     * identity-projection mismatch. `tests/v2/pol-011-resource-aware-reviewer-masking.php`
     * already covers ReviewerMaskingPolicy's own pure logic; this test
     * proves both real call sites now share exactly one masking
     * decision (resolveReviewerMasking()) instead of maintaining two.
     */
    $baseSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

    // The shared method must exist, on the base class both widget and
    // bind can reach (bindSupportSessionRequest() lives on the v2
    // subclass, which extends ChatwootIntegrationBasePlugin).
    $sharedMethodStart = strpos($baseSource, 'function resolveReviewerMasking(');
    har006Check($sharedMethodStart !== false, 'ChatwootIntegrationBasePlugin must declare the shared resolveReviewerMasking()');
    $sharedMethodBody = substr($baseSource, $sharedMethodStart, (int) strpos($baseSource, "\n    }\n", $sharedMethodStart) - $sharedMethodStart);
    har006Check(str_contains($sharedMethodBody, 'new ReviewerMaskingPolicy('), 'resolveReviewerMasking() must construct the real ReviewerMaskingPolicy');
    har006Check(str_contains($sharedMethodBody, 'CurrentSubmissionResolver'), 'resolveReviewerMasking() must resolve the current submission before deferring to the policy');

    // The widget path must call the shared method, not its own inline
    // copy of the policy-construction logic.
    $widgetMethodStart = strpos($baseSource, 'function addChatwootWidget(');
    har006Check($widgetMethodStart !== false, 'addChatwootWidget() must exist');
    $widgetMethodBody = substr($baseSource, $widgetMethodStart, (int) strpos($baseSource, "\n    }\n", $widgetMethodStart) - $widgetMethodStart);
    har006Check(str_contains($widgetMethodBody, '$this->resolveReviewerMasking('), 'addChatwootWidget() must call the shared resolveReviewerMasking(), not maintain its own independent policy construction');
    har006Check(!str_contains($widgetMethodBody, 'new ReviewerMaskingPolicy('), 'addChatwootWidget() must no longer construct ReviewerMaskingPolicy directly — that now lives only inside the shared method');

    // The bind path must call the same shared method, and must no
    // longer compute masking from the journal-wide role alone.
    $bindMethodStart = strpos($v2Source, 'function bindSupportSessionRequest(');
    har006Check($bindMethodStart !== false, 'bindSupportSessionRequest() must exist');
    $bindMethodBody = substr($v2Source, $bindMethodStart, (int) strpos($v2Source, "\n    }\n", $bindMethodStart) - $bindMethodStart);
    har006Check(str_contains($bindMethodBody, '$this->resolveReviewerMasking('), 'bindSupportSessionRequest() must call the shared resolveReviewerMasking() — the exact same decision the widget uses for the same request');
    har006Check(!preg_match('/\$privacy\s*&&\s*in_array\(\s*Role::ROLE_ID_REVIEWER/', $bindMethodBody), 'bindSupportSessionRequest() must no longer compute masking from the journal-wide Reviewer role alone — that is the real inconsistency HAR-006 found');

    /**
     * Real-browser-discovered defect (2026-09-04, item D): both call
     * sites used to gate resolveReviewerMasking() behind
     * "enablePrivacyMode", a togglable admin setting that defaulted to
     * false — a fresh install (or an admin who never found the
     * checkbox) exposed real reviewer identity to Chatwoot by default.
     * Blind-review protection is a mandatory invariant, not an opt-in:
     * neither call site may condition masking on that setting again.
     */
    har006Check(!preg_match('/(?:getEffectiveSetting|getSetting|v2EffectiveSetting)\([^)]*enablePrivacyMode/', $widgetMethodBody), 'addChatwootWidget() must not read the enablePrivacyMode setting — masking is unconditional');
    har006Check(!preg_match('/(?:getEffectiveSetting|getSetting|v2EffectiveSetting)\([^)]*enablePrivacyMode/', $bindMethodBody), 'bindSupportSessionRequest() must not read the enablePrivacyMode setting — masking is unconditional');
    har006Check(!isset(\APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry::all()['enablePrivacyMode']), 'enablePrivacyMode must be removed from SettingsRegistry entirely, not merely hidden from the UI');

    fwrite(STDOUT, "HAR-006 shared reviewer masking tests passed\n");
}
