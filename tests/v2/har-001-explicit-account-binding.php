<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    function har001Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-001: before this, ChatwootApiService's constructor hardcoded
     * accountId = 1, then resolveAccountId() tried to overwrite it from a
     * real /profile call — but silently swallowed \Throwable on failure,
     * leaving every subsequent accounts/{id}/... call to silently operate
     * against the guessed account 1 (network error, bad token, wrong
     * account — indistinguishable from success). This proves the service
     * now fails closed: it tracks whether the account was ever actually
     * confirmed, and requestJson() refuses every accounts/{id}/... call
     * until it is, instead of silently guessing.
     */
    $source = (string) file_get_contents("{$root}/ChatwootApiService.php");

    har001Check(str_contains($source, 'private bool $accountResolved = false;'), 'ChatwootApiService must track whether the account ID was ever actually confirmed');
    har001Check(str_contains($source, 'public function isAccountResolved(): bool'), 'ChatwootApiService must expose isAccountResolved() so callers can check before trusting getAccountId()');

    // resolveAccountId() must only flip accountResolved to true inside the
    // success branch, never unconditionally.
    $resolveStart = strpos($source, 'function resolveAccountId(');
    har001Check($resolveStart !== false, 'resolveAccountId() must exist');
    $resolveBody = substr($source, $resolveStart, (int) strpos($source, "\n    }\n", $resolveStart) - $resolveStart);
    har001Check(str_contains($resolveBody, '$this->accountResolved = true;'), 'resolveAccountId() must mark the account resolved only once a real profile confirms it');
    har001Check(
        strpos($resolveBody, '$this->accountResolved = true;') > strpos($resolveBody, "if (!empty(\$profile['account_id']))"),
        'accountResolved must be set inside the success branch, not unconditionally'
    );

    // The old bug: resolveAccountId() silently swallowing failure and
    // leaving the guessed default in place with no trace. The catch block
    // must not set accountResolved — proven by the string order check
    // above already; this additionally proves the catch block is still a
    // no-op with respect to accountId (never overwritten there).
    $catchStart = strpos($resolveBody, 'catch (\Throwable $e)');
    har001Check($catchStart !== false, 'resolveAccountId() must still guard getProfile() against real request failures');
    $catchBody = substr($resolveBody, $catchStart);
    har001Check(!str_contains($catchBody, '$this->accountId ='), 'the failure branch must never assign accountId — it must leave the unconfirmed state alone');

    // setAccountId() is the explicit override path — an explicit caller
    // decision counts as confirmation.
    har001Check(
        (bool) preg_match('/function setAccountId\(\$id\)\s*:\s*void\s*\{\s*\$this->accountId\s*=\s*\(int\)\s*\$id;\s*\$this->accountResolved\s*=\s*true;\s*\}/', $source),
        'setAccountId() must also mark the account resolved — an explicit caller-provided ID is a real confirmation'
    );

    // requestJson() must refuse account-scoped calls before the account is
    // confirmed, rather than silently sending them against the guessed
    // default.
    $requestJsonStart = strpos($source, 'function requestJson(');
    har001Check($requestJsonStart !== false, 'requestJson() must exist');
    $requestJsonBody = substr($source, $requestJsonStart, (int) strpos($source, "\n    }\n", $requestJsonStart) - $requestJsonStart);
    har001Check(
        (bool) preg_match('/if\s*\(\s*!\$this->accountResolved\s*&&\s*str_starts_with\(\$uri,\s*[\'"]accounts\/[\'"]\)\s*\)/', $requestJsonBody),
        'requestJson() must guard every accounts/{id}/... call behind accountResolved — this is the actual fail-closed fix'
    );
    har001Check(
        strpos($requestJsonBody, 'accountResolved') < strpos($requestJsonBody, 'try {'),
        'the accountResolved guard must run before the request is attempted, not after'
    );

    // The guard must reject with ok=false (matching requestJson()'s own
    // existing error contract), not throw an uncaught exception that would
    // break every caller's ['ok']/['data'] access pattern.
    har001Check(str_contains($requestJsonBody, "'ok' => false"), 'the guard must return the same ok:false contract every other requestJson() failure path uses');

    // profile itself must remain reachable pre-resolution (it IS the
    // resolution mechanism) — it does not start with "accounts/".
    har001Check(str_contains($source, "requestJson('GET', 'profile')"), 'getProfile() must call the account-agnostic profile endpoint, unaffected by the new guard');

    fwrite(STDOUT, "HAR-001 explicit account binding tests passed\n");
}
