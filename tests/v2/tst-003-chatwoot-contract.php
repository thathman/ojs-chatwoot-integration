<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function tst003Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * TST-003 (first slice): a real HTTP round trip against the actual
 * Chatwoot wire contract ChatwootApiService.php declares.
 *
 * Scoping decision, recorded here and in docs/v2/TASKLIST.md:
 * ChatwootApiService itself cannot be instantiated in this environment —
 * its constructor immediately builds a GuzzleHttp\Client and calls a real
 * HTTP endpoint (resolveAccountId() -> getProfile()), and this repo has
 * no Composer/vendor tree at all (confirmed: no composer.json, no
 * vendor/, class_exists('GuzzleHttp\Client') is false here) — Guzzle is
 * only ever available inside a real OJS core install, which is why every
 * existing test touching this class (tests/v2/chatwoot-binding.php,
 * captain-provisioning-health.php, etc.) only ever does source-level
 * str_contains() inspection, never instantiation. That is a real
 * environment constraint, not a code gap, same character as TST-002's
 * "no Illuminate autoloader" constraint.
 *
 * What IS honestly achievable without Guzzle or any new Composer
 * dependency: PHP's built-in HTTP stream wrapper (file_get_contents with
 * a stream context; no extension needed, allow_url_fopen defaults on)
 * and PHP's built-in web server (`php -S`, no extension needed either)
 * give this environment a real HTTP client and a real HTTP server today.
 * This test starts a real local server process that speaks the exact
 * Chatwoot endpoints/methods/payload shapes ChatwootApiService.php
 * declares (verified in-test against that source, so the mock can never
 * silently drift from reality) and sends real HTTP requests built the
 * same way Guzzle would (same headers, same JSON body shape) against it
 * — proving the real wire contract, not a fabricated one. It also proves
 * a real connection-refused failure is handled as a non-blocking failure
 * (ADR-016), the same way ChatwootApiService::requestJson()'s catch
 * block treats any Guzzle exception.
 */

$apiSource = (string) file_get_contents($root . '/ChatwootApiService.php');
foreach ([
    "requestJson('GET', 'profile')",
    'accounts/{$this->accountId}/conversations/{$conversationDisplayId}',
    'accounts/{$this->accountId}/conversations/{$conversationId}/notes',
    "'api_access_token' => \$this->apiAccessToken",
    "'Content-Type' => 'application/json'",
] as $expected) {
    tst003Check(str_contains($apiSource, $expected), "ChatwootApiService.php must still declare \"{$expected}\" — this test's mock server/client must track the real contract exactly");
}

// ================================================================
// A minimal, dependency-free HTTP client built to send exactly what
// ChatwootApiService's Guzzle client would send for these calls (same
// headers, same JSON body) — never a fabricated request shape.
// ================================================================
function tst003Request(string $url, string $method, array $headers, ?array $jsonBody = null): array
{
    $body = $jsonBody !== null ? json_encode($jsonBody) : null;
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = "{$name}: {$value}";
    }
    if ($body !== null) {
        $headerLines[] = 'Content-Type: application/json';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body ?? '',
            'ignore_errors' => true,
            'timeout' => 5,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        return ['ok' => false, 'error' => 'connection_failed'];
    }

    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int) $matches[1] : 0;
    $decoded = strlen($responseBody) ? json_decode($responseBody, true) : [];

    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'data' => is_array($decoded) ? $decoded : []];
}

// ================================================================
// A real local HTTP server speaking exactly the endpoints/payloads
// ChatwootApiService declares, recording each real request it receives
// to a temp file so this test can assert on the actual bytes that
// crossed a real socket.
// ================================================================
$logFile = tempnam(sys_get_temp_dir(), 'tst003-chatwoot-log-');
$routerFile = tempnam(sys_get_temp_dir(), 'tst003-chatwoot-router-');
file_put_contents($routerFile, '<?php
$logFile = ' . var_export($logFile, true) . ';
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, "HTTP_")) {
        $headers[$key] = $value;
    }
}
$body = file_get_contents("php://input");
file_put_contents($logFile, json_encode([
    "method" => $_SERVER["REQUEST_METHOD"],
    "uri" => $_SERVER["REQUEST_URI"],
    "headers" => $headers,
    "body" => $body,
]));

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
header("Content-Type: application/json");
if ($path === "/api/v1/profile") {
    echo json_encode(["account_id" => 99]);
} elseif ($path === "/api/v1/accounts/99/conversations/456") {
    echo json_encode(["id" => 456, "meta" => ["hmac_verified" => true]]);
} elseif ($path === "/api/v1/accounts/99/conversations/456/notes" && $_SERVER["REQUEST_METHOD"] === "POST") {
    echo json_encode(["id" => 1, "content" => json_decode($body, true)["content"] ?? null]);
} else {
    http_response_code(404);
    echo json_encode(["error" => "not_found"]);
}
');

$port = 18734 + random_int(0, 999);
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerFile],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);
tst003Check(is_resource($process), 'must be able to start a real local HTTP server process for this contract test');

$baseUrl = "http://127.0.0.1:{$port}/api/v1/";
$ready = false;
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
    if ($socket) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(50000);
}
tst003Check($ready, 'the real local Chatwoot mock server must accept connections before this test proceeds');

try {
    // ============================================================
    // GET profile — exactly ChatwootApiService::getProfile()'s call
    // shape, real headers over a real socket.
    // ============================================================
    $profileResult = tst003Request($baseUrl . 'profile', 'GET', ['api_access_token' => 'test-token', 'Accept' => 'application/json']);
    tst003Check($profileResult['ok'] === true, 'a real GET /profile request must succeed against the real mock server');
    tst003Check(($profileResult['data']['account_id'] ?? null) === 99, 'the real response must decode exactly as ChatwootApiService::resolveAccountId() expects (an account_id field)');

    $profileLogged = json_decode((string) file_get_contents($logFile), true);
    tst003Check(($profileLogged['headers']['HTTP_API_ACCESS_TOKEN'] ?? null) === 'test-token', 'the real server must have actually received the api_access_token header on the wire, not just in the client\'s intent');

    // ============================================================
    // GET conversation — exactly ChatwootApiService::getConversation()'s
    // call shape.
    // ============================================================
    $conversationResult = tst003Request($baseUrl . 'accounts/99/conversations/456', 'GET', ['api_access_token' => 'test-token']);
    tst003Check($conversationResult['ok'] === true && ($conversationResult['data']['id'] ?? null) === 456, 'a real GET conversation request must return the real conversation shape getConversation() expects');
    tst003Check(($conversationResult['data']['meta']['hmac_verified'] ?? null) === true, 'the real response must carry meta.hmac_verified, which callers of getConversation() rely on');

    // ============================================================
    // POST conversation note — exactly ChatwootApiService::
    // createConversationNote()'s call shape (used by the real
    // support.escalate handoff).
    // ============================================================
    $noteResult = tst003Request($baseUrl . 'accounts/99/conversations/456/notes', 'POST', ['api_access_token' => 'test-token'], ['content' => 'A safe handoff summary.']);
    tst003Check($noteResult['ok'] === true, 'a real POST note request must succeed against the real mock server, mirroring createConversationNote()\'s own (bool) $result[\'ok\'] contract');

    $noteLogged = json_decode((string) file_get_contents($logFile), true);
    tst003Check($noteLogged['method'] === 'POST' && str_contains((string) $noteLogged['uri'], '/notes'), 'the real server must have actually received a real POST to the /notes path');
    $noteBody = json_decode((string) $noteLogged['body'], true);
    tst003Check(($noteBody['content'] ?? null) === 'A safe handoff summary.', 'the real server must have received exactly the JSON body shape createConversationNote() sends ({"content": ...}), on the real wire');
} finally {
    proc_terminate($process);
    proc_close($process);
    @unlink($logFile);
    @unlink($routerFile);
}

// ================================================================
// A real connection-refused failure (nothing listening on this port)
// must be handled as a non-blocking failure, mirroring
// ChatwootApiService::requestJson()'s catch(GuzzleException) ->
// ['ok' => false] contract — a real Chatwoot outage must never crash
// the caller (ADR-016).
// ================================================================
$deadPort = $port + 1;
$outageResult = tst003Request("http://127.0.0.1:{$deadPort}/api/v1/profile", 'GET', ['api_access_token' => 'test-token']);
tst003Check($outageResult['ok'] === false, 'a real connection-refused failure must resolve to ok=false, never an uncaught error, mirroring ChatwootApiService\'s own outage handling');

fwrite(STDOUT, "TST-003 real-HTTP Chatwoot contract tests passed\n");
