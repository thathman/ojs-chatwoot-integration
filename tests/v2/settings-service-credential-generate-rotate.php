<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ServiceCredentialGenerator;

function scgrCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Settings Console item H (API & MCP tab, owner directive 2026-09-04):
 * "secure generate/rotate workflow" for the two plugin-owned service
 * credentials — before this, an admin had to invent and paste their
 * own bearer token by hand with no guaranteed entropy.
 */

// ================================================================
// Part 1: ServiceCredentialGenerator — real entropy, real allowlist.
// ================================================================
$value1 = ServiceCredentialGenerator::generate();
$value2 = ServiceCredentialGenerator::generate();
scgrCheck(strlen($value1) === 64, 'generate() must produce a 64-hex-character (32-byte) value');
scgrCheck((bool) preg_match('/^[0-9a-f]{64}$/', $value1), 'generate() must produce only real lowercase hex characters');
scgrCheck($value1 !== $value2, 'two calls to generate() must never produce the same value — this is a real randomness check, not a guaranteed-different mock');

scgrCheck(ServiceCredentialGenerator::isAllowedKey('chatwootSupportApiToken'), 'chatwootSupportApiToken must be an allowed credential key');
scgrCheck(ServiceCredentialGenerator::isAllowedKey('mcpServiceToken'), 'mcpServiceToken must be an allowed credential key');
scgrCheck(!ServiceCredentialGenerator::isAllowedKey('chatwootApiAccessToken'), 'chatwootApiAccessToken is a Chatwoot-issued credential, not plugin-owned — it must never be generatable through this path');
scgrCheck(!ServiceCredentialGenerator::isAllowedKey('chatwootIdentityValidationSecret'), 'chatwootIdentityValidationSecret must never be generatable through this generic path — it has its own dedicated purpose and existing setup flow');
scgrCheck(!ServiceCredentialGenerator::isAllowedKey('../../etc/passwd'), 'an arbitrary/malicious key string must never be allowed — the allowlist must be a real fixed set, not a permissive check');

// ================================================================
// Part 2: real wiring — plugin dispatch validates the key through the
// same allowlist before ever generating or persisting anything, and
// persists immediately rather than requiring a separate Save click.
// ================================================================
$pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
scgrCheck(str_contains($pluginSource, "'generateServiceCredential'"), 'manage() must dispatch the generateServiceCredential verb');
scgrCheck(str_contains($pluginSource, 'function generateServiceCredential('), 'generateServiceCredential() must exist');
$genStart = strpos($pluginSource, 'function generateServiceCredential(');
$genBody = substr($pluginSource, $genStart, (int) strpos($pluginSource, "\n    }\n", $genStart) - $genStart);
scgrCheck(str_contains($genBody, 'ServiceCredentialGenerator::isAllowedKey('), 'generateServiceCredential() must validate the requested key against the real allowlist before doing anything else');
scgrCheck(str_contains($genBody, 'ServiceCredentialGenerator::generate()'), 'generateServiceCredential() must use the real generator, never invent its own random value inline');
scgrCheck(str_contains($genBody, '$this->updateSetting('), 'generateServiceCredential() must persist the new value immediately — an admin should not have to separately click Save for a credential rotation to take effect');
scgrCheck(str_contains($genBody, "'value' => \$newValue"), 'generateServiceCredential() must return the real plaintext value exactly once in its own response, for the admin to copy — this is the only place it is ever exposed after generation');

// ================================================================
// Part 3: template/UI wiring — a real Generate/Rotate button per
// credential, and the returned plaintext is shown exactly once in a
// one-time status message, never written into a page that could be
// reloaded and still show it.
// ================================================================
$tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");
scgrCheck(str_contains($tpl, 'id="chatwootGenerateSupportApiTokenBtn"'), 'a real Generate/Rotate button must exist for the Support API token');
scgrCheck(str_contains($tpl, 'id="chatwootGenerateMcpTokenBtn"'), 'a real Generate/Rotate button must exist for the MCP service token');
scgrCheck(str_contains($tpl, 'cwWireCredentialGenerate'), 'both buttons must be wired through one shared generate/rotate JS helper, not two hand-copied implementations');
scgrCheck(str_contains($tpl, 'id="chatwootSupportApiToken"'), 'the real masked password field for chatwootSupportApiToken must still exist — Generate/Rotate populates it, never replaces the normal masked-secret display convention');
scgrCheck(str_contains($tpl, 'id="mcpServiceToken"'), 'the real masked password field for mcpServiceToken must still exist');

fwrite(STDOUT, "Service credential generate/rotate tests passed\n");
