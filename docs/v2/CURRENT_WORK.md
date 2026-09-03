# CURRENT WORK — READ THIS FIRST

This file is the short authoritative continuation pointer for active v2 development. Read it before `TASKLIST.md`, `AIRIX360_TASKLIST.md`, or older phase summaries.

Last reconciled: 2026-09-03 (PR #209 resolved — ADM-008/ADM-009 tabbed-UI first slice complete).

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Immediate execution order

1. ~~Do not merge PR #196 as-is.~~ **Done.** All three blocking findings fixed (PR #196, then #198 for a live-discovered pagination gap) and merged into `v2-dev`. Live-verified on dell: `SupportGatewayMigrationRunner` upgraded the real 2.0.0.0 database in place (new `chatwoot_support_faq_cache` table, existing five tables untouched), and `syncFaqCache()` synced all 209 real approved FAQs from the real Chatwoot account (real account ID 2, resolved dynamically — not the naively-assumed 1) into it. See `docs/v2/TASKLIST.md` KNO-011/KNO-021/MIG-003 for full evidence. Not yet done: a browser check that the anonymous `/support-knowledge/` page actually renders these facts end-to-end.
2. **Settings Console Redesign — in progress.** `docs/v2/SETTINGS_UI_REDESIGN.md` is the authoritative product/UX brief. The canonical-settings slice (UX-024, ADM-007) is **complete**: `SettingsRegistry`/`SettingDefinition` (`classes/v2/Settings/`, PR #200) is the single source of truth for every one of the 39 real setting keys, and every previously-duplicated key list in the plugin now delegates directly to it (PRs #201/#204/#205/#207); HAR-008 closed as a side effect. The tabbed-UI first slice (ADM-008/ADM-009, PR #209) is also **complete**: a real WAI-ARIA tab layout (Overview/Chatwoot/Widget/Automation/AI & Knowledge/API & MCP/Advanced) replaces the old single-scroll form, tab membership driven directly by `SettingsRegistry`'s own `tab` field, the real duplicate-`id="description"` bug fixed, `alert()` replaced with inline status elements — `tests/v2/settings-form-tabs.php` is the drift guard. Deployed to dell; full browser acceptance remains blocked by the pre-existing AJDSI theme override of this exact template (see AUD-011/PR #195 — not a new limitation, not fixable from this repository). Next, in order: (a) close the cross-cutting HAR items below that intersect the settings surface, (b) the richer per-tab content ADM-008 still lacks (Chatwoot account/inbox/Captain discovery UI, structured widget preview, positive-audience-model controls, Integrations provider dashboard) — larger, separate slices — before returning to further Knowledge/Staff/Provider/Payment UI expansion.
3. While implementing the console, close the cross-cutting hardening items that directly affect the same surfaces — HAR-001, HAR-003, HAR-006, HAR-007, HAR-012, HAR-013 and HAR-017 remain open in `docs/v2/PROACTIVE_HARDENING_AUDIT.md` (HAR-008 closed above; HAR-018's `skipBackendPages` placebo-setting item closed by PR #211 — HAR-018's other listed items, e.g. `enablePrivacyMode`'s misleading label, remain open).
4. After the console foundation is live-accepted on Dell, return to the remaining Product Bible backlog in `COMPLETION_RECONCILIATION.md`, but treat the proactive hardening audit as a candidate gate: unresolved MUST-FIX items cannot be hidden by older checked boxes.

## Current-next rule

A large tasklist, old phase order, prior session prompt, checked checkbox, or useful adjacent PR does **not** override this file. If this file conflicts with an older roadmap ordering, this file wins until explicitly updated.

Only interrupt this order for a genuinely inseparable production-safety defect; document why.

## Evidence rule

Source/unit/structural evidence is not live acceptance. Any item that changes OJS runtime behavior, Chatwoot behavior, migrations, browser UI, scheduled delivery, or cross-journal privacy must be tested at the corresponding real evidence tier before being called complete.

## Required supporting documents

- `docs/v2/SETTINGS_UI_REDESIGN.md`
- `docs/v2/PROACTIVE_HARDENING_AUDIT.md`
- `docs/v2/COMPLETION_RECONCILIATION.md`
- `docs/v2/SETTINGS_RECONCILIATION.md`
- `docs/v2/TASKLIST.md`
- `docs/v2/AIRIX360_TASKLIST.md`
- `docs/v2/ACCEPTANCE_TEST_MATRIX.md`
