<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

    function settingsFormTabsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * ADM-008/ADM-009: the tabbed settings console's first slice. This
     * test is the automated drift guard the template's own docblock
     * promises — it fails the moment a setting's tab placement
     * disagrees with SettingsRegistry, a real field goes missing from
     * the template, or a structural regression (duplicate IDs, an
     * external CDN reference, a bare alert()) creeps back in. Real
     * live-browser acceptance of this UI remains blocked by the
     * pre-existing AJDSI theme override of this exact template (see
     * docs/v2/TASKLIST.md AUD-011/PR #195) — this is source/structural
     * evidence only until that is resolved.
     */
    $tpl = (string) file_get_contents("{$root}/templates/settingsForm.tpl");

    // ================================================================
    // Part 1: ADM-009 — no duplicate element IDs (the real bug that
    // motivated this item: six real id="description" occurrences).
    // ================================================================
    preg_match_all('/id="([a-zA-Z0-9_-]+)"/', $tpl, $idMatches);
    $idCounts = array_count_values($idMatches[1]);
    $duplicates = array_filter($idCounts, static fn ($count) => $count > 1);
    settingsFormTabsCheck($duplicates === [], 'template must never emit a duplicate element id, found: ' . implode(',', array_keys($duplicates)));

    // ================================================================
    // Part 2: real WAI-ARIA tab structure — a tablist with one real
    // tab/tabpanel pair per rendered tab, each panel but the first
    // hidden by default.
    // ================================================================
    $expectedTabs = ['overview', 'chatwoot', 'widget', 'automation', 'verification', 'aiKnowledge', 'apiMcp', 'advanced'];
    foreach ($expectedTabs as $tab) {
        settingsFormTabsCheck(str_contains($tpl, "id=\"cwTab-{$tab}\""), "tab button cwTab-{$tab} must exist");
        settingsFormTabsCheck(str_contains($tpl, "id=\"cwPanel-{$tab}\""), "tab panel cwPanel-{$tab} must exist");
        settingsFormTabsCheck(str_contains($tpl, "aria-controls=\"cwPanel-{$tab}\""), "cwTab-{$tab} must reference its panel via aria-controls");
        settingsFormTabsCheck(str_contains($tpl, "aria-labelledby=\"cwTab-{$tab}\""), "cwPanel-{$tab} must reference its tab via aria-labelledby");
    }
    // preg counts only real HTML attribute usage, never the CSS
    // attribute-selector form ([role="tab"]) used in the <style> block.
    settingsFormTabsCheck(preg_match_all('/(?<!\[)role="tab"/', $tpl) === count($expectedTabs), 'exactly one real role="tab" attribute per rendered tab — no orphaned/extra tab buttons');
    settingsFormTabsCheck(preg_match_all('/(?<!\[)role="tabpanel"/', $tpl) === count($expectedTabs), 'exactly one real role="tabpanel" attribute per rendered tab');
    settingsFormTabsCheck(str_contains($tpl, 'id="cwTab-overview" aria-controls="cwPanel-overview" aria-selected="true"'), 'the overview tab must be selected by default');
    foreach (array_slice($expectedTabs, 1) as $tab) {
        settingsFormTabsCheck(str_contains($tpl, "id=\"cwPanel-{$tab}\" aria-labelledby=\"cwTab-{$tab}\" hidden"), "cwPanel-{$tab} must start hidden — only the overview panel is visible by default");
    }

    // ================================================================
    // Part 3: every real SettingsRegistry key is rendered exactly once,
    // and lands inside the panel matching its own declared tab — the
    // real drift guard.
    // ================================================================
    $panelMap = [
        'chatwoot' => 'cwPanel-chatwoot',
        'widget' => 'cwPanel-widget',
        'automation' => 'cwPanel-automation',
        'ai_knowledge' => 'cwPanel-aiKnowledge',
        'api_mcp' => 'cwPanel-apiMcp',
        'advanced' => 'cwPanel-advanced',
    ];

    preg_match_all('/<div role="tabpanel" id="([a-zA-Z-]+)"[^>]*>(.*?)(?=<div role="tabpanel"|\{\/fbvFormArea\})/s', $tpl, $panelMatches, PREG_SET_ORDER);
    $panels = [];
    foreach ($panelMatches as $match) {
        $panels[$match[1]] = $match[2];
    }
    settingsFormTabsCheck(count($panels) === count($expectedTabs), 'must have parsed exactly one panel body per rendered tab');

    // Positive audience model (owner directive 2026-09-04, item D): these
    // 8 negative keys are no longer rendered directly — ChatwootSettingsForm
    // inverts each to/from its own audienceAllow_* checkbox (see its
    // AUDIENCE_ROLE_KEYS), which the template renders instead. Still real
    // SettingsRegistry keys (export/import/global-profile round-tripping
    // must keep working), just represented by a positive proxy field.
    $audienceProxyIds = [
        'hideForGuests' => 'audienceAllow_guest',
        'hideForRole_65536' => 'audienceAllow_author',
        'hideForRole_4096' => 'audienceAllow_reviewer',
        'hideForRole_1048576' => 'audienceAllow_reader',
        'hideForRole_16' => 'audienceAllow_manager',
        'hideForRole_17' => 'audienceAllow_subEditor',
        'hideForRole_4097' => 'audienceAllow_assistant',
        'hideForRole_1' => 'audienceAllow_siteAdmin',
    ];

    // Owner directive 2026-09-04, item E: eventDeliveryPerEventOverridesJson
    // is no longer a single directly-rendered field — it's computed from
    // 8 real eventAction_* selects in the event/action matrix (see
    // event-delivery-settings-resolver.php for the fuller assertion that
    // it's still genuinely read/written). Skipped here, not mapped to one
    // proxy id, since no single id stands in for it.
    $computedNotDirectlyRendered = ['eventDeliveryPerEventOverridesJson'];

    foreach (SettingsRegistry::all() as $key => $definition) {
        if (in_array($key, $computedNotDirectlyRendered, true)) {
            continue;
        }
        $expectedPanelId = $panelMap[$definition->tab] ?? null;
        settingsFormTabsCheck($expectedPanelId !== null, "SettingsRegistry declares an unmapped tab '{$definition->tab}' for '{$key}' — this test's panelMap must cover every real registry tab");
        settingsFormTabsCheck(isset($panels[$expectedPanelId]), "expected panel '{$expectedPanelId}' was not found in the template");
        $renderedId = $audienceProxyIds[$key] ?? $key;
        settingsFormTabsCheck(
            str_contains($panels[$expectedPanelId] ?? '', "id=\"{$renderedId}\""),
            "SettingsRegistry places '{$key}' on tab '{$definition->tab}', but the template does not render '{$renderedId}' inside {$expectedPanelId} — drift"
        );
    }

    settingsFormTabsCheck(substr_count($tpl, 'id="' . SettingsRegistry::keys()[0] . '"') === 1, 'a real registry key must be rendered exactly once, never duplicated across panels');

    // ================================================================
    // Part 4: HAR-018 (no placebo tabs) — every rendered tab except
    // Overview must contain at least one real SettingsRegistry field.
    // ================================================================
    foreach ($panelMap as $tabKey => $panelId) {
        $hasRealField = false;
        foreach (SettingsRegistry::all() as $key => $definition) {
            if ($definition->tab === $tabKey && str_contains($panels[$panelId] ?? '', "id=\"{$key}\"")) {
                $hasRealField = true;
                break;
            }
        }
        settingsFormTabsCheck($hasRealField, "tab panel {$panelId} must contain at least one real setting field — no empty placeholder tabs");
    }

    // ================================================================
    // Part 5: ADM-008 — no external CDN/font/icon references, no
    // bare alert() call for an action button's result (every button
    // must have its own adjacent status element).
    // ================================================================
    settingsFormTabsCheck(!preg_match('/https?:\/\//', $tpl), 'settingsForm.tpl must never reference an external CDN/URL — local/native OJS patterns only');
    $tplWithoutJsComments = preg_replace('#//[^\n]*#', '', $tpl);
    settingsFormTabsCheck(!preg_match('/\balert\(/', $tplWithoutJsComments), 'settingsForm.tpl must never call alert() directly for action-button results — use the shared cwShowStatus()/cwWireAction() helpers instead');

    $actionButtons = [
        'chatwootHealthCheckBtn', 'chatwootTestMessageBtn', 'chatwootExportBtn', 'chatwootImportBtn',
        'chatwootSaveGlobalBtn', 'chatwootApplyGlobalBtn', 'chatwootSyncCaptainBtn', 'chatwootSendMailTestBtn',
    ];
    foreach ($actionButtons as $btnId) {
        settingsFormTabsCheck(str_contains($tpl, "cwWireAction('{$btnId}'"), "{$btnId} must be wired through the shared cwWireAction() helper");
        settingsFormTabsCheck(str_contains($tpl, "id=\"{$btnId}Status\""), "{$btnId} must have its own adjacent status element ({$btnId}Status)");
    }

    fwrite(STDOUT, "Settings form tabs (ADM-008/ADM-009) tests passed\n");
}
