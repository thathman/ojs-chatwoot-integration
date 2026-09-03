<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);

    function har007Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-007: `resolveWidgetContext()`'s ambiguous fallback,
     * `fallbackWidgetContextFromSettings()`, iterated every enabled
     * journal and returned the first one whose widget was configured
     * — a real risk of leaking Journal A's widget/identity into a
     * Journal B or site-level route with no real per-request context
     * evidence.
     *
     * Investigating it also found a real, currently-shipping bug: it
     * (and two other call sites in the same method) called
     * `Repo::context()`, which does not exist as a real method on
     * either `APP\facades\Repo` or `PKP\facades\Repo` (verified
     * against a real local checkout — grep for every real `public
     * static function` on both classes finds no `context()`). Calling
     * it always threw a real `\Error` at runtime; `PKP\plugins\Hook::run()`'s
     * own plugin-exception handling silently logged and swallowed it
     * (`error_log("Plugin ... failed to handle the hook ...")`),
     * so a page never visibly broke — the widget simply, silently,
     * never rendered whenever this path was reached, and every
     * production run of this code logged a real, avoidable PHP error.
     *
     * Both are fixed together here: the two path-based lookups now
     * call the real `Application::getContextDAO()->getByPath()` (the
     * same DAO pattern this codebase already uses elsewhere, e.g.
     * `FaqCacheSyncScheduledTask`), and the ambiguous fallback method
     * is removed entirely — resolveWidgetContext() now returns null
     * (no widget renders) rather than either crashing or guessing.
     * ================================================================
     */
    $source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");

    har007Check(!str_contains($source, 'fallbackWidgetContextFromSettings'), 'the ambiguous first-enabled-journal fallback method must be removed entirely, not merely renamed or left unreferenced');
    har007Check(!str_contains($source, 'Repo::context()'), 'Repo::context() must never appear — it is not a real method on APP\facades\Repo or PKP\facades\Repo and always throws at runtime');
    har007Check(substr_count($source, 'Application::getContextDAO()->getByPath(') === 2, 'both real path-based context lookups must use the real Application::getContextDAO()->getByPath()');

    $resolveStart = strpos($source, 'function resolveWidgetContext(');
    har007Check($resolveStart !== false, 'resolveWidgetContext() must exist');
    $resolveBody = substr($source, $resolveStart, (int) strpos($source, "\n    }\n", $resolveStart) - $resolveStart);
    har007Check(
        preg_match('/return null;\s*\n    \}/', substr($source, $resolveStart)) === 1,
        'resolveWidgetContext() must end by returning null when no real per-request context evidence exists — fail closed, never invent a journal'
    );

    fwrite(STDOUT, "HAR-007 widget context fail-closed tests passed\n");
}
