# SETTINGS CONSOLE COMPLETION DIRECTIVE — DO NOT STOP MID-REDESIGN

Owner directive: 2026-09-04.

This document is an execution override for the current Settings Console workstream. It supplements `CURRENT_WORK.md` and `SETTINGS_UI_REDESIGN.md` and wins over older phase/backlog ordering for what to do next.

## Owner requirement

**Finish the Settings Console completely before returning to unrelated HAR items, Knowledge expansion, Staff Plane, Provider SDK, Payment Portfolio, publication work, or any other backlog stream. Do not stop after individual console slices.**

Continue autonomously through implementation → PR → CI → merge → Dell deployment → real browser acceptance → defect repair → next console slice until the entire console is done.

Routine milestones such as “B complete”, “C complete”, “deployed healthy”, or “wakeup scheduled” are not stopping points. Record them and continue immediately to the next unfinished console item.

Only interrupt the console sequence for a genuinely inseparable production-safety defect. Fix it, document it, and resume the console immediately.

## Screenshot evidence — current effective page is NOT accepted

The owner supplied a real browser screenshot on 2026-09-04 showing the effective OJS Chatwoot Integration settings page still rendering the old/legacy experience.

The screenshot visibly contains, among other things:

- one long single-scroll form instead of the finished console experience;
- untranslated locale keys such as `##plugins.generic.chatwootIntegration.settings.section.health##`;
- raw/manual Website Inbox and Captain Assistant numeric values;
- negative audience controls (`Hide for Guests`, `Hide for Authors`, `Hide for Reviewers`, etc.);
- `Enable Privacy Mode (Blind Review Protection)` presented as an optional checkbox;
- legacy retry queue fields in the main workflow;
- raw per-event override JSON;
- raw Widget Settings JSON;
- technical route/CSP/lazy-load controls mixed into the normal workflow;
- old global-profile/import/export controls exposed in the primary form;
- missing polished per-tab information architecture/tooltips/help/status presentation.

This screenshot is **FAIL evidence for Settings Console real-browser acceptance**.

Do not call the Settings Console live-accepted merely because the plugin source contains tabs or because the Dell request returns HTTP 200.

The final effective rendered page must visibly be the new console.

## Theme override is not an acceptable stopping condition

The AJDSI theme has historically overridden `settingsForm.tpl`.

That may explain why current plugin-source UI is not what the owner sees, but it does not complete the task.

Trace the effective template/render path and resolve the real browser output in the correct owning codebase/configuration.

If the active theme override is maintained in another owner-controlled Airix repository, update/synchronize/remove that stale override through that repository's normal protected-branch workflow. Do not make an unsafe blind edit, but do not leave the Settings Console unfinished merely because the stale override lives outside this plugin repository.

The acceptance target is the **effective rendered OJS settings page**, not only `templates/settingsForm.tpl` in this repository.

## Uninterrupted console sequence

Work these to completion without switching away:

### B — Chatwoot
Finish all remaining B items, not only discovery:

- connection state and safe Test Connection UX;
- explicit account resolution/selection;
- Website Inbox selector by human name;
- Website Inbox type/account ownership validation;
- Website Token ↔ selected Inbox relationship/consistency validation;
- Captain Assistant selector by human name;
- Captain/account resource ownership validation;
- Chatwoot-owned vs OJS-owned configuration boundary;
- useful read-only Chatwoot state;
- `Open in Chatwoot` actions where appropriate;
- no raw numeric ID as the primary workflow.

### C — Widget
Preserve the shipped structured Appearance controls and local preview, then finish acceptance:

- no normal raw JSON requirement;
- structured position/style/title/language/theme and other verified SDK controls;
- local preview;
- real frontend widget must match saved structured controls;
- raw JSON, if retained, Advanced-only and validated.

### D — Audience / Privacy

- replace negative `Hide for ...` UX with positive **Who can see the support widget?** controls;
- migrate/translate legacy storage safely;
- show effective audience;
- blind-review protection must be presented as an always-on security invariant, not an optional privacy checkbox;
- optional context sharing, if any, must be separate from reviewer safety;
- complete the real HAR-006 discriminating scenario: same real user = Author on Submission A and assigned Reviewer on Submission B; widget and `/bind` must agree in both contexts, with reviewer masking only where appropriate.

### E — Automation / Event Bridge

- one understandable event/action matrix;
- no raw per-event JSON in normal UI;
- no visible v1/v2 implementation terminology;
- legacy queue controls removed from normal current-event UX;
- per-row customer-visible-message warning and explicit consent;
- saved choices must change real Event Bridge behavior.

### F — Overview / Health

Build an actionable dashboard, not a text dump.

Show human states for at least:

- Chatwoot connection/account/inbox;
- Widget;
- identity/HMAC;
- Captain;
- Knowledge;
- Event Bridge/queue;
- mail/verification;
- Support API;
- MCP;
- integrations/providers.

Distinguish `Healthy`, `Configured`, `Optional / Off`, `Not configured`, `Never checked`, `Stale`, `Degraded`, `Failed`, and `Action required` where meaningful.

No raw health JSON as the primary UX.

### G — Verification

Add a dedicated Verification tab/workspace and finish HAR-014:

- real OJS 3.5 EmailTemplate lifecycle for PIN and secure link;
- localization/fallback;
- strict variable allowlist;
- HTML/header safety;
- Mailpit real acceptance;
- test PIN, link, malicious journal name, mail failure, expiry, wrong PIN, max attempts, resend, anti-enumeration, success;
- useful mail/template status and Send Test Email action.

### H — API & MCP

- clear explanation of purpose;
- endpoint/copy UX;
- credential configured state;
- secure generate/rotate workflow for plugin-owned credentials;
- never redisplay stored secret plaintext;
- capabilities/tools/resources summary;
- explicitly explain service authentication ≠ OJS end-user authorization.

### I — Integrations

Add a dedicated Integrations tab/dashboard using real installed sibling plugins only.

Show installed/enabled/compatible/healthy/degraded/capabilities/link-to-owner-settings without duplicating sibling credentials or business configuration.

### J — Advanced

Move technical/power-user items out of the normal setup flow:

- CSP/lazy load;
- route exclusions;
- raw widget override if retained;
- debug/troubleshooting;
- import/export;
- global profile;
- legacy/queue compatibility information while still needed.

Remove obsolete/placebo/dead controls rather than merely relabeling them.

### K — Real browser acceptance

This is mandatory before the Settings Console workstream can end.

The effective Dell browser page must prove:

- the new console is actually what OJS renders;
- no stale theme override is shadowing it;
- all intended tabs exist and open;
- no untranslated `##plugins...##` locale keys are visible;
- no duplicate DOM IDs;
- keyboard tab navigation works;
- narrow/responsive layout works;
- tooltips/helper text are usable;
- Chatwoot discovery/selectors work against the real account;
- saved Widget controls match the real frontend launcher;
- positive audience controls work;
- blind-review invariant is clear and enforced;
- HAR-006 multi-role scenario passes;
- Event matrix saves/reloads and affects real delivery behavior;
- customer-visible actions require contextual consent;
- Overview state is understandable/actionable;
- Verification + Mailpit acceptance passes;
- API/MCP credential UX works safely;
- Integrations shows only real provider state;
- Advanced contains the technical controls rather than the primary workflow;
- import/export/global-profile behavior remains safe;
- no secret is exposed;
- no raw JSON or numeric Chatwoot IDs are required for normal setup.

## Definition of Settings Console DONE

The Settings Console is not done because individual PRs B/C/D/etc. merged.

It is DONE only when:

1. B through J are implemented to the authoritative UX brief;
2. the effective real OJS browser renders the new console, not the legacy screenshot;
3. K passes with real Dell evidence;
4. all defects discovered during K are fixed and retested;
5. documentation/reconciliation reflects the actual final state.

Then — and only then — update `CURRENT_WORK.md` to release the builder back to the remaining Product Bible/hardening backlog.

Until that point:

> **DO NOT STOP THE SETTINGS CONSOLE WORKSTREAM. DO NOT SWITCH BACK TO UNRELATED BACKLOG WORK.**

Preparation only. Do not publish, mutate `v2.0.0.0`, or resume PKP Gallery work.