<?php

declare(strict_types=1);

// ================================================================
// TST-020: real acceptance testing on ojs-demo.airixmedia.com found that
// this plugin's own admin settings modal silently rendered incomplete
// (missing the entire MCP configuration section) because
// ChatwootIntegrationBasePlugin::addChatwootWidget() — hooked into
// TemplateManager::fetch, which fires for *every* template render
// site-wide — called $request->getRequestedPage() unconditionally.
// That method only exists on PKPPageRouter; any component-routed
// request (any plugin's own AJAX settings modal, any grid cell render)
// uses a PKPComponentRouter instead, so the call fataled every time.
// Confirmed via the real Apache error log on the live container: the
// exact crash trace showed ChatwootSettingsForm::fetch() itself
// recursively triggering this same hook while rendering its own
// settingsForm.tpl, corrupting that very render. PKP's Hook::call()
// catches the fatal and logs it rather than aborting the whole request,
// which is exactly why nothing appeared as an error to the end user —
// only a real live admin-UI acceptance pass surfaced it.
//
// This test asserts, against the real source tree, that the
// excluded-pages check (the only reason getRequestedPage() is called
// here) is guarded behind a PKPPageRouter instanceof check.
// ================================================================

function tst020Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$source = (string) file_get_contents(dirname(__DIR__, 2) . '/ChatwootIntegrationBasePlugin.php');

$methodStart = strpos($source, 'public function addChatwootWidget(');
tst020Check($methodStart !== false, 'must be able to locate addChatwootWidget() for the source-level check below');

// The method body contains an embedded JS bootstrap script with its own
// `function boot(){...}` literal, so the boundary must match a real PHP
// method signature (leading indentation + visibility keyword), not any
// occurrence of the word "function".
$nextMethodStart = preg_match('/\n    (?:public|private|protected) function /', $source, $m, PREG_OFFSET_CAPTURE, $methodStart + 1)
    ? $m[0][1]
    : false;
$methodBody = $nextMethodStart !== false
    ? substr($source, $methodStart, $nextMethodStart - $methodStart)
    : substr($source, $methodStart);

tst020Check(
    (bool) preg_match('/getRouter\(\)\s+instanceof\s+\\\\?PKP\\\\core\\\\PKPPageRouter/', $methodBody),
    'addChatwootWidget() must guard getRequestedPage() behind a PKPPageRouter instanceof check — calling it unconditionally fatals under any component-routed request (confirmed live: this plugin\'s own settings-modal AJAX fetch)'
);

$getRequestedPageCalls = substr_count($methodBody, '->getRequestedPage()');
tst020Check($getRequestedPageCalls === 1, 'expected exactly one getRequestedPage() call site in addChatwootWidget() to guard');

$guardPos = strpos($methodBody, 'instanceof');
$callPos = strpos($methodBody, '->getRequestedPage()');
tst020Check($guardPos !== false && $callPos !== false && $guardPos < $callPos, 'the instanceof guard must appear before the getRequestedPage() call it protects');

fwrite(STDOUT, "PASS: tst-020-widget-hook-component-router-guard\n");
