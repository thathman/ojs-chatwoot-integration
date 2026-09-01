<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function adminMailTestCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * ADM-006 (verification EmailTemplates / mail test, first slice): the
 * "Send test email" diagnostic has no PHP logic worth proving on a real
 * database or HTTP server (it is a single Mail::send() call with no
 * database/queue/HTTP behavior of its own), so this is a source-level
 * wiring + structural-independence proof: the diagnostic Mailable must
 * be completely separate from the real verification challenge system,
 * and the plugin/form/template glue must be real.
 */

$mailableSource = (string) file_get_contents($root . '/classes/v2/Verification/SupportMailTestMailable.php');
adminMailTestCheck(str_contains($mailableSource, 'final class SupportMailTestMailable extends Mailable'), 'SupportMailTestMailable must exist as its own Mailable subclass');
adminMailTestCheck(!str_contains($mailableSource, 'VerificationChallengeService') && !str_contains($mailableSource, 'v2VerificationPepper'), 'the mail test diagnostic must never touch the verification challenge system (no shared pepper/challenge code path)');
adminMailTestCheck(!str_contains($mailableSource, 'new SupportVerificationMailable') && !str_contains($mailableSource, 'extends SupportVerificationMailable'), 'the mail test diagnostic must be a structurally independent class, never a wrapper around or subclass of the real verification Mailable');

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
adminMailTestCheck(str_contains($pluginSource, 'use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\SupportMailTestMailable;'), 'the plugin must import the real diagnostic Mailable');

$methodStart = strpos($pluginSource, 'function sendSupportMailTest(');
adminMailTestCheck($methodStart !== false, 'the plugin must implement a real sendSupportMailTest() method');
$methodBody = substr($pluginSource, $methodStart, (int) strpos($pluginSource, "\n    }\n", $methodStart) - $methodStart);
adminMailTestCheck(str_contains($methodBody, 'Mail::send(new SupportMailTestMailable('), 'the diagnostic must send through the real Mail::send()/Mailable transport, never a fabricated success response');
adminMailTestCheck(!str_contains($methodBody, 'VerificationChallengeService') && !str_contains($methodBody, 'v2VerificationPepper') && !str_contains($methodBody, 'generateChallenge'), 'the diagnostic method must never reuse the verification challenge system (no PIN/link/pepper generation)');
adminMailTestCheck(str_contains($methodBody, 'catch (\Throwable $e)'), 'the diagnostic must report real transport failure rather than assuming success');

adminMailTestCheck(str_contains($pluginSource, "if (\$request->getUserVar('verb') === 'sendSupportMailTest')"), 'the plugin must route a real sendSupportMailTest verb to the real method');

$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
adminMailTestCheck(str_contains($formSource, "'verb' => 'sendSupportMailTest'"), 'the settings form must build a real URL for the sendSupportMailTest verb');

$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
adminMailTestCheck(str_contains($templateSource, 'chatwootSendMailTestBtn') && str_contains($templateSource, '$sendSupportMailTestUrl'), 'the template must render a real, wired Send Test Email button');

fwrite(STDOUT, "Admin mail test diagnostic tests passed\n");
