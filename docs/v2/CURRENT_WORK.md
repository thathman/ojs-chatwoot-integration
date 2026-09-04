# CURRENT WORK — READ THIS FIRST

This file is the short authoritative continuation pointer for active v2 development. Read it before `TASKLIST.md`, `AIRIX360_TASKLIST.md`, or older phase summaries.

Last reconciled: 2026-09-04 (PR #249 merged, deployed SHA `f10e1b6` confirmed on dell — console item B, Chatwoot tab discovery, shipped and live-verified; see the owner redirect section for the full B–K build sequence).

## Owner goal

Prepare the plugin completely. **Do not publish it.** Do not modify/replace the immutable `v2.0.0.0` release/tag/artifact and do not resume PKP Gallery work.

## Owner redirect (2026-09-04) — Settings Console is now the primary workstream

The owner explicitly redirected priority away from picking hardening (HAR-*) items opportunistically: proactive hardening has been productive but must not indefinitely postpone the actual Settings Console product UX. **Do not select HAR items merely because they are easy to close.** Only fix a hardening defect outside this list when it directly intersects the console surface being built or is a serious, standalone production-safety issue.

The ordered console build sequence, to be worked in order:

```
A. reconcile CURRENT_WORK           (this update)
B. Chatwoot tab — connection, account discovery/selection, Website Inbox selector,
   Captain Assistant selector, resource-ownership validation, Chatwoot-owned vs
   OJS-owned boundary (status + "Open in Chatwoot", never duplicate their config)
   **[account/inbox/Captain-assistant discovery shipped + live-verified, PR #249 —
   see below; Website-Token/inbox-relationship checks and the Chatwoot-owned-vs-
   OJS-owned status/"Open in Chatwoot" UI still open]**
C. Widget tab — structured Appearance controls (position/launcher/title/language/
   theme), local visual preview (no real iframe boot), widgetSettingsJson moved to
   Advanced/removed
D. Audience/privacy UX — positive audience model ("who can see the widget"),
   blind-review protection reframed as an always-on invariant, not a toggle
E. Automation/Event Bridge UI — single understandable event/action matrix, no raw
   JSON, inline customer-visible-message consent per row
F. Overview health — per-integration state (Healthy/Configured/Optional-Off/
   Not-configured/Never-checked/Stale/Degraded/Failed/Action-required), safe actions
G. Verification tab — finish HAR-014 with real OJS EmailTemplate lifecycle + Mailpit
   acceptance (PIN, link, malicious name, failure, expiry, wrong PIN, max attempts,
   resend, anti-enumeration, success)
H. API & MCP tab — credential lifecycle, capabilities, safe explanatory copy
I. Integrations tab — real installed sibling providers only, status + link out,
   never duplicate their config
J. Advanced cleanup — CSP, lazy-load, route exclusions, raw override, debug,
   export/import/global profile, queue tuning
K. Real browser acceptance — full checklist in PROACTIVE_HARDENING_AUDIT.md's
   Settings Console section once the AJDSI theme override (see item 1 below) is no
   longer shadowing the real template
```

Full detailed requirements for each slice (exact fields, validation rules, ownership boundary, blind-review framing, etc.) are in the owner's 2026-09-04 directive — treat it as the authoritative UX spec for B–J until superseded.

**Evidence discipline reminder** (owner-stated, now doubly authoritative): do not call something live-accepted because code exists on dell, the page returns 200, or no PHP error occurred. Real acceptance must discriminate the intended behavior — e.g. HAR-006 needs the actual author-on-A/reviewer-on-B case; HAR-003 needs a contact with multiple inbox conversations where the wrong inbox is present but not chosen; HAR-022 needs real duplicate-contact ambiguity; HAR-023 needs an actual rendered-page script/listener count (see that entry's own PR #245/#246 correction for what happens when this discipline slips).

## Immediate execution order

1. ~~Do not merge PR #196 as-is.~~ **Done.** All three blocking findings fixed (PR #196, then #198 for a live-discovered pagination gap) and merged into `v2-dev`. Live-verified on dell: `SupportGatewayMigrationRunner` upgraded the real 2.0.0.0 database in place (new `chatwoot_support_faq_cache` table, existing five tables untouched), and `syncFaqCache()` synced all 209 real approved FAQs from the real Chatwoot account (real account ID 2, resolved dynamically — not the naively-assumed 1) into it. See `docs/v2/TASKLIST.md` KNO-011/KNO-021/MIG-003 for full evidence. Not yet done: a browser check that the anonymous `/support-knowledge/` page actually renders these facts end-to-end.
2. **Settings Console Redesign — in progress.** `docs/v2/SETTINGS_UI_REDESIGN.md` is the authoritative product/UX brief. The canonical-settings slice (UX-024, ADM-007) is **complete**: `SettingsRegistry`/`SettingDefinition` (`classes/v2/Settings/`, PR #200) is the single source of truth for every one of the 39 real setting keys, and every previously-duplicated key list in the plugin now delegates directly to it (PRs #201/#204/#205/#207); HAR-008 closed as a side effect. The tabbed-UI first slice (ADM-008/ADM-009, PR #209) is also **complete**: a real WAI-ARIA tab layout (Overview/Chatwoot/Widget/Automation/AI & Knowledge/API & MCP/Advanced) replaces the old single-scroll form, tab membership driven directly by `SettingsRegistry`'s own `tab` field, the real duplicate-`id="description"` bug fixed, `alert()` replaced with inline status elements — `tests/v2/settings-form-tabs.php` is the drift guard. Deployed to dell; full browser acceptance remains blocked by the pre-existing AJDSI theme override of this exact template (see AUD-011/PR #195 — not a new limitation, not fixable from this repository). Next: follow the owner-redirect build sequence (B–K) above — the richer per-tab content ADM-008 still lacks (Chatwoot account/inbox/Captain discovery UI, structured widget preview, positive-audience-model controls, Integrations provider dashboard) is now the explicit primary workstream, not an optional follow-up.
3. Cross-cutting hardening items already closed or partially closed this far (fix one only when it directly intersects the console slice being built, or is a standalone serious production-safety issue — see the owner redirect above). Status per item — see `docs/v2/PROACTIVE_HARDENING_AUDIT.md` for full evidence on each:
   - HAR-002 — partial (PR #241): Captain custom-tool/scenario provisioning's dedup-before-create list checks now fail closed on a real request failure instead of risking a duplicate resource; `getCannedResponses()`/`getContactConversations()` and a general typed result/error type remain open, lower-risk items.
   - HAR-003 — closed (PR #219): event delivery no longer trusts `conversations[0]`; `selectConversationForInbox()` requires real `inbox_id` membership and fails closed otherwise.
   - HAR-006 — implementation fixed (PR #215), multi-role Dell acceptance still open: widget and bind share one `resolveReviewerMasking()` decision instead of two independently-maintained ones, but the discriminating real-user walkthrough (one real user, author on Submission A + reviewer on Submission B, proving widget and `/bind` agree on identity in both contexts) has not been run. Do this during the Widget/Audience console work per the owner's redirect below.
   - HAR-007 — closed (PR #213): also fixed a real, high-volume production bug — a nonexistent `Repo::context()` call was throwing 3,081 times/day in real dell traffic, now 0.
   - HAR-008 — closed (PRs #200/#201): `SettingsRegistry` is the single source of truth for global-eligibility, closing the credential-sharing gap as a side effect.
   - HAR-011 — closed (PR #239): every real call site that logged a raw exception message now uses `SafeExceptionMessage::describe()`; MCP's one remaining `getMessage()` call verified safe by construction.
   - HAR-012 — partial (PRs #223/#227/#231): all 8 event types confirmed v2-owned, both opportunistic queue drains removed (`ProcessLegacyRetryQueueScheduledTask` is the sole drain path), settings relabeled to clarify legacy-only scope; migrating Send Test Message/canned-response-sync to explicit v2 operations and retiring the legacy queue once no producer remains are the only items left open.
   - HAR-013 — partial (PR #225, drain item jointly with #227): `syncEmailTemplates()` hard-denies security/verification templates before ever calling `createCannedResponse()`, live-verified (6 of 112 real templates denied); the larger opt-in-feature-vs-remove-the-button product decision remains open.
   - HAR-014 — partial (PR #235): journal name now HTML-escaped in verification email bodies and CRLF-stripped in the subject, proven against real malicious-looking fixtures; EmailTemplate-lifecycle/localization/Mailpit-acceptance items remain open.
   - HAR-016 — partial (PR #237): proved idempotency for the additive migration architecture already shipped earlier this session (KNO-011/MIG-003, live-verified upgrading a real 2.0.0.0 database in place); only multi-DB-driver testing remains open.
   - HAR-017 — partial (PR #221): an unconfigured optional module no longer drags overall Support Gateway health to degraded; the fuller module-state model (last-verified-healthy vs stale vs failed, timestamps, reason codes) remains open.
   - HAR-018 — partial (PR #211): `skipBackendPages` placebo-setting item closed; other items remain open.
   - HAR-001 — partial (PRs #217/#229/#249): fails closed instead of silently guessing account 1, caches the resolved account per (baseUrl, token) (live-verified: 768ms cold, 0ms cached), and the new Chatwoot tab's discoverChatwootResources() surfaces human-readable account selection and scopes every Inbox/Captain resource call to the one explicit account (live-verified: 9/11 real inboxes correctly filtered to Website-type, 11 real Captain assistants returned with only safe id/name/description fields); a real multi-account acceptance test remains open (this session's token has only one account).
   - HAR-021 — partial (inherited from HAR-001's cache, PR #233): queue delivery's per-row client construction already skips the redundant `/profile` call after the first row per journal per run; constructors still hide network I/O on a cold cache, and per-object client reuse remain open.
   - HAR-022 — closed (PR #243): `findContactByEmail()` prefers a candidate matching the stable OJS identifier over the first email match; both real call sites now pass it.
   - HAR-023 — partial (PRs #245/#246): a request-scoped guard now caps the widget's own script/listener registration at once per request, closing that specific risk; PR #245's initial evidence claim (a live-observed duplicate) was overstated and corrected in #246 — the fix is preventive hardening against a real architectural risk, not a fix for an observed live bug. A real DOM/runtime test and the separate "component/fetch response pollution" item remain open.

   Every MUST-FIX item in `docs/v2/PROACTIVE_HARDENING_AUDIT.md` now has at least a real, evidenced, deployed first slice. None are fully closed to the audit's complete original scope. The next reasonable increments are: (a) any of the follow-up items noted above, each independently choosable; (b) the remaining fully-untouched audit entries (HAR-004/005/009/010/015/019/020/023/024/025 — read each before picking, several are larger architecture/product-decision items); (c) richer ADM-008 per-tab Settings Console content per item 2 above; (d) after live-accepting the console foundation on Dell, the remaining Product Bible backlog in `COMPLETION_RECONCILIATION.md`.
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
