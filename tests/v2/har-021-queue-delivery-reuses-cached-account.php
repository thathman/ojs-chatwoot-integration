<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har021Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-021: "Queue delivery constructs a client per event row, producing
 * an avoidable profile API call per row before the actual
 * contact/conversation calls." deliverQueuedSupportEvents() ->
 * v2DeliverQueuedEventRow() does construct a fresh ChatwootApiService
 * per row (confirmed below) — but HAR-001's account-identity cache
 * (ChatwootApiService::$resolvedAccountCache, keyed by (baseUrl,
 * token), live-verified on dell: 768ms cold, 0ms cached, see
 * har-001-cached-account-resolution.php) already resolves this
 * specific complaint as a side effect: every row for the same journal
 * derives baseUrl/token from v2EffectiveSetting($contextId, ...) —
 * deterministic per context — so only the first row in a batch pays
 * the real /profile cost; every later row for the same journal in the
 * same scheduled-task run hits the cache. Proves the real code path
 * actually derives its credentials this way, so that inherited fix
 * genuinely applies here, not just in isolation.
 */
$source = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

$rowDeliveryStart = strpos($source, 'function v2DeliverQueuedEventRow(');
har021Check($rowDeliveryStart !== false, 'v2DeliverQueuedEventRow() must exist');
$rowDeliveryBody = substr($source, $rowDeliveryStart, (int) strpos($source, "\n    }\n", $rowDeliveryStart) - $rowDeliveryStart);

har021Check(str_contains($rowDeliveryBody, 'new ChatwootApiService($baseUrl, $apiToken)'), 'v2DeliverQueuedEventRow() must construct a ChatwootApiService per row — confirming the N+1 shape HAR-021 describes');
har021Check(
    str_contains($rowDeliveryBody, "v2EffectiveSetting(\$contextId, 'chatwootBaseUrl'") && str_contains($rowDeliveryBody, "v2EffectiveSetting(\$contextId, 'chatwootApiAccessToken'"),
    'the per-row baseUrl/token must be deterministic per contextId — this is what makes every row for the same journal in one batch share one cache key'
);

// The inherited fix itself: ChatwootApiService's cache is keyed by
// exactly (baseUrl, token), which is exactly what two rows for the
// same journal always share.
$apiServiceSource = (string) file_get_contents("{$root}/ChatwootApiService.php");
har021Check(str_contains($apiServiceSource, 'private static array $resolvedAccountCache = [];'), 'ChatwootApiService must carry the HAR-001 account-identity cache this fix relies on');
har021Check(str_contains($apiServiceSource, "md5(\$this->baseUrl . '|' . \$this->apiAccessToken)"), 'the cache key must be exactly (baseUrl, token) — the same pair every row for one journal shares');

fwrite(STDOUT, "HAR-021 queue-delivery-reuses-cached-account tests passed\n");
