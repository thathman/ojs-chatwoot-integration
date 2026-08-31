<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFieldAllowlist;

function apiFieldAllowlistCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeAllowlistRequest
{
    public function __construct(private array $vars)
    {
    }
    public function getUserVars(): array
    {
        return $this->vars;
    }
}

// The three chatwootAccountId/chatwootContactId/chatwootConversationId
// fields are read by SupportApiRequestResolver before any endpoint runs, so
// every endpoint must allow them even if the endpoint itself never reads
// them again.
$common = ['chatwootAccountId' => '1', 'chatwootContactId' => '2', 'chatwootConversationId' => '3'];

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest($common), 'status') === null,
    'the three common conversation fields alone must be accepted by an endpoint with no fields of its own'
);

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest($common + ['scope' => 'submission']), 'accountDiagnostics') === null,
    'a field the endpoint actually reads must be accepted'
);

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest($common + ['isAdmin' => '1']), 'status') === 'isAdmin',
    'a field no endpoint declared must be rejected, naming the offending field'
);

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest($common + ['submissionId' => '5']), 'status') === 'submissionId',
    'a field valid on a different endpoint (submissionId) must still be rejected on one that never reads it'
);

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest($common + ['email' => 'a@example.com', 'purpose' => 'p', 'method' => 'pin']), 'verificationRequest') === null,
    'every field verificationRequest actually reads must be accepted together'
);

apiFieldAllowlistCheck(
    SupportApiFieldAllowlist::firstUnknownField(new FakeAllowlistRequest([]), 'unknownEndpoint') === null,
    'an endpoint not in the allowlist (not gated by resolveSupportApiRequest) must never be checked here, since this class does not know its real field contract'
);

// ================================================================
// Wiring: resolveSupportApiRequest() must only run this check once the
// request is authenticated, so an unauthenticated caller can never use
// field names as a probing oracle.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$resolveStart = strpos($pluginSource, 'private function resolveSupportApiRequest');
apiFieldAllowlistCheck($resolveStart !== false, 'resolveSupportApiRequest() must exist');
$resolveBody = substr($pluginSource, $resolveStart, (strpos($pluginSource, "\n    public function exportSettings", $resolveStart) ?: strlen($pluginSource)) - $resolveStart);

apiFieldAllowlistCheck(str_contains($resolveBody, 'SupportApiFieldAllowlist::firstUnknownField'), 'resolveSupportApiRequest() must call the allowlist check');
apiFieldAllowlistCheck(
    (bool) preg_match('/instanceof SupportApiRequestContext\)\s*\{\s*\$unknownField = SupportApiFieldAllowlist::firstUnknownField/s', $resolveBody),
    'the allowlist check must run only after resolve() already returned an authenticated context, never before'
);

fwrite(STDOUT, "API field allowlist tests passed\n");
