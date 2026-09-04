<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function narrowLayoutCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Item K live-browser acceptance (2026-09-04): the Chrome extension's
 * resize_window tool did not actually change this tab's reported
 * window.innerWidth in this session (confirmed via javascript_tool
 * before and after two resize attempts — real tool limitation, not a
 * page bug), so "narrow/responsive layout works" could not be proven
 * with a real 420px-wide screenshot this pass. This is the structural
 * fallback: the two real multi-column tables in this console
 * (Automation's event matrix, Integrations' plugin status table) had
 * no horizontal-scroll container, so a genuinely narrow viewport could
 * force the whole settings modal to scroll sideways instead of just
 * the table. Both are now wrapped in .cwTableScroll (overflow-x:auto).
 */
$tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");

preg_match_all('/<table class="cwEventMatrix">/', $tpl, $tableMatches, PREG_OFFSET_CAPTURE);
narrowLayoutCheck(count($tableMatches[0]) === 2, 'expected exactly two cwEventMatrix tables (Automation event matrix, Integrations status table) — update this test if a third is added');

foreach ($tableMatches[0] as [$needle, $offset]) {
    $before = substr($tpl, max(0, $offset - 200), 200);
    narrowLayoutCheck(str_contains($before, '<div class="cwTableScroll">'), 'every cwEventMatrix table must be immediately preceded by an opening <div class="cwTableScroll"> wrapper so it scrolls horizontally instead of the whole settings modal on a narrow viewport');
}

narrowLayoutCheck(str_contains($tpl, '.cwTableScroll {') && str_contains($tpl, 'overflow-x: auto;'), 'cwTableScroll must declare overflow-x: auto');

fwrite(STDOUT, "Narrow-layout table-scroll tests passed\n");
