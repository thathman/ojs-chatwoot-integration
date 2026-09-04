<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har001CacheCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-001/HAR-021: every ChatwootApiService construction used to make
 * its own hidden `/profile` network call to resolve the account — a
 * real request that constructs this service more than once for the
 * same credentials (a nested call, or any future call site sharing
 * one request) paid for that resolution again each time. Guzzle is
 * not available in this local test harness (ChatwootApiService's real
 * behavior is live-verified via the CLI harness on dell, same pattern
 * as HAR-001's original fail-closed fix), so this proves the cache's
 * real wiring at the source level: a static per-(baseUrl,token) cache
 * consulted before any network attempt, populated only on a real
 * confirmed resolution, never on failure.
 */
$source = (string) file_get_contents("{$root}/ChatwootApiService.php");

har001CacheCheck(str_contains($source, 'private static array $resolvedAccountCache = [];'), 'ChatwootApiService must declare a static cache shared across constructions for the lifetime of the process/request');

$resolveStart = strpos($source, 'function resolveAccountId(');
har001CacheCheck($resolveStart !== false, 'resolveAccountId() must exist');
$resolveBody = substr($source, $resolveStart, (int) strpos($source, "\n    }\n", $resolveStart) - $resolveStart);

har001CacheCheck(
    str_contains($resolveBody, "md5(\$this->baseUrl . '|' . \$this->apiAccessToken)"),
    'the cache key must incorporate both the base URL and the token — two different Chatwoot accounts/instances must never share a cache entry'
);

$cacheReadPos = strpos($resolveBody, 'isset(self::$resolvedAccountCache[$cacheKey])');
$getProfileCallPos = strpos($resolveBody, '$this->getProfile()');
har001CacheCheck($cacheReadPos !== false && $getProfileCallPos !== false && $cacheReadPos < $getProfileCallPos, 'the cache must be consulted before getProfile() is ever called — a cache hit must skip the real network request entirely, not just skip acting on its result');

$cacheReturnPos = strpos($resolveBody, 'return;', $cacheReadPos);
har001CacheCheck($cacheReturnPos !== false && $cacheReturnPos < $getProfileCallPos, 'a cache hit must return immediately, never falling through to also perform the real network call');

// A resolution failure must never be cached — a transient outage must
// not lock every later construction in the same request/process into
// the fail-closed state once Chatwoot recovers.
$catchStart = strpos($resolveBody, 'catch (\Throwable $e)');
har001CacheCheck($catchStart !== false, 'resolveAccountId() must still guard getProfile() against real request failures');
$catchBody = substr($resolveBody, $catchStart);
har001CacheCheck(!str_contains($catchBody, 'resolvedAccountCache'), 'a failed resolution must never populate the cache — only a real confirmed account ID may be cached');

// The cache write itself must only happen inside the real success
// branch (account_id present), never unconditionally after the call.
$successBranchPos = strpos($resolveBody, "if (!empty(\$profile['account_id']))");
$cacheWritePos = strpos($resolveBody, 'self::$resolvedAccountCache[$cacheKey] = $this->accountId;');
har001CacheCheck($successBranchPos !== false && $cacheWritePos !== false && $cacheWritePos > $successBranchPos, 'the cache must only be written inside the real success branch, never unconditionally');

fwrite(STDOUT, "HAR-001 cached-account-resolution tests passed\n");
