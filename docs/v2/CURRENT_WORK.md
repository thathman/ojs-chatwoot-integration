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

### Major live-browser acceptance pass (2026-09-04, second session of the day)

A real, logged-in authenticated browser session became available (after an earlier session lost its login and could not re-authenticate — see the stale notes below, now superseded). Used it to run a genuine, comprehensive live-browser acceptance pass across the shipped console, and found + fixed one more real defect in the process:

- **All 8 console tabs confirmed rendering correctly** in the real browser: Overview, Chatwoot, Widget, Automation, Verification, AI & Knowledge, API & MCP, Integrations, Advanced (9 actually, Overview + 8 others).
- **Overview (item F)**: card grid renders with correct real states and colors; banner correctly showed "2 item(s) need attention: Captain, Event Bridge" against real degraded Captain/Event Bridge state. Clicked **Run Health Check** — confirmed the owner-review fix live: rendered exactly "Chatwoot widget service — Reachable / API access — Verified / Identity validation — Verified / Connection settings — Complete / Last checked — just now" — human sentences, zero raw JSON.
- **Automation (item E)**: all 8 real event rows rendered with correct real Enabled state (Review submitted correctly unchecked, matching real saved data) and Action selects. Set "Article published" to "Send a customer-visible message" — the inline per-row warning ("This sends an outgoing Chatwoot message the customer can read.") and the previously-hidden global consent checkbox both appeared correctly and instantly. Change discarded via Cancel (never saved).
- **Verification (item G)**: cards, methods list, frozen "What verification proves" invariant, Send Test Email, and a real working "Open Email Templates" link all confirmed rendering correctly.
- **Integrations (item I)**: table rendered exactly matching the CLI-harness data gathered earlier the same day — all 10 real sibling plugins, correct Enabled state, correct real version strings.
- **API & MCP (item H)**: "Authentication ≠ authorization" banner, Support API Token card correctly showing "Configured"/"Rotate" (not "Generate", since already configured), MCP Service Token card, and "Available tools (15)" with real tool names/descriptions from the catalog all confirmed.

**Real defect found and fixed during this pass**: clicking **Generate** on the MCP Service Token produced the correct one-time-reveal message ("New credential generated — copy it now...") and the credential was correctly persisted server-side (confirmed independently via CLI harness), but the visible token field itself stayed blank instead of showing the new value. Root cause: PKP's real `_smartyFBVTextInput()`/`_smartyFBVTextArea()` unconditionally call `uniqid()` and the real `textInput.tpl`/`textarea.tpl` append that as a random suffix to the field's rendered `id` (confirmed against the real deployed `lib/pkp` source on dell) — but never to its `name`. Every `$('#exactId')` jQuery selector in this template targeting a `type="text"`/`password=true`/`type="textarea"` fbvElement field was therefore silently matching nothing in the real page. Fixed (PR #272) by switching every such selector to `[name="..."]`: both service-credential fields, the Chatwoot Base URL/API Access Token fields read by Test Connection & Discover (previously silently falling back to already-saved settings instead of actually reading in-progress unsaved edits — a second real consequence of the same bug), the Import/Export textarea, and the Widget tab's Launcher Title field (whose change/keyup listener never fired at all, so the local preview never reflected typed text). Re-tested live after deploying the fix: clicking **Rotate** now correctly populates the field with a full row of masked dots. Deployed to dell (`eb746cd`, healthy, no errors).

**Continued the same pass after deploying the id-suffix fix (PR #272) and the discovery-timestamp fix (PR #274)**:
- **Chatwoot tab (item B)**: re-ran Test Connection & Discover fresh — "Connected." status, "Connected. Account: Airix Media — Last verified: <real fresh ISO timestamp>" (now correctly refreshing immediately, confirming PR #274's fix), Website Inbox still correctly showing "OJS Demo (AJDSI)" by human name.
- **Widget tab (item D/C)**: "Blind-review protection: Always enforced" banner and all 8 positive audience checkboxes confirmed rendering; "Currently visible to: ..." effective-audience summary correct. Switched Launcher style to "Expanded button" — the conditional "Button title" field correctly appeared; typed "Need Help?" into it and **the local preview bubble updated live to show "Need Help?"** — this is the exact real behavior PR #272's `[name="widgetLauncherTitle"]` fix restored (previously this field's own keyup listener never fired at all due to the id-suffix bug, so the preview never reflected typed text). Genuine, direct proof the fix works, not just a passing assertion.

All test changes made during this pass (Automation action override, Widget appearance overrides, MCP token rotation) were either cleanly discarded via Cancel or, for the one credential rotation, deliberately left in place (the new value is real and correctly persisted).

- **AI & Knowledge tab (item B remainder)**: Captain Assistant correctly selected by real human name ("Aluko", not an ID). Clicked **Sync/Repair Captain** — rendered exactly "Captain sync completed with issues. Knowledge document: already current. Custom Tools: 6 already current, 6 failed. Scenarios: 1 already current, 4 failed." — matching the owner-review doc's worked example verbatim, confirming that fix works live too, not just in the Overview tab's own actions.

**Still not exercised this pass** (real further defects may exist here, not yet checked): Website Token ↔ Inbox consistency validation, Chatwoot-owned-vs-OJS-owned status/"Open in Chatwoot" UI, real-frontend-vs-preview widget match on an actual public page, keyboard-only tab navigation, narrow/responsive layout, a duplicate-DOM-ID check across the whole rendered page at once, HAR-006's real Author-A/Reviewer-B fixture. Continue the K checklist from here next.

### Current state at this redirect (updated 2026-09-04, post theme-root-cause fix — see the live-browser acceptance pass above for the latest, more complete picture)

- **Theme override root cause found and fixed.** The effective legacy page in the owner's screenshot was `/home/hendrix/ojs-fresh/plugins-src/ajdsiProduction/templates/plugins/generic/chatwootIntegration/templates/settingsForm.tpl` — a stale 223-line pre-console copy of this exact template shadowing the real 619-line console template via OJS/PKP's standard theme-template-override lookup. Not version-controlled (no `.git` in that theme directory — a plain filesystem override, confirmed via a `.bak` file already present there from an earlier manual edit). Moved aside (renamed `.stale-2026-09-04.bak`, not deleted) on dell; verified `ajdsiproduction` is the real active theme for context 1 via a live CLI-harness check. Smarty compile/opcode caches cleared and Apache reloaded. **The real console now renders live** — confirmed via a real logged-in browser session opening the Chatwoot Integration settings modal.
- B — Chatwoot tab: **partial, core discovery live-verified**. Test Connection & Discover, single-account auto-resolution ("Connected. Account: Airix Media"), and the Website Inbox selector by human name ("OJS Demo (AJDSI)") all confirmed working in the real browser against the real Chatwoot account. Website Token ↔ Inbox consistency check, resource-ownership completion, Chatwoot-owned vs OJS-owned status presentation, and `Open in Chatwoot` remain.
- C — Widget Appearance: **implementation shipped**. Structured controls + local preview shipped; real frontend-vs-preview browser acceptance remains under K.
- D — Audience/privacy: **shipped and live-verified**. See "Item D" section below.
- E — Automation/Event Bridge: **shipped (PR #261), partially live-verified**. Deployed to dell (`ce54763`, healthy, no errors). A real, previously-undiscovered defect was found and fixed: none of the v2 event adapters ever consulted the eventXxx enable settings, so those checkboxes had zero effect on real delivery on any v2-owned install (true for all 8 event types today per HAR-012) — `v2EnqueueEvent()` now gates on the real per-event enable setting before enqueueing. Extensive structural test coverage (`tests/v2/settings-automation-event-matrix.php`). **Live-browser confirmation of the rendered matrix itself is incomplete**: the authenticated browser session was lost mid-verification (this session has no admin credentials to re-authenticate — do not attempt to guess/enter credentials; use a real logged-in session next time). Re-verify the Automation tab in a real browser before calling item E's UI fully done — confirm all 8 rows render (including "Review submitted"), the Action selects/inline consent warning work, and a saved Enabled=unchecked row actually stops that event from being enqueued (check the real event queue table after triggering that event).
- F — Overview/Health: **shipped (PR #264), structurally tested, live-browser confirmation pending**. Real card-grid dashboard (`OverviewCardStates`, fully unit-tested — never conflates configured with healthy or optional/off with degraded) replacing the flat text-dump `<ul>`. Deployed to dell (`41719b4`, healthy, no errors). **Not yet re-verified in a live authenticated browser session** — this session's browser is stuck on the OJS login page with no working credentials to re-authenticate; do not attempt to guess/enter credentials, use a real logged-in session next time to confirm the card grid, the human banner sentence, and the owner-review fixes below actually render.
- **Owner browser review addressed same day (PR #264)**: three real defects found live-reviewing the shipped console, recorded in `docs/v2/SETTINGS_CONSOLE_OWNER_REVIEW_HEALTH_DISCOVERY.md` — (1) Health Check/Captain Sync/Retry Dead Letters were raw `JSON.stringify()` dumps, now human sentences; (2) Overview lacked one actionable summary sentence, now shows "N item(s) need attention: ..." or "No issues detected."; (3) **real behavior defect**: a successfully discovered Chatwoot account/Inbox/Captain Assistant regressed to "Not tested yet (ID X)" on modal reopen/page refresh because discovery only ever wrote into transient DOM state — fixed with real persisted, non-secret, non-exportable metadata settings (`chatwootAccountName`/`chatwootInboxName`/`chatwootCaptainAssistantName`/`chatwootDiscoveryVerifiedAt`) that round-trip through the normal save cycle. This reopens part of item B (durable resource identity) and item K's acceptance checklist — the owner's doc lists the exact save/reload/re-discovery acceptance sequence still needed in a real browser.
- G — Verification: **shipped and live-verified (PR #266)**. Real OJS EmailTemplate lifecycle for verification PIN/link mail (seeded via `AddVerificationEmailTemplatesMigration`, confirmed live-run on dell — 2 rows in `email_templates_default_data`), real locale-fallback via `getLocalizedData()`, strict allowlisted `{$var}` substitution (never Smarty/eval). **Real Mailpit acceptance done and live-verified**: two real emails sent through the exact production `compose()` → `Mail::send()` → real SMTP → real `ojs-fresh-mailpit-1` path; the malicious-journal-name fixture's raw MIME source (fetched via Mailpit's own API) proves the embedded CRLF + `Bcc: attacker@example.com` never became a real mail header, and the `<script>` tag is HTML-escaped in the body — genuine end-to-end proof, not just a PHP-level assertion. See HAR-014's entry in `PROACTIVE_HARDENING_AUDIT.md` for full detail. Deployed to dell (`a79a09e`). Still open: the fuller PIN/link/malicious-name/failure/expiry/wrong-PIN/max-attempts/resend/anti-enumeration/success acceptance matrix, which requires exercising the real rate-limited `/verify/request`/`/verify/confirm` endpoints with real Chatwoot account/contact/conversation IDs — not yet attempted. Verification tab UI also not yet live-browser-confirmed (same login-session constraint as items E/F).
- H — API & MCP: **shipped and live-verified (PR #268), plus one real UI bug found and fixed via live-browser testing (PR #272 — see the "Major live-browser acceptance pass" section above)**. Real Generate/Rotate for both plugin-owned service credentials (`ServiceCredentialGenerator`, 32-byte/64-hex random value). Explicit "Authentication ≠ authorization" explanatory copy. Real MCP tool catalog (`McpToolCatalog`, drift-guarded against the real 15 registered tools). Deployed to dell (`eb746cd`, healthy). Documented, not silently pretended done: no dual-token overlap/grace-period window on rotate — rotating immediately invalidates the previous value for any in-flight client.
- I — Integrations: **shipped and live-verified (PR #270)**. New Integrations tab, `IntegrationCatalog` (10 real, verified sibling plugins: Submission Fee, Request Waiver, Paystack, Flutterwave, Bachs, MultiPay, Required Submission Files, Contributor User Sync, Magic Login, Visibility Suite). Live-verified via CLI harness on dell: all 10 correctly report real installed/enabled state and real version strings (e.g. Submission Fee 1.4.0.1, Magic Login 2.0.0.0) pulled directly from each plugin's own metadata — no invented data. Never reads a sibling's own settings; status + link to the real journal Plugins page only. Deployed to dell (`145fafd`, healthy).
- J — Advanced: **already complete as a side effect of items C/E's earlier reorganization** — reviewed the full Advanced tab content: legacy retry queue (`retryQueueEnabled`/`maxRetryAttempts`/`eventSyncMode`, moved here in item E), raw `widgetSettingsJson` override (moved here in item C), CSP/lazy-load/route-exclusions (`cspSafeMode`/`lazyLoadWidget`/`lazyLoadTrigger`/`excludedPages`/`skipBackendPages`), debug/troubleshooting (`enableDebugMode`), and export/import/global-profile admin tools. Every item the owner's directive lists for J is already present and nowhere else in the normal setup flow. No new work needed; not yet live-browser-confirmed (same session constraint as other tabs).
- K — real browser acceptance: the original screenshot's specific defects (legacy single-scroll layout, raw `##plugins...##` keys in the Overview health block) are now fixed and live-verified fixed; the console itself now renders. Full K checklist still open, now including the owner-review discovery-persistence acceptance sequence above (see `docs/v2/SETTINGS_CONSOLE_COMPLETION_DIRECTIVE.md` and `docs/v2/SETTINGS_CONSOLE_OWNER_REVIEW_HEALTH_DISCOVERY.md`).

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
