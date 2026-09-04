<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function cwDiscoveryCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Settings Console Chatwoot tab (owner directive 2026-09-04):
 * administrators should not need to know numeric Inbox/Captain
 * Assistant IDs. discoverChatwootResources() replaces that with real
 * Chatwoot API discovery. Guzzle is not available in this local test
 * harness, so ChatwootApiService's actual HTTP behavior is verified
 * separately (live CLI-harness pattern used elsewhere this session);
 * this proves the real wiring — manage() dispatch, secret-masking
 * handling, HAR-001 never-guess-the-account semantics, Website-only
 * inbox filtering, and the Captain-assistant field allowlist that
 * keeps real confidential guardrails/response_guidelines/config out
 * of every response this plugin ever sends.
 */
$v2Source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

cwDiscoveryCheck(str_contains($v2Source, "'discoverChatwootResources'"), 'manage() must dispatch the discoverChatwootResources verb');
cwDiscoveryCheck(str_contains($v2Source, 'function discoverChatwootResources('), 'discoverChatwootResources() must exist');

$methodStart = strpos($v2Source, 'function discoverChatwootResources(');
$methodBody = substr($v2Source, $methodStart, (int) strpos($v2Source, "\n    }\n", $methodStart) - $methodStart);

cwDiscoveryCheck(str_contains($methodBody, 'SecretFieldMasking::resolveSavedValue('), 'the API token must be resolved via SecretFieldMasking — the field redisplays as the mask string once already saved, and posting that mask back must never be treated as the real token');

// HAR-001: never guess when more than one account exists.
cwDiscoveryCheck(str_contains($methodBody, "'needsAccountSelection' => true"), 'more than one account with no explicit selection must return needsAccountSelection, never guess');
$multiAccountBranchPos = strpos($methodBody, 'count($accounts) === 1');
$needsSelectionPos = strpos($methodBody, "'needsAccountSelection' => true");
cwDiscoveryCheck($multiAccountBranchPos !== false && $needsSelectionPos !== false && $multiAccountBranchPos < $needsSelectionPos, 'auto-select-when-exactly-one must be checked before falling through to needsAccountSelection');
cwDiscoveryCheck(str_contains($methodBody, '$api->setAccountId($selectedAccountId)'), 'once an account is resolved, every later resource call must be scoped to it via setAccountId(), never the constructor default');

// Only real Website (widget) inboxes belong in the selector.
cwDiscoveryCheck(str_contains($methodBody, "!== 'Channel::WebWidget'"), 'inbox discovery must filter to Channel::WebWidget only — a WhatsApp/API inbox is never a valid Website Inbox selection');

fwrite(STDOUT, "sanity: located discoverChatwootResources() and verified its real structure\n");

// ChatwootApiService::listCaptainAssistants() must never expose the
// real assistant's confidential fields.
$apiServiceSource = (string) file_get_contents("{$root}/ChatwootApiService.php");
$assistantMethodStart = strpos($apiServiceSource, 'function listCaptainAssistants(');
cwDiscoveryCheck($assistantMethodStart !== false, 'listCaptainAssistants() must exist');
$assistantMethodBody = substr($apiServiceSource, $assistantMethodStart, (int) strpos($apiServiceSource, "\n    }\n", $assistantMethodStart) - $assistantMethodStart);
foreach (['guardrails', 'response_guidelines', 'config'] as $confidentialField) {
    cwDiscoveryCheck(!str_contains($assistantMethodBody, "'{$confidentialField}'"), "listCaptainAssistants() must never reference the real assistant's confidential '{$confidentialField}' field — real accounts carry internal business rules there");
}
cwDiscoveryCheck(
    (bool) preg_match("/'id' => \(int\) \\\$assistant\['id'\],\s*'name' => \(string\) \(\\\$assistant\['name'\] \?\? ''\),\s*'description' => \(string\) \(\\\$assistant\['description'\] \?\? ''\),/", $assistantMethodBody),
    'listCaptainAssistants() must return exactly the safe id/name/description projection, nothing more'
);

// chatwootAccountId must exist in the canonical registry, on the
// chatwoot tab, and remain global-eligible (it describes a fact about
// the shared token, not journal-specific data — HAR-008's invariant is
// "non-global-eligible === secret," which chatwootAccountId is not).
$def = SettingsRegistry::get('chatwootAccountId');
cwDiscoveryCheck($def !== null, 'chatwootAccountId must be a real registered setting');
cwDiscoveryCheck($def->tab === 'chatwoot', 'chatwootAccountId must live on the Chatwoot tab');
cwDiscoveryCheck($def->secret === false, 'chatwootAccountId is not a secret');
cwDiscoveryCheck(!in_array('chatwootAccountId', SettingsRegistry::nonGlobalEligibleKeys(), true), 'chatwootAccountId must remain global-eligible — it describes the account a shared token resolves to, not journal-specific data');

fwrite(STDOUT, "Chatwoot tab discovery tests passed\n");
