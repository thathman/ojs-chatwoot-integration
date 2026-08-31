<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ResponseTimingNormalizer;

function responseTimingNormalizerCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// IDN-015: ResponseTimingNormalizer must pad a fast branch up to the
// floor, and must never pad (or shorten) an already-slow branch — a
// sleeper is injected so this test needs no real wall-clock waiting.
// ================================================================

$slept = null;
$fastStart = microtime(true) - 0.01; // pretend 10ms already elapsed
ResponseTimingNormalizer::normalize($fastStart, 0.3, function (float $seconds) use (&$slept): void {
    $slept = $seconds;
});
responseTimingNormalizerCheck($slept !== null, 'a fast branch under the floor must invoke the sleeper');
responseTimingNormalizerCheck($slept > 0.2 && $slept < 0.3, 'the sleeper must be asked to wait roughly (floor - elapsed), not the full floor or zero');

$slept = null;
$slowStart = microtime(true) - 0.5; // pretend 500ms already elapsed, already over a 0.3s floor
ResponseTimingNormalizer::normalize($slowStart, 0.3, function (float $seconds) use (&$slept): void {
    $slept = $seconds;
});
responseTimingNormalizerCheck($slept === null, 'a branch that already exceeded the floor must never be padded further (never make a slow real response look even slower, which would itself be distinguishable)');

// Default sleeper (no injected callable) must not throw and must be
// callable — exercised with a near-zero floor so the real test run isn't
// slowed down.
$threw = false;
try {
    ResponseTimingNormalizer::normalize(microtime(true), 0.0);
} catch (\Throwable $e) {
    $threw = true;
}
responseTimingNormalizerCheck(!$threw, 'the real default sleeper path must not throw');

// ================================================================
// Wiring: supportVerificationRequestRequest() must call normalize()
// unconditionally, after the audit call and before the response is sent —
// regardless of which internal branch (user not found / found / mail
// sent / exception) ran.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function supportVerificationRequestRequest');
responseTimingNormalizerCheck($methodStart !== false, 'supportVerificationRequestRequest() must exist');
$nextMethodStart = strpos($pluginSource, "\n    public function", $methodStart + 1);
$methodBody = $nextMethodStart !== false ? substr($pluginSource, $methodStart, $nextMethodStart - $methodStart) : substr($pluginSource, $methodStart);

responseTimingNormalizerCheck(str_contains($methodBody, 'ResponseTimingNormalizer::normalize('), 'the verification-request endpoint must call the timing normalizer');
responseTimingNormalizerCheck(
    (bool) preg_match('/v2AuditVerificationEvent\([^;]*\);\s*\n\s*ResponseTimingNormalizer::normalize\(/', $methodBody),
    'the normalizer must run after the audit call, unconditionally on every path, right before the response is sent'
);
responseTimingNormalizerCheck(
    strpos($methodBody, 'ResponseTimingNormalizer::normalize(') > strpos($methodBody, 'catch (\Throwable $e)'),
    'the normalizer call must be outside/after the try/catch, so an exception path is normalized exactly like every other path'
);

fwrite(STDOUT, "Response timing normalizer tests passed\n");
