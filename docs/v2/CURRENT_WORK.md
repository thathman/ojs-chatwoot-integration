# CURRENT WORK — READ THIS FIRST

This file is the short authoritative continuation pointer for active v2 development. Read it before `TASKLIST.md`, `AIRIX360_TASKLIST.md`, or older phase summaries.

Last reconciled: 2026-09-03 (PR #196/#198 resolved and live-verified).

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Immediate execution order

1. ~~Do not merge PR #196 as-is.~~ **Done.** All three blocking findings fixed (PR #196, then #198 for a live-discovered pagination gap) and merged into `v2-dev`. Live-verified on dell: `SupportGatewayMigrationRunner` upgraded the real 2.0.0.0 database in place (new `chatwoot_support_faq_cache` table, existing five tables untouched), and `syncFaqCache()` synced all 209 real approved FAQs from the real Chatwoot account (real account ID 2, resolved dynamically — not the naively-assumed 1) into it. See `docs/v2/TASKLIST.md` KNO-011/KNO-021/MIG-003 for full evidence. Not yet done: a browser check that the anonymous `/support-knowledge/` page actually renders these facts end-to-end.
2. **Settings Console Redesign — in progress.** `docs/v2/SETTINGS_UI_REDESIGN.md` is the authoritative product/UX brief. Started with the canonical settings foundation (UX-024): PR #200 added `SettingsRegistry`/`SettingDefinition` (`classes/v2/Settings/`) plus an automated drift guard (`tests/v2/settings-registry.php`) proving it agrees with every pre-existing key list; PR #201 used it to fix HAR-008 for real (global-profile fallback no longer copies `chatwootApiAccessToken`/`chatwootIdentityValidationSecret`/`chatwootSupportApiToken` across journals) and migrated `guessSettingType()` to the registry. Both merged and deployed to dell. Still open before the console foundation itself: migrate the remaining consumers (`ChatwootIntegrationV2Plugin::LEGACY_EXPORT_KEYS`, `ChatwootSettingsForm`'s three own key lists) to the registry, then build the actual tabbed UI (Overview/Chatwoot/Widget/Automation/AI & Knowledge/Verification/API & MCP/Integrations/Advanced). Console foundation comes before further Knowledge/Staff/Provider/Payment UI expansion.
3. While implementing the console, close the cross-cutting hardening items that directly affect the same surfaces — HAR-001, HAR-003, HAR-006, HAR-007, HAR-012, HAR-013, HAR-017 and HAR-018 remain open in `docs/v2/PROACTIVE_HARDENING_AUDIT.md` (HAR-008 closed, see above).
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
