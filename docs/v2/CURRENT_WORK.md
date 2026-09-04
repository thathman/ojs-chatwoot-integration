# CURRENT WORK — READ THIS FIRST

## OWNER HARD OVERRIDE — SETTINGS CONSOLE MUST FINISH BEFORE ANY OTHER WORK

The owner supplied real browser evidence on 2026-09-04 showing that the **effective OJS Chatwoot Integration settings page is still the legacy single-scroll experience** even though several new-console source slices have merged.

Therefore the Settings Console is **not accepted and is now the uninterrupted primary workstream**.

Read immediately:

`docs/v2/SETTINGS_CONSOLE_COMPLETION_DIRECTIVE.md`

Then read:

`docs/v2/SETTINGS_UI_REDESIGN.md`

### Non-stop execution rule

Continue through the remaining Settings Console sequence **B → C → D → E → F → G → H → I → J → K** until the entire effective browser experience is finished and real-browser accepted.

Do **not** stop after an individual slice, PR, merge, deployment, health check, or scheduled wakeup. Record the milestone and immediately continue to the next unfinished Settings Console item.

Do **not** return to unrelated HAR work, Knowledge expansion, Staff Plane, Provider SDK, Payment Portfolio, Product Bible backlog, release/publication work, or other adjacent tasks until the completion directive's Definition of Settings Console DONE is satisfied.

Only interrupt this sequence for a genuinely inseparable production-safety defect. Fix it, document it, and resume the console immediately.

### Current state at this redirect (updated 2026-09-04, post theme-root-cause fix)

- **Theme override root cause found and fixed.** The effective legacy page in the owner's screenshot was `/home/hendrix/ojs-fresh/plugins-src/ajdsiProduction/templates/plugins/generic/chatwootIntegration/templates/settingsForm.tpl` — a stale 223-line pre-console copy of this exact template shadowing the real 619-line console template via OJS/PKP's standard theme-template-override lookup. Not version-controlled (no `.git` in that theme directory — a plain filesystem override, confirmed via a `.bak` file already present there from an earlier manual edit). Moved aside (renamed `.stale-2026-09-04.bak`, not deleted) on dell; verified `ajdsiproduction` is the real active theme for context 1 via a live CLI-harness check. Smarty compile/opcode caches cleared and Apache reloaded. **The real console now renders live** — confirmed via a real logged-in browser session opening the Chatwoot Integration settings modal.
- B — Chatwoot tab: **partial, core discovery live-verified**. Test Connection & Discover, single-account auto-resolution ("Connected. Account: Airix Media"), and the Website Inbox selector by human name ("OJS Demo (AJDSI)") all confirmed working in the real browser against the real Chatwoot account. Website Token ↔ Inbox consistency check, resource-ownership completion, Chatwoot-owned vs OJS-owned status presentation, and `Open in Chatwoot` remain.
- C — Widget Appearance: **implementation shipped**. Structured controls + local preview shipped; real frontend-vs-preview browser acceptance remains under K.
- D — Audience/privacy: **shipped and live-verified**. See "Item D" section below.
- E through J: **must be completed next, in order**.
- K — real browser acceptance: the original screenshot's specific defects (legacy single-scroll layout, raw `##plugins...##` keys in the Overview health block) are now fixed and live-verified fixed; the console itself now renders. Full K checklist still open (see `docs/v2/SETTINGS_CONSOLE_COMPLETION_DIRECTIVE.md`).

### Item D — Audience/privacy (shipped, PR #257/#258/#259 — live-verified 2026-09-04)

- **Real security fix, found while building item D**: `enablePrivacyMode` used to gate reviewer-identity masking in both `addChatwootWidget()` and the `/bind` handshake behind an admin checkbox defaulting to `false` — a fresh install (or an admin who never found the checkbox) exposed real reviewer identity to Chatwoot by default. Masking is now unconditional in both call sites; the setting is removed from `SettingsRegistry` entirely (PR #257).
- Positive audience model: the Widget tab's negative `Hide for X` checkboxes replaced with "Who can see the support widget?" (8 positive role checkboxes) + a live "Currently visible to: ..." effective-audience summary. `ChatwootSettingsForm` inverts to/from the existing `hideForRole_*`/`hideForGuests` settings on load/save — no new setting key, no change to the runtime gate, no existing install's effective audience changes.
- "Blind-review protection: Always enforced" now shown as a frozen status banner, never an optional checkbox.
- **Two real regressions found and fixed live, both from this same change**, each its own PR: (1) PR #258 — `resolveReviewerMasking()` becoming unconditional exposed a pre-existing crash in `CurrentSubmissionResolver::resolve()`, which called `$request->getRequestedPage()` unconditionally; that method exists on `Request` but delegates to the router, and `PKPComponentRouter` (any AJAX/grid render) has no such method — crashed the plugin management grid for any reviewer-role user. (2) PR #259 — splitting the old combined `fbvFormSection` dropped `list=true` from the section still wrapping `enableWidget` alone, causing PKP's FormBuilderVocabulary to throw an uncaught fatal (silent HTTP 500, empty body, **no application log line at all** — had to temporarily enable `log_errors` via a reversible `.htaccess` override to capture the real stack trace) the moment the settings modal was opened.
- Live-verified end-to-end: opened the real settings modal as a real logged-in admin/reviewer user on dell, confirmed the Widget tab renders the always-on banner + all 8 positive checkboxes + effective-audience summary, and confirmed the summary updates live when a checkbox is toggled (reviewer unchecked → summary correctly dropped "Reviewers").
- HAR-006 real Author-A/Reviewer-B Dell fixture acceptance is still open — see PROACTIVE_HARDENING_AUDIT.md's HAR-006 entry.

### Evidence discipline reminder (reinforced this session)

A silent HTTP 500 with an empty body and zero log output is real production-safety FAIL evidence, even when nothing appears in `docker logs`. When a real-browser action fails with no visible cause, check the actual HTTP response status/body via the browser's own network tools before concluding "it must be fine" — and if logs are silent, a temporary, reversible `log_errors`/`error_log` override (removed immediately after capture) is a legitimate way to get a real stack trace rather than guessing.

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Evidence rule

Source/unit/structural evidence is not live acceptance. Any item that changes OJS runtime behavior, Chatwoot behavior, migrations, browser UI, scheduled delivery, or cross-journal privacy must be tested at the corresponding real evidence tier before being called complete.

The Settings Console workstream may end only when B–J are complete, K passes in the real browser, all K-discovered defects are fixed/retested, and documentation reflects the final state.

Until then:

> **DO NOT STOP THE SETTINGS CONSOLE WORKSTREAM. DO NOT SWITCH BACK TO UNRELATED BACKLOG WORK.**

## Required supporting documents

- `docs/v2/SETTINGS_CONSOLE_COMPLETION_DIRECTIVE.md`
- `docs/v2/SETTINGS_UI_REDESIGN.md`
- `docs/v2/PROACTIVE_HARDENING_AUDIT.md`
- `docs/v2/COMPLETION_RECONCILIATION.md`
- `docs/v2/SETTINGS_RECONCILIATION.md`
- `docs/v2/TASKLIST.md`
- `docs/v2/AIRIX360_TASKLIST.md`
- `docs/v2/ACCEPTANCE_TEST_MATRIX.md`
