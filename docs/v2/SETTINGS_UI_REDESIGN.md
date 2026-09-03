# Settings Console Redesign — Current Execution Priority

Status: BUILD NEXT after the currently active EVT-020 ownership-transfer slice is safely completed.

This document is the authoritative UX/product brief for the next major workstream. It supplements `TASKLIST.md`, `COMPLETION_RECONCILIATION.md`, and `SETTINGS_RECONCILIATION.md` and exists to stop the settings surface from growing as a collection of implementation details.

## Execution order override

1. Finish the currently active EVT-020 ownership-transfer slice without leaving an event family in a no-owner or dual-owner state.
2. Complete any live verification that is inseparable from that ownership transfer.
3. Then move directly to the Settings Console Redesign in this document before starting the large Knowledge/staff/provider/payment UI expansion.
4. New Knowledge, Captain, provider, payment, staff-plane, MCP, health, and verification settings/status surfaces must plug into this console architecture rather than adding more blocks to the legacy form.

Do not pause after EVT-020 for a normal checkpoint. Start this workstream automatically.

---

# Product goal

The settings page must behave like an understandable **OJS Support Gateway control panel**, not a dump of PHP setting keys.

A Journal Manager should be able to answer:

- Is Chatwoot connected?
- Which inbox does this journal use?
- Who sees the support widget?
- What does the widget look like?
- What user/context data is shared?
- What happens when an OJS workflow event occurs?
- Is Captain connected and current?
- Is Knowledge current?
- Is email verification healthy?
- Is MCP/API access configured?
- Which sibling providers are active?
- Is anything degraded or requiring action?

The normal UI must not require administrators to understand numeric resource IDs, Chatwoot SDK JSON, internal route names, legacy-vs-v2 delivery architecture, OJS role IDs, queue internals, or raw API response JSON.

---

# Required information architecture

Build a tabbed, keyboard-accessible admin console with approximately these tabs:

1. **Overview**
2. **Chatwoot**
3. **Widget**
4. **Automation**
5. **AI & Knowledge**
6. **Verification**
7. **API & MCP**
8. **Integrations**
9. **Advanced**

Exact labels may be refined after real OJS 3.5 UI research, but the separation of concerns must remain.

Use native/local OJS/PKP assets and patterns where practical. Do not add React/Vue or an external icon/font CDN merely for the settings page.

---

# UX-001 — Overview dashboard

The first tab must summarize the system in human terms.

Show module/status cards for at least:

- Chatwoot connection
- Website widget
- identity/HMAC protection
- Captain
- Knowledge
- Event Bridge / delivery queue
- OJS mail / verification
- Support API
- MCP
- providers/payment integrations

Use states such as:

- Healthy
- Configured
- Not configured
- Degraded
- Optional / not enabled
- Action required
- Not checked / stale

Do not equate `configured` with `healthy`.

Include setup progress such as:

- Chatwoot connected
- inbox selected
- widget configured
- identity validation configured
- Captain selected/provisioned
- mail verified
- Knowledge current

Operational actions should render inline results, not browser `alert()` calls.

---

# UX-002 — Chatwoot connection should use resource discovery

Current numeric fields such as `chatwootInboxId` and `chatwootCaptainAssistantId` are implementation-oriented.

After a working Chatwoot URL + API token are available, inspect the real current Chatwoot API and implement safe discovery where supported:

- list/select Website inbox by **name**, not numeric ID;
- show channel type and relevant inbox metadata;
- derive/show the Website Token when the selected inbox API exposes it instead of forcing duplicate manual entry;
- list/select Captain assistant by **name** where the real API supports it;
- verify the selected inbox really is compatible with the Website widget;
- show mismatch/degraded states rather than silently accepting unrelated IDs.

Retain a clearly labeled **Manual configuration** fallback only when discovery is unavailable or intentionally restricted.

Do not duplicate Chatwoot-owned configuration. OJS owns integration/embed/policy; Chatwoot owns inbox service configuration such as agents, business hours, greetings, pre-chat form, CSAT, and similar inbox behavior. Show safe read-only status and an **Open in Chatwoot** link when useful.

---

# UX-003 — Widget JSON becomes structured appearance controls

The normal UI must not expose `widgetSettingsJson` as the primary way to configure ordinary Chatwoot widget options.

Represent common options as typed controls, including at minimum where supported by the currently deployed Chatwoot SDK:

- launcher position: Left / Right;
- launcher style/type: Standard / Expanded;
- launcher title, conditionally shown when relevant;
- language behavior: follow OJS / browser / fixed locale where safely supported;
- dark/light/automatic theme behavior where supported;
- unread-message prompt behavior where supported;
- pop-out control where supported;
- other stable Website SDK options that materially help this integration.

Example input such as:

```json
{"position":"right","type":"standard","launcherTitle":"Need Help?"}
```

must become ordinary controls, not JSON.

If raw JSON/custom SDK overrides remain useful, move them to **Advanced → Custom Widget Overrides**, validate them, explain precedence, and warn that unsupported keys may break widget behavior. The structured controls remain authoritative for the normal path.

---

# UX-004 — Add a live local widget preview

The Widget tab should include a safe local preview of launcher appearance.

Changing position/style/title/theme-related controls should update the preview without loading the real Chatwoot iframe.

Provide a separate explicit **Preview/Test real widget** action only when connection prerequisites are satisfied.

---

# UX-005 — Replace negative role visibility with an audience model

Current `hideForGuests` + seven `hideForRole_*` checkboxes force administrators to reason backwards.

The UI should instead ask:

**Who can see the support widget?**

Use positive audience groups such as:

- Visitors / guests
- Authors
- Reviewers
- Readers
- Journal Managers
- Section Editors
- Editorial Assistants
- Site Administrators

The stored legacy `hideForRole_*` representation may be migrated/translated internally if needed, but the UI should present a positive effective audience.

Show an effective-audience summary.

Widget visibility and privacy/blind-review safety are separate concepts.

---

# UX-006 — Blind-review protection must not look optional

Audit `enablePrivacyMode` against the current real runtime.

The current label **Enable Privacy Mode (Blind Review Protection)** is misleading if frozen reviewer/blind-review safety is actually mandatory and resource-aware regardless of an administrator preference.

Required outcome:

- permanent blind-review/reviewer protection should be displayed as a non-disableable safety status where that matches the frozen architecture;
- do not allow an ordinary UI toggle to imply administrators can disable a mandatory privacy invariant;
- separately define any optional context-sharing controls if the product genuinely supports them;
- update/migrate/deprecate `enablePrivacyMode` based on real behavior rather than preserving a misleading label.

If optional context projection is introduced, use explicit fields/categories and server-side allowlists; never make reviewer identity or blind-review evidence configurable for exposure.

---

# UX-007 — Re-verify `skipBackendPages`

Treat `skipBackendPages` as suspect until runtime evidence proves it works in the actual widget injection path.

The current source visibly injects headers into frontend and backend contexts, while `isBackendPage()` appears separate from the path inspected during this audit.

Before presenting this option in the redesigned console:

1. trace every read of `skipBackendPages`;
2. add a discriminating runtime test;
3. live-test on Dell;
4. either wire it correctly or remove/deprecate it.

No second placebo setting is allowed.

---

# UX-008 — Replace comma-separated route exclusions with understandable placement controls

Normal UI should use page/audience categories such as:

- Public journal pages
- Article pages
- Author dashboard
- Submission workflow
- Reviewer workflow
- Journal management
- Site administration

Keep arbitrary route/page exclusions only under Advanced if still needed, preferably as validated chips/tags rather than an undocumented comma-separated string.

---

# UX-009 — Performance/compatibility settings belong under Advanced

Move technical controls such as lazy loading, trigger choice, CSP behavior, and route exclusions out of the primary Widget flow.

Normal default should be an **Automatic / Recommended** loading mode.

Advanced may expose:

- load automatically / idle / first interaction / immediate;
- CSP compatibility status/override;
- custom route exclusions;
- raw widget overrides.

Do not expose implementation jargon without helper text/tooltips.

---

# UX-010 — Debug mode is troubleshooting-only

Move `enableDebugMode` to **Advanced → Troubleshooting**.

Audit the actual browser-console payload before redesign completion. It currently has the potential to print identity/context material. Minimize/redact what is logged and clearly warn that diagnostic logging should be temporary.

---

# UX-011 — Automation becomes one model, not legacy + v2 controls

The current page exposes legacy `eventSyncMode`, per-event checkboxes, a v2 global mode, customer consent, and raw per-event override JSON simultaneously.

The redesigned Automation tab must present one current model:

- one **Default action** selector;
- one structured row per supported event;
- Enabled toggle per event;
- Action selector per event (`Use default`, `Private staff note`, `Open/update privately`, `Update context`, `Record only`, `Customer-visible message` where supported);
- current delivery/queue health.

Do not show raw per-event JSON in the normal path. The implementation may continue storing a map internally.

Once event ownership migration is complete, remove visible legacy/v2 terminology and retire legacy configuration that no longer owns behavior.

---

# UX-012 — Customer-visible messages require contextual confirmation

When an event is configured to send a customer-visible Chatwoot message:

- visibly mark the row as customer-visible;
- explain the consequence inline;
- require explicit consent in context;
- preserve server-side fail-closed consent enforcement;
- importing settings must not silently enable this mode.

Do not rely on one obscure global checkbox far from the event that uses it.

---

# UX-013 — Legacy retry queue controls must not remain primary UX

As EVT-017/EVT-020 complete the v1→v2 ownership transfer, retire normal UI controls that exist only for the old `apiQueue` path.

Normal administrators should see queue health:

- pending
- retrying
- delivered/recently healthy
- dead letters

and explicit recovery actions.

Any real v2 retry tuning that remains configurable belongs in Advanced.

---

# UX-014 — Identity/HMAC gets a first-class security card

Do not bury `chatwootIdentityValidationSecret` among generic connection fields.

Show:

- identity validation configured/not configured;
- whether the selected Chatwoot Website inbox requires HMAC where the real API exposes that state;
- mismatch warnings;
- safe instructions for locating/replacing the secret;
- no stored plaintext rendering.

The UI should make it clear that Chatwoot service/application authentication is distinct from OJS end-user authority.

---

# UX-015 — Secrets need generate/rotate workflows where appropriate

For plugin-owned service credentials such as Support API and MCP credentials:

- support secure server-side generation;
- support explicit rotation/replacement;
- retain masking semantics;
- do not expose stored plaintext;
- explain which consumer uses each credential;
- never reuse public Support API and MCP/staff credentials across planes.

For Chatwoot-owned secrets/tokens, provide clear replace/configure flows rather than pretending OJS generated them.

---

# UX-016 — API & MCP tab explains capability, not protocol internals first

Present Support API and MCP as integrations:

- what they enable;
- configured/healthy status;
- endpoint with copy control;
- protocol revision as secondary technical information;
- credential status and rotate action;
- safe list of available tools/resources/capabilities;
- setup/help link.

Tooltips must explicitly state that application/service authentication does not itself authorize an OJS user.

---

# UX-017 — Verification gets its own workspace

The Verification tab should contain, as those features are completed:

- verification methods (PIN and secure link as actually supported);
- safe bounded expiry/attempt/resend controls if they are intentionally administrator-configurable;
- mail transport status;
- Send Test Email action;
- verification EmailTemplate status;
- Manage Email Templates link where supported;
- recent safe health state.

Do not expose peppers, challenge hashes, session IDs, OTP values, or link tokens.

---

# UX-018 — AI & Knowledge combines Captain and Knowledge health

Show an understandable summary:

- selected Captain assistant;
- provisioning status;
- Knowledge document state;
- Custom Tool count/state;
- Scenario count/state;
- last successful sync;
- Knowledge fingerprint/state;
- provider/source health;
- FAQ cache state once KNO-011 exists;
- safe conflict count/details once KNO-020 exists.

Provide **Sync/Repair** and **Open in Chatwoot** actions.

Do not duplicate Chatwoot-owned Captain Audience/Schedule configuration; show/link their state where useful.

Review the current 12-tool Captain footprint against current Chatwoot guidance. If the deployed Chatwoot version recommends fewer tools for reliable selection, record a warning and investigate safe consolidation rather than deleting tools blindly.

---

# UX-019 — Integrations tab is a provider dashboard

As Airix Provider SDK work lands, show sibling providers read-only by default:

- installed
- enabled
- compatible/incompatible
- degraded/unavailable
- capabilities contributed
- last health evidence where available

Examples may include Submission Fee, Request Waiver, Paystack, Bachs, Flutterwave, MultiPay, Required Submission Files, Contributor User Sync, Magic Login, and Visibility Suite only where actually installed/verified.

Do not duplicate sibling-plugin credentials or business configuration. Link to the owning plugin where practical.

---

# UX-020 — Conditional requirements / modular product setup

Audit the current unconditional validation of Chatwoot Base URL and Website Token.

The plugin now contains more than a frontend widget. A deployment may legitimately use some combination of Widget, Event Bridge, Knowledge, MCP, or API functionality.

Validation should be feature-aware:

- Widget prerequisites required when the Widget is enabled;
- Captain prerequisites required when Captain provisioning is enabled/used;
- MCP credential required when MCP is enabled/used;
- optional modules should not force unrelated configuration.

Do not introduce feature toggles merely for aesthetics; derive state from real architecture where appropriate.

---

# UX-021 — Tooltips and helper text

Use concise tooltips for technical terms and short helper text for consequences.

Examples:

**Website Inbox**
> The Chatwoot Website inbox that receives conversations from this journal.

**Captain API credential**
> Authenticates Chatwoot Captain when it calls OJS. It does not authorize an OJS user.

**Private staff note**
> Adds information to the Chatwoot conversation for agents only. The customer cannot see it.

**Record only**
> Records the OJS event for audit/diagnostic purposes without sending it to Chatwoot.

Do not hide required instructions exclusively inside hover-only tooltips. Keyboard and touch users must have equivalent access.

---

# UX-022 — Visual implementation requirements

Use:

- tab navigation;
- local/native icons;
- restrained status cards;
- clear hierarchy;
- scoped CSS;
- dedicated local JS assets;
- responsive layout;
- visible focus states;
- semantic/accessible controls;
- in-place notifications/results.

Fix the existing repeated `id="description"` markup and any other invalid/duplicate IDs.

Remove the giant inline settings jQuery block as the console is rebuilt; move behavior to dedicated plugin-owned assets using OJS/PKP patterns.

Do not globally style `input`, `button`, `h2`, `table`, etc. Namespace all plugin styles.

No external font/icon CDN.

---

# UX-023 — Global profile / import-export moves to Advanced

Keep these capabilities if still useful, but they are administrative migration/power-user tools, not primary setup:

- export settings;
- import settings;
- save current as global profile;
- apply global profile.

Provide warnings/preview/validation where practical and preserve secret exclusion rules.

Do not expose one giant JSON textarea as an ordinary workflow.

---

# UX-024 — Settings schema must become canonical

The current form repeats setting keys across init/read/save/import/export/global-profile code paths and has already suffered key-list drift.

During the redesign, move toward one canonical settings definition/schema that can describe, where practical:

- key;
- type;
- default;
- scope;
- secret status;
- validation;
- export/import policy;
- tab/group;
- runtime owner;
- deprecated/internal status.

Use it to reduce future UI/runtime drift. Do not perform a gratuitous rewrite, but stop adding more independently maintained key lists.

---

# UX-025 — Theme override drift must be treated as acceptance risk

The active `ajdsiproduction` theme can vendor/override plugin templates and previously caused the real plugin settings page to run stale code after the plugin itself was fixed.

This repo must not modify that theme, but settings acceptance must detect an overridden template and clearly record when the live UI is not the plugin's current template.

The redesigned console must be acceptance-tested against the effective template actually rendered on Dell, not merely the plugin source file.

---

# Required real acceptance

Do not close this workstream from source inspection alone.

On Dell, test at minimum:

- settings modal/page loads without 500;
- every tab opens;
- keyboard navigation;
- responsive/narrow layout;
- connection test with real Chatwoot;
- inbox discovery/selection if implemented;
- Captain discovery/selection if implemented;
- widget structured controls save/reload;
- widget preview;
- real frontend widget reflects position/style/title settings;
- positive audience controls map correctly to runtime visibility;
- reviewer/blind-review safety remains enforced;
- `skipBackendPages` is either proven functional or removed;
- event matrix saves/reloads and changes real delivery behavior;
- customer-visible mode requires consent;
- secret retention/replacement/rotation;
- import/export/global-profile behavior after schema changes;
- in-place action feedback;
- no duplicate DOM IDs;
- no CSS leakage outside plugin settings;
- no external font/icon calls;
- theme override state explicitly checked.

Update `SETTINGS_RECONCILIATION.md`, `TASKLIST.md`, `COMPLETION_RECONCILIATION.md`, and `ACCEPTANCE_TEST_MATRIX.md` as each slice becomes real.

---

# Definition of done

The Settings Console Redesign is complete only when:

- ordinary widget settings no longer require JSON;
- ordinary event overrides no longer require JSON;
- inbox/assistant selection is human-readable wherever supported by real APIs;
- blind-review safety is not presented as an optional privacy toggle;
- no known placebo setting remains;
- legacy/v2 implementation terminology is hidden from ordinary users once migration allows it;
- secrets remain safely masked;
- Chatwoot-owned and OJS-owned settings are clearly separated;
- health is actionable and distinguishes configured from verified healthy;
- current and future Knowledge/Provider/Payment/Staff surfaces have a defined place in the console;
- the real Dell page is visually, functionally, and accessibly accepted.

After this console foundation is merged and live-accepted, return to the standing completion order, plugging Knowledge/observability and later staff/provider/payment work into this UI architecture rather than extending the legacy form.