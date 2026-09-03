# CURRENT WORK — READ THIS FIRST

This file is the short authoritative continuation pointer for active v2 development. Read it before `TASKLIST.md`, `AIRIX360_TASKLIST.md`, or older phase summaries.

Last reconciled: 2026-09-03.

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Immediate execution order

1. **Do not merge PR #196 as-is.** Its review comment records blocking findings: it continues Priority 2 despite the current redirect, adds a new FAQ table through the original 2.0 install migration, and its API failure semantics can clear the stale FAQ cache during an outage. Correct/park that work without losing it.
2. **Start the Settings Console Redesign now.** `docs/v2/SETTINGS_UI_REDESIGN.md` is the authoritative product/UX brief. Console foundation comes before further Knowledge/Staff/Provider/Payment UI expansion.
3. While implementing the console, close the cross-cutting hardening items that directly affect the same surfaces, especially HAR-001, HAR-003, HAR-006, HAR-007, HAR-008, HAR-012, HAR-013, HAR-017 and HAR-018 in `docs/v2/PROACTIVE_HARDENING_AUDIT.md`.
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
