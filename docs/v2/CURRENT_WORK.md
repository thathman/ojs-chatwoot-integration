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

### Current state at this redirect

- B — Chatwoot tab: **partial**. Human account/Inbox/Captain discovery shipped, but Website Token ↔ Inbox consistency, resource-ownership completion, Chatwoot-owned vs OJS-owned status presentation, and `Open in Chatwoot` remain.
- C — Widget Appearance: **implementation shipped**. Structured controls + local preview shipped; real frontend-vs-preview browser acceptance remains under K.
- D — Audience/privacy: **next**. Positive audience model + blind-review always-on framing + real HAR-006 Author-A/Reviewer-B acceptance.
- E through J: **must be completed next, in order**.
- K — real browser acceptance: **mandatory and currently FAIL** based on the owner's screenshot.

### Screenshot FAIL evidence

The owner's screenshot visibly shows the effective page still contains:

- legacy single-scroll layout;
- untranslated `##plugins...##` keys;
- raw/manual Chatwoot numeric IDs;
- negative `Hide for ...` controls;
- optional `Enable Privacy Mode (Blind Review Protection)`;
- legacy retry controls;
- raw per-event JSON;
- raw Widget Settings JSON;
- CSP/lazy-load/route internals in the main workflow;
- old import/export/global-profile presentation.

This is real-browser **FAIL** evidence. HTTP 200 or “deployed healthy” does not override it.

### Theme override rule

A stale AJDSI theme override may explain the screenshot, but it is **not an acceptable stopping condition**. Trace the effective render path and resolve the owner-visible page in the correct owning repository/configuration using normal protected-branch workflow. The acceptance target is the **effective rendered OJS page**, not only this repository's `templates/settingsForm.tpl`.

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
