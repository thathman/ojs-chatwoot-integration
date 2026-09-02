<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function evt023Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** Extracts one method's body, bounded by the next method declaration. */
function extractMethodBodyEvt023(string $source, string $needle): string
{
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, 'function ', $start + strlen($needle));
    return $next !== false ? substr($source, $start, $next - $start) : substr($source, $start);
}

// ================================================================
// EVT-023 (CRITICAL, hostile completion audit): `RuntimeContextBridge`'s
// `$kernel` used to be populated ONLY as a side effect of calling
// `resolve($request, ...)` first. Every scheduled task
// (DeliverQueuedSupportEventsTask, confirmed live on dell — see
// docs/v2/TASKLIST.md EVT-023) builds a fresh bridge and calls a method
// like `loadSubmission()` directly, never through `resolve($request)`
// first — so `$kernel` stayed null forever and every method returned
// null/false/empty silently, regardless of how correct the rest of the
// pipeline was. This is the real reason no event type had ever actually
// delivered to Chatwoot before this fix.
//
// `OjsVersionResolver` is `final` and its `resolve()` depends on a live
// `DAORegistry`/`VersionDAO`, so a true end-to-end behavioral proof of
// this fix needs the real OJS runtime (same evidence-tier constraint as
// EVT-021/EVT-022) — that proof is the real dell acceptance run recorded
// in docs/v2/TASKLIST.md. This test proves the real source structure the
// fix depends on, so a future edit cannot silently reintroduce a bare
// kernel check on any method.
// ================================================================

$bridgeSource = (string) file_get_contents("{$root}/classes/v2/Runtime/RuntimeContextBridge.php");

evt023Check(
    str_contains($bridgeSource, 'private function ensureKernel(): bool'),
    'RuntimeContextBridge must declare a real ensureKernel(): bool helper'
);

$ensureKernelBody = extractMethodBodyEvt023($bridgeSource, 'private function ensureKernel(): bool');
evt023Check(
    str_contains($ensureKernelBody, 'SupportGatewayKernel::forOjsVersion'),
    'ensureKernel() must actually construct the real kernel via SupportGatewayKernel::forOjsVersion(), not merely check a flag'
);
evt023Check(
    str_contains($ensureKernelBody, '$this->versionResolver->resolve()'),
    'ensureKernel() must resolve the real installed OJS version itself, not assume resolve($request) already did'
);

// Exactly one bare "!$this->kernel" check may remain — ensureKernel()'s
// own internal construction guard (inside its "|| !$this->kernel)"
// condition). Every other gated method (34 of them as of this fix) must
// call ensureKernel() instead of checking $kernel directly. The
// docblock's own backtick-quoted mention of `!$this->kernel` is prose,
// not code, so it is excluded by only counting inside ensureKernel()'s
// own body plus a whole-file count that must match exactly.
$wholeFileBareGuards = substr_count($bridgeSource, '!$this->kernel)') + substr_count($bridgeSource, '!$this->kernel ');
evt023Check(
    $wholeFileBareGuards === 1,
    'exactly one bare "!$this->kernel" check may remain (ensureKernel()\'s own internal construction check) — every other gated method must call ensureKernel() instead, found ' . $wholeFileBareGuards
);
evt023Check(
    str_contains($ensureKernelBody, '!$this->kernel'),
    'the one remaining bare kernel check must be inside ensureKernel() itself, not somewhere else'
);

$ensureKernelCallSites = preg_match_all('/if \(!\$this->ensureKernel\(\)/', $bridgeSource);
evt023Check(
    $ensureKernelCallSites >= 30,
    'the large majority of the bridge\'s gated methods must call ensureKernel(), not a bare kernel check — found only ' . $ensureKernelCallSites . ' call sites, suggesting some methods were missed'
);

// resolve() itself must still exist and still ultimately populate
// $kernel via the same ensureKernel() path — its own request-driven
// behavior for real REST/MCP callers must be unchanged.
$resolveBody = extractMethodBodyEvt023($bridgeSource, 'public function resolve($request, string $locale');
evt023Check(
    str_contains($resolveBody, 'ensureKernel()'),
    'resolve($request) must itself go through ensureKernel() so both call paths share one real initialization path, never two independently-maintained copies'
);
evt023Check(
    str_contains($resolveBody, 'resolveContext($request, $locale)'),
    'resolve($request) must still pass the real $request through to resolveContext() once the kernel is ready — this part of its behavior must be unchanged by the fix'
);

fwrite(STDOUT, "PASS: evt-023-runtime-bridge-lazy-kernel-init\n");
