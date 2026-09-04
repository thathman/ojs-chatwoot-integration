<?php

declare(strict_types=1);

// ================================================================
// TST-024: same class of defect as TST-020, a second real instance.
// Live-confirmed on dell (2026-09-04): once resolveReviewerMasking()
// became unconditional (owner directive item D — blind-review
// protection is no longer gated behind the removed enablePrivacyMode
// checkbox), any logged-in reviewer-role user crashed the plugin
// management grid with "An unexpected error has occurred" the moment
// TemplateManager::fetch hooked a component-routed (AJAX/grid) render.
// The real Apache error log showed CurrentSubmissionResolver::resolve()
// calling $request->getRequestedPage(), which exists on the Request
// class itself but delegates to its router — PKPComponentRouter has no
// such method, so method_exists($request, 'getRequestedPage') (always
// true regardless of router type) could never actually guard this.
// ================================================================

function tst024Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/classes/v2/Relationship/CurrentSubmissionResolver.php');

$methodStart = strpos($source, 'public function resolve(');
tst024Check($methodStart !== false, 'must be able to locate resolve() for the source-level check below');
$methodBody = substr($source, $methodStart);

tst024Check(
    !str_contains($methodBody, "method_exists(\$request, 'getRequestedPage')"),
    'resolve() must not rely on method_exists($request, ...) to guard a router-delegated method — it is always true regardless of the real router type and does not prevent the fatal'
);
tst024Check(
    (bool) preg_match('/getRouter\(\)\s+instanceof\s+\\\\?PKP\\\\core\\\\PKPPageRouter/', $methodBody),
    'resolve() must guard getRequestedPage()/getRequestedArgs() behind a real PKPPageRouter instanceof check on $request->getRouter()'
);

$guardPos = strpos($methodBody, 'instanceof');
$callPos = strpos($methodBody, '->getRequestedPage()');
tst024Check($guardPos !== false && $callPos !== false && $guardPos < $callPos, 'the instanceof guard must appear before the getRequestedPage() call it protects');

fwrite(STDOUT, "PASS: tst-024-current-submission-resolver-component-router-guard\n");
