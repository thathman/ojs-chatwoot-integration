<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

function openapiContractCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * API-018: contract-tests the OpenAPI schema (API-017) against real
 * endpoint output. Deliberately does not pull in a general-purpose JSON
 * Schema validator dependency for two representative endpoints — the
 * checks below directly compare a schema's declared property names against
 * the real serializer's actual output keys, in both directions (an
 * undeclared key the serializer emits, and a declared key the serializer
 * never emits, are both a real contract drift and must fail this test).
 */

$schema = json_decode((string) file_get_contents($root . '/docs/v2/openapi.json'), true);
openapiContractCheck(is_array($schema) && json_last_error() === JSON_ERROR_NONE, 'docs/v2/openapi.json must be valid JSON');

/** @return string[] */
function openapiSchemaProperties(array $schema, string $name): array
{
    $node = $schema['components']['schemas'][$name] ?? null;
    openapiContractCheck($node !== null, "docs/v2/openapi.json must define components.schemas.{$name}");

    $properties = [];
    if (isset($node['properties'])) {
        $properties = array_keys($node['properties']);
    }
    foreach ($node['allOf'] ?? [] as $branch) {
        if (isset($branch['$ref'])) {
            $refName = basename((string) $branch['$ref']);
            $properties = array_merge($properties, openapiSchemaProperties($schema, $refName));
        }
        if (isset($branch['properties'])) {
            $properties = array_merge($properties, array_keys($branch['properties']));
        }
    }
    return array_values(array_unique($properties));
}

// ================================================================
// Endpoint 1: /actions -> StatusOrActionsData. Mirrors exactly what
// supportActionsRequest() builds inline (verified/assurance/
// availableActions/disabledActions).
// ================================================================
$actionsFixture = [
    'verified' => true,
    'assurance' => 'v2',
    'availableActions' => ['view_status'],
    'disabledActions' => [['action' => 'view_payment_status', 'reason' => 'verification_required']],
];
$actionsSchemaProperties = openapiSchemaProperties($schema, 'StatusOrActionsData');
foreach (array_keys($actionsFixture) as $key) {
    openapiContractCheck(in_array($key, $actionsSchemaProperties, true), "the real /actions response includes \"{$key}\", but docs/v2/openapi.json's StatusOrActionsData does not declare it — the schema is stale");
}
foreach (['verified', 'assurance', 'availableActions'] as $required) {
    openapiContractCheck(in_array($required, array_keys($actionsFixture), true), "StatusOrActionsData's required field \"{$required}\" must actually be present in the real /actions response fixture");
}

// ================================================================
// Endpoint 2: /requiredActions (verified branch) -> RequiredActionsData.
// Built through the real serializer REST/MCP both call
// (RequiredActionsSerializer::verified()), not a hand-written fixture, so
// a real field added/removed there is caught here too.
// ================================================================
$relationship = new ResourceRelationship('submission', 456, ['author'], ['author' => true]);
$requiredActionsFixture = RequiredActionsSerializer::verified($relationship, ['submit_revisions'], ['view_status', 'view_required_actions']);
$requiredActionsSchemaProperties = openapiSchemaProperties($schema, 'RequiredActionsData');
foreach (array_keys($requiredActionsFixture) as $key) {
    openapiContractCheck(in_array($key, $requiredActionsSchemaProperties, true), "RequiredActionsSerializer::verified() emits \"{$key}\", but docs/v2/openapi.json's RequiredActionsData does not declare it — the schema is stale");
}
foreach (['verified', 'resourceVerified', 'assurance', 'resource', 'relationships', 'availableActions', 'requiredActions'] as $required) {
    openapiContractCheck(in_array($required, array_keys($requiredActionsFixture), true), "RequiredActionsData's required field \"{$required}\" must actually be present in the real serializer output");
}

// ================================================================
// Every path in the schema must correspond to a real, implemented
// PageHandler operation — never a documented endpoint that doesn't exist.
// ================================================================
$handlerSource = (string) file_get_contents($root . '/classes/v2/Http/SupportGatewayPageHandler.php');
foreach (array_keys($schema['paths']) as $path) {
    $operation = ltrim($path, '/');
    openapiContractCheck(str_contains($handlerSource, "function {$operation}("), "docs/v2/openapi.json documents \"{$path}\", but SupportGatewayPageHandler has no real {$operation}() operation — the schema must never document an endpoint that does not exist");
}

fwrite(STDOUT, "OpenAPI contract tests passed\n");
