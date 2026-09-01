<?php

declare(strict_types=1);

// ================================================================
// TST-018: real acceptance testing on ojs-demo.airixmedia.com found that
// Ojs35CompatibilityAdapter::getUserByEmail() and getUserById() both
// called `\PKP\user\Repo`, a class that has never existed in OJS 3.5 —
// the real class every core OJS file uses (verified directly against
// pkp-lib's own lib/pkp/pages/login/LoginHandler.php, which imports
// `use APP\facades\Repo;`) is `\APP\facades\Repo`. `\PKP\user\Repository`
// is the underlying repository class, but the facade that exposes
// `::user()` lives only under `APP\facades`, added by the app-level
// `classes/facades/Repo.php`. Because every existing unit test for this
// adapter defined its own fake `\PKP\user\Repo` class matching the same
// wrong namespace, the bug was internally consistent and invisible to
// the whole test suite — this is why a real acceptance pass against a
// live OJS 3.5.0.5 instance was needed to find it. Confirmed live: a
// correctly-authenticated support-session identity refresh
// (`ContextResolver::resolveContextForUser` -> `getUserById`) and a real
// verification-PIN request for a real, enabled OJS user
// (`getUserByEmail`) both silently failed in production before this fix.
//
// This test asserts, against the real source tree (not a mock), that the
// adapter never references the nonexistent class again.
// ================================================================

function tst018Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/v2/Compatibility/Ojs35CompatibilityAdapter.php');

tst018Check(
    !str_contains($source, 'PKP\\user\\Repo'),
    'Ojs35CompatibilityAdapter must never reference the nonexistent \PKP\user\Repo class'
);
tst018Check(
    substr_count($source, '\\APP\\facades\\Repo') >= 4,
    'getUserById() and getUserByEmail() must both use the real \APP\facades\Repo class (class_exists check + static call, x2 methods = 4 occurrences)'
);

fwrite(STDOUT, "PASS: tst-018-real-repo-facade-class\n");
