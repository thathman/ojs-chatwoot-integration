<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function submissionCreatedEventWiringCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// EVT-003/EVT-011: verifies the real hook wiring for
// ChatwootIntegrationV2Plugin::handleSubmissionCreated() — the first
// point in this whole build where Event Bridge v2 touches a live OJS
// hook. Full behavioral instantiation isn't possible in this
// plain-PHP test environment (parent::handleSubmissionCreated() reaches
// deep into Repo::submission()/Mail::send()/etc., same constraint as
// every other deeply-OJS-entangled hook method — see
// tests/v2/live-plugin.php's identical source-level treatment of
// addChatwootWidget()), so this proves the wiring shape directly from
// source, the same standard already applied to that method.
// ================================================================

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');

submissionCreatedEventWiringCheck(str_contains($pluginSource, 'function handleSubmissionCreated'), 'plugin must override handleSubmissionCreated()');

$methodStart = strpos($pluginSource, 'function handleSubmissionCreated');
submissionCreatedEventWiringCheck($methodStart !== false, 'must be able to locate the method body for the checks below');
$methodEnd = strpos($pluginSource, 'public function bindSupportSessionRequest', $methodStart);
submissionCreatedEventWiringCheck($methodEnd !== false, 'must be able to bound the method body');
$methodBody = substr($pluginSource, $methodStart, $methodEnd - $methodStart);

submissionCreatedEventWiringCheck(
    (bool) preg_match('/\$result\s*=\s*parent::handleSubmissionCreated\(\$hookName,\s*\$args\)/', $methodBody),
    'v1\'s real handleSubmissionCreated() must run first and unconditionally — its return value is what this method returns'
);
submissionCreatedEventWiringCheck(
    substr_count($methodBody, 'return $result;') === 1,
    'the method must return v1\'s real result exactly once, never a hardcoded value or the v2 enqueue outcome'
);
submissionCreatedEventWiringCheck(
    (bool) preg_match('/catch \(\\\\Throwable \$e\) \{[^}]*\}\s*return \$result;/s', $methodBody),
    'v2 enqueue work must be wrapped in its own try/catch, positioned so a v2 failure can never prevent returning v1\'s real result'
);
submissionCreatedEventWiringCheck(str_contains($methodBody, 'SubmissionCreatedEventAdapter::fromSubmission'), 'must convert the real submission via the real EVT-003 adapter, not a bespoke inline conversion');
submissionCreatedEventWiringCheck(str_contains($methodBody, 'EventDeliveryPolicy::resolve'), 'must resolve a real delivery mode via the real EVT-010 policy, not hardcode one');
submissionCreatedEventWiringCheck(str_contains($methodBody, "'eventSyncMode'"), 'must preserve v1\'s real eventSyncMode setting as the delivery-policy global mode, per EVT migration requirement to preserve configured event choices');
submissionCreatedEventWiringCheck(str_contains($methodBody, 'DatabaseSupportEventQueueRepository'), 'must enqueue through the real EVT-011/014 persisted queue, never send directly to Chatwoot from a live hook');
foreach (['createConversation', 'sendChatwootEvent', 'ChatwootApiService', 'Mail::send'] as $forbidden) {
    submissionCreatedEventWiringCheck(!str_contains($methodBody, $forbidden), "this method must never touch '{$forbidden}' directly — it only enqueues; delivery is a separate, not-yet-built consumer");
}

fwrite(STDOUT, "Submission-created event wiring tests passed\n");
