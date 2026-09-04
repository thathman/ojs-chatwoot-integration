# CURRENT WORK — READ THIS FIRST

This file is the short authoritative continuation pointer for active v2 development. Read it before `TASKLIST.md`, `AIRIX360_TASKLIST.md`, or older phase summaries.

Last reconciled: 2026-09-04 (PR #225 merged — HAR-013 partially closed, sensitive-template canned-response deny).

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Immediate execution order

1. ~~Do not merge PR #196 as-is.~~ **Done.** All three blocking findings fixed (PR #196, then #198 for a live-discovered pagination gap) and merged into `v2-dev`. Live-verified on dell: `SupportGatewayMigrationRunner` upgraded the real 2.0.0.0 database in place (new `chatwoot_support_faq_cache` table, existing five tables untouched), and `syncFaqCache()` synced all 209 real approved FAQs from the real Chatwoot account (real account ID 2, resolved dynamically — not the naively-assumed 1) into it. See `docs/v2/TASKLIST.md` KNO-011/KNO-021/MIG-003 for full evidence. Not yet done: a browser check that the anonymous `/support-knowledge/` page actually renders these facts end-to-end.
2. **Settings Console Redesign — in progress.** `docs/v2/SETTINGS_UI_REDESIGN.md` is the authoritative product/UX brief. The canonical-settings slice (UX-024, ADM-007) is **complete**: `SettingsRegistry`/`SettingDefinition` (`classes/v2/Settings/`, PR #200) is the single source of truth for every one of the 39 real setting keys, and every previously-duplicated key list in the plugin now delegates directly to it (PRs #201/#204/#205/#207); HAR-008 closed as a side effect. The tabbed-UI first slice (ADM-008/ADM-009, PR #209) is also **complete**: a real WAI-ARIA tab layout (Overview/Chatwoot/Widget/Automation/AI & Knowledge/API & MCP/Advanced) replaces the old single-scroll form, tab membership driven directly by `SettingsRegistry`'s own `tab` field, the real duplicate-`id="description"` bug fixed, `alert()` replaced with inline status elements — `tests/v2/settings-form-tabs.php` is the drift guard. Deployed to dell; full browser acceptance remains blocked by the pre-existing AJDSI theme override of this exact template (see AUD-011/PR #195 — not a new limitation, not fixable from this repository). Next, in order: (a) close the cross-cutting HAR items below that intersect the settings surface, (b) the richer per-tab content ADM-008 still lacks (Chatwoot account/inbox/Captain discovery UI, structured widget preview, positive-audience-model controls, Integrations provider dashboard) — larger, separate slices — before returning to further Knowledge/Staff/Provider/Payment UI expansion.
3. While implementing the console, close the cross-cutting hardening items that directly affect the same surfaces. HAR-008 closed above; HAR-018's `skipBackendPages` placebo-setting item closed by PR #211, other HAR-018 items remain open; HAR-007 closed by PR #213 — also fixed a real, high-volume production bug found along the way: a nonexistent `Repo::context()` call was throwing 3,081 times/day in real dell traffic, now 0; HAR-006 closed by PR #215 — widget and bind now share one `resolveReviewerMasking()` decision instead of two independently-maintained ones; HAR-001 partially closed by PR #217 — `ChatwootApiService` now fails closed instead of silently guessing account 1 when account resolution fails, live-verified against the real Chatwoot API on dell against both a bad token and the real production account; multi-account selection UX, Inbox/Captain resource-ownership validation, and hidden-constructor-I/O caching remain open, see the audit entry; HAR-003 closed by PR #219 — event delivery no longer trusts `conversations[0]`, a shared `selectConversationForInbox()` requires real `inbox_id` membership and fails closed otherwise; HAR-017 partially closed by PR #221 — an unconfigured optional module (Support API/MCP/verification) no longer drags overall Support Gateway health to degraded; the fuller module-state model (last-verified-healthy vs stale vs failed, timestamps, reason codes) remains open; HAR-012 partially closed by PR #223 — confirmed all 8 real event types are v2-owned (dispatchEvent()'s v1 event-handler call sites are now provably dead code), removed dispatchEvent()'s own opportunistic queue drain; migrating Send Test Message/canned-response-sync to explicit v2 operations and retiring the legacy queue settings/scheduled task remain open, larger follow-up work; **HAR-013 partially closed by PR #225** — `syncEmailTemplates()` now hard-denies security/verification templates (keyword-matched) before ever calling `createCannedResponse()`, live-verified against this real installation's actual templates (6 of 112 denied: MAGIC_LOGIN_LINK, PASSWORD_RESET_CONFIRM, REVIEWER_REGISTER, USER_REGISTER, USER_VALIDATE_CONTEXT, USER_VALIDATE_SITE); its own opportunistic queue drain and the larger opt-in-feature-vs-remove-the-button product decision remain open.

   Every MUST-FIX item in `docs/v2/PROACTIVE_HARDENING_AUDIT.md` now has at least a real, evidenced, deployed first slice (HAR-001/003/006/007/008/012/013/017/018 — several partial, see each entry for what remains). None are fully closed to the audit's complete original scope. The next reasonable increments are: (a) the remaining HAR-001/012/013/017 follow-up items noted above, each independently choosable; (b) richer ADM-008 per-tab Settings Console content per item 2 above; (c) after live-accepting the console foundation on Dell, the remaining Product Bible backlog in `COMPLETION_RECONCILIATION.md`.
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
