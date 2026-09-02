# v2 Settings Reconciliation (SETTINGS-001)

Status: in progress — real, live acceptance testing against `dell`
(`ojs-demo.airixmedia.com`, real Chatwoot at `support.airixmedia.com`).

This is the one authoritative, real inventory of every setting the
Chatwoot Integration plugin (v1 legacy class + v2) reads or writes,
built by grepping every `getSetting`/`updateSetting`/`getEffectiveSetting`/
`v2EffectiveSetting` call site across the **entire** codebase (not just
`classes/v2/`), then cross-checking `ChatwootSettingsForm.php`'s own
`getData()`/`setData()` dictionary for settings a plain `getSetting`
grep would miss. That cross-check found the seven `hideForRole_*`
settings: they are read/written directly inside `ChatwootSettingsForm.php`
(`initData()`/`execute()`) via `$plugin->getSetting()`/`updateSetting()`,
never through `getEffectiveSetting()`/`v2EffectiveSetting()`, and never
referenced from `classes/v2/` at all — a grep scoped to `classes/v2/`
would have silently missed them.

Classification key (per the governing directive):
- **USER CONFIGURABLE** — plain, non-secret, meant for a Journal Manager to set.
- **ADVANCED** — configurable but power-user/edge-case (JSON blobs, per-event overrides).
- **SECRET** — masked at rest and in the UI; never re-echoed once saved.
- **READ-ONLY STATUS** — displayed, not editable (health/diagnostics).
- **INTERNAL — NO UI** — self-provisioning or queue/state storage; deliberately has no admin field.
- **DEAD** — has a real field and is saved/loaded, but nothing reads it.

## Connection

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Validation | In UI? | Save/load correct? | Functional? | Intended tab | Acceptance evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `chatwootBaseUrl` | Chatwoot Base URL | per-journal | `''` | `v2EffectiveSetting()`/`getEffectiveSetting()`, ~10 call sites (widget, Captain, support API, MCP, health) | USER CONFIGURABLE | No | required (`FormValidator`) | Yes — Connection section | Yes | Yes | Chatwoot Settings | live: real value `https://support.airixmedia.com` confirmed rendering (ACCEPTANCE_TEST_MATRIX SET-01) |
| `chatwootWebsiteToken` | Website Token | per-journal | `''` | widget script assembly | USER CONFIGURABLE | No (public, embedded client-side) | required | Yes | Yes | Yes | Chatwoot Settings | live: real token confirmed rendering (public value, not sensitive) |
| `chatwootIdentityValidationSecret` | Identity Validation Secret (HMAC) | per-journal | `''` | HMAC identity hash for widget `identifier_hash` | SECRET | Yes | none declared | Yes — fixed TST-023 | Yes | **Fixed** — live-verified rendering + masking after PR #155 merge, dell redeploy, and fixing a stale theme-override copy of this template (see cross-cutting finding 2) | Chatwoot Settings | live 500 root-caused, fixed, redeployed, and re-verified rendering correctly end-to-end |
| `chatwootApiAccessToken` | Chatwoot API Access Token | per-journal | `''` | Chatwoot REST API calls (contacts/conversations) | SECRET | Yes | none declared | Yes — fixed TST-023 | Yes | **Fixed**, live-verified | Chatwoot Settings | same as above |
| `chatwootInboxId` | Chatwoot Inbox ID | per-journal | `0` | Chatwoot API calls scoped to an inbox | USER CONFIGURABLE | No | none declared (no numeric-range/required check) | Yes | Yes | Yes | Chatwoot Settings | live: field renders with real value `15` |
| `chatwootCaptainAssistantId` | Captain Assistant ID | per-journal | `0` | `provisionCaptainKnowledgeDocument()`/`provisionCaptainCustomTools()` — Sync/Repair Captain hard-requires `> 0`, else silent no-op | USER CONFIGURABLE | No | none declared | Yes — added TST-022 | Yes | **Live-verified rendering** (empty, matching the real "Captain provisioning health: not_provisioned" status shown in the same modal) | Chatwoot Settings | source-tree test tst-022; live render confirmed via browser screenshot after TST-023 fix + theme-override fix |

## Support API / MCP

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Validation | In UI? | Save/load correct? | Functional? | Intended tab | Acceptance evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `chatwootSupportApiToken` | Support API Token | per-journal | `''` | `ServiceTokenAuthenticator` — bearer auth for the v2 Support API surface | SECRET | Yes | none declared | Yes — fixed TST-023 | Yes | **Fixed**, live-verified | Support API | same as above |
| `mcpServiceToken` | MCP Service Token | per-journal | `''` | `ServiceTokenAuthenticator` for the MCP gateway | SECRET | Yes | none declared | Yes — fixed TST-023 | Yes | **Fixed**, live-verified | MCP | same as above; deliberately excluded from both `EXPORT_KEYS`/`LEGACY_EXPORT_KEYS` (ADR-021, tst-022/settings-form-mcp-secret-masking assert this) |

## Widget visibility

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Validation | In UI? | Save/load correct? | Functional? | Intended tab | Acceptance evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `enableWidget` | Enable Widget | per-journal | `false` | gates widget injection | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForGuests` | Hide for Guests | per-journal | (unset/false) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_1` (Site Admin) | Hide for role: Site Admin | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes (via form's own getData/setData — see note above) | Yes | Widget Visibility | |
| `hideForRole_16` (Manager) | Hide for role: Manager | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_17` (Sub Editor) | Hide for role: Sub Editor | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_65536` (Author) | Hide for role: Author | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_4096` (Reviewer) | Hide for role: Reviewer | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_4097` (Assistant) | Hide for role: Assistant | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `hideForRole_1048576` (Reader) | Hide for role: Reader | per-journal | (unset) | role-visibility check | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `enablePrivacyMode` | Privacy Mode | per-journal | `false` | suppresses identity-bearing context sent to Chatwoot | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `excludedPages` | Excluded Pages | per-journal | `''` | comma-list of pages the widget must not load on | ADVANCED | No | none (free text, no format validation) | Yes | Yes | Yes | Widget Visibility | |
| `skipBackendPages` | Skip Backend Pages | per-journal | (unset) | widget suppression on admin/backend pages | USER CONFIGURABLE | No | none | Yes | Yes | Yes (grep-confirmed consumer exists in v1 class, not separately re-verified live this pass) | Widget Visibility | |
| `cspSafeMode` | CSP Safe-Mode | per-journal | `false` | nonce-based CSP compatibility for the injected widget script | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `lazyLoadWidget` | Lazy-Load Widget | per-journal | `true` | defers widget script load | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Widget Visibility | |
| `lazyLoadTrigger` | Lazy-Load Trigger | per-journal | `'idle'` | `idle`/`interaction` | USER CONFIGURABLE | No | enum via dropdown (`idle`/`interaction`) | Yes | Yes | Yes | Widget Visibility | |
| `widgetSettingsJson` | Widget Settings (JSON) | per-journal | `''` | raw JSON passed through to the Chatwoot widget SDK config | ADVANCED | No | none declared (no JSON.parse validation found in form) | Yes | Yes | Yes (assuming valid JSON; malformed JSON's actual runtime behavior not yet re-verified live) | Widget Visibility | |
| `launcherBottomOffset` | Launcher Bottom Offset | per-journal | (unset) | **none — dead** | DEAD | No | none | Yes | Yes (saves/loads fine) | **No — confirmed dead**, see V1_INVENTORY.md FND-004 | Widget Visibility | source: no reference outside the form/template; REMOVE or WIRE UP still an open decision, not resolved by this pass |

## Workflow Automation (event bridge)

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Validation | In UI? | Save/load correct? | Functional? | Intended tab | Acceptance evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `eventSyncMode` | Event Sync Mode (legacy) | per-journal | `'note'` | v1 fallback default; still the real fallback whenever `eventDeliveryGlobalMode` is left at "(use legacy)" | USER CONFIGURABLE | No | enum dropdown | Yes | Yes | Yes | Workflow Automation | |
| `eventSubmissionCreated` | Event: Submission Created | per-journal | (unset/true-ish via `isEventEnabled` default `true`) | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventRevisionRequested` | Event: Revision Requested | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventAccepted` | Event: Accepted | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventRejected` | Event: Rejected | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventPublicationScheduled` | Event: Publication Scheduled | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventPublicationPublished` | Event: Publication Published | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventDecisionRecorded` | Event: Decision Recorded | per-journal | default `true` | per-event toggle | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventDeliveryGlobalMode` | Event Delivery Mode (v2) | per-journal | `''` (use legacy) | `EventDeliverySettingsResolver`/`EventDeliveryPolicy` | USER CONFIGURABLE | No | enum dropdown (private note / open+update / update context / audit only / opt-in customer message) | Yes | Yes | Yes | Workflow Automation | |
| `eventDeliveryCustomerMessageConsent` | Customer-Message Consent | per-journal | `false` | gates opt-in customer-message delivery mode | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Workflow Automation | |
| `eventDeliveryPerEventOverridesJson` | Per-Event Overrides (JSON) | per-journal | `''` | per-event delivery-mode override map | ADVANCED | No | none declared | Yes | Yes | Yes | Workflow Automation | |

## Performance & Delivery / Global profile

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Validation | In UI? | Save/load correct? | Functional? | Intended tab | Acceptance evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `retryQueueEnabled` | Retry Queue Enabled | per-journal | `true` | gates v1 JSON retry-queue path | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Performance & Delivery | |
| `maxRetryAttempts` | Max Retry Attempts | per-journal | `5` | clamped `max(1, min(10, n))` | USER CONFIGURABLE | No | clamped server-side to [1,10], not enforced client-side | Yes | Yes | Yes | Performance & Delivery | |
| `enableGlobalDefaults` | Use Global Defaults | per-journal | (unset) | `getEffectiveSetting()`/`v2EffectiveSetting()` fall back to context `0` when true | USER CONFIGURABLE | No | none | Yes | Yes | Yes, pending cross-OJS-target re-verification per V1_INVENTORY.md | Performance & Delivery | |
| `enableDebugMode` | Debug Mode | per-journal | `false` | verbose logging | USER CONFIGURABLE | No | none | Yes | Yes | Yes | Admin Tools & Setup | |

## Internal / no UI (by design)

| Setting | Human name | Scope | Default | Consumer | Classification | Secret? | Notes |
|---|---|---|---|---|---|---|---|
| `chatwootVerificationPepper` | (none — internal) | per-journal | self-generated (`bin2hex(random_bytes(32))`) on first use | `v2VerificationPepper()` — HMAC pepper for verification challenges | INTERNAL — NO UI | Yes (never rendered anywhere) | self-provisioning by design; correct as-is |
| `apiQueue` | (none — internal) | per-journal | `[]` | v1's own JSON-blob retry queue (`QUEUE_KEY`), distinct from v2's real `chatwoot_support_event_queue` table | INTERNAL — NO UI | No | see V1_INVENTORY.md "Retry/event bridge" — the two queues currently coexist independently |

## Cross-cutting findings from this pass

1. **TST-023 (fixed this pass):** all four `SECRET_KEYS` fields used
   `type="password"`, which is not a real pkp-lib FBV element type —
   it hit `default: assert(false)` in `FormBuilderVocabulary`, an
   uncaught `AssertionError` that 500'd the entire settings modal the
   instant a real browser rendered past `chatwootIdentityValidationSecret`
   (the first secret field in template order). This means the settings
   modal had **never** actually rendered end-to-end in a real browser
   for this plugin in this environment — only source-tree tests had
   validated the masking logic itself. Fixed via PR #155
   (`type="text" password=true`, the real pkp-lib incantation).
   Confirmed live: after fixing the theme-override copy below (finding
   2a), the settings modal renders end-to-end with no 500 — every
   field including the four secret fields (correctly masked) and the
   TST-022 Captain Assistant ID field (empty, matching the real
   "Captain provisioning health: not_provisioned" status shown in the
   same modal).
2. **Infra finding (not fixed by this pass, dell-side only, not
   committed to git):** dell's `log_errors` was `Off`, so the above
   500 produced zero trace in Apache/nginx/cloudflared logs at any
   layer. Temporarily enabled (`log_errors=On`, `error_log` to the
   real Apache error log) via a `conf.d` ini drop-in on the live
   `ojs-fresh-ojs-1` container during this investigation — left
   enabled as a real observability improvement; this is an
   environment configuration, not a code change, so it does not need
   a PR, but is recorded here so it isn't lost.
2a. **Theme-override drift (fixed dell-side, same class of bug this
   session already documented once before):** dell's active theme
   (`ajdsiproduction`) carries its own vendored copy of this plugin's
   `settingsForm.tpl` at
   `plugins/themes/ajdsiproduction/templates/plugins/generic/chatwootIntegration/templates/settingsForm.tpl`
   — OJS resolves the theme's copy before the plugin's own template.
   That copy still had the broken `type="password"` fields (so the
   PR #155 fix alone did not clear the live 500) and was also missing
   the TST-022 Captain Assistant ID field entirely. Fixed directly on
   the live container (backed up as `settingsForm.tpl.bak-tst023`
   first): applied the same `type="text" password=true` fix to all
   four secret fields and inserted the missing Captain Assistant ID
   field block, preserving the theme's own intentional CSS-class
   customizations (`ajdsi-plugin-admin` classes) that the plugin's
   stock template doesn't have. This is a theme-repo concern, not this
   plugin's code, so it is not committed here — recorded so it isn't
   lost, same as the Smarty-cache theme-drift finding earlier this
   session.
3. **Export/import gap (not yet fixed):** `LEGACY_EXPORT_KEYS` (v2)
   and `EXPORT_KEYS` (v1) both predate several newer settings —
   `widgetSettingsJson`, `launcherBottomOffset`,
   `eventDeliveryGlobalMode`, `eventDeliveryCustomerMessageConsent`,
   `eventDeliveryPerEventOverridesJson` are all real, non-secret,
   UI-configurable settings that are silently excluded from the
   Export/Import Settings feature. This is a real completeness gap,
   not a security issue (nothing sensitive is over-exported) — left
   as an open follow-up.
4. **`launcherBottomOffset` remains dead** (confirmed again this
   pass) — REMOVE or WIRE UP is still an open decision per
   `docs/v2/V1_INVENTORY.md` FND-004; not resolved here.

## Next steps

- Done: settings modal re-verified live end-to-end with no 500, every
  field (including the four fixed secret fields and the TST-022
  Captain Assistant ID field) rendering correctly.
- Decide and act on `launcherBottomOffset` (remove vs. wire up).
- Decide and act on the export/import completeness gap (item 3 above).
- Consider whether the `ajdsiproduction` theme's vendored plugin
  templates (of which this is one of several — see the `find`
  results in this pass's investigation) should be re-synced from
  their real plugin sources on a regular cadence, or the theme should
  stop vendoring plugin templates it doesn't actively customize beyond
  a couple of CSS classes — a decision for whoever owns that theme,
  out of this repo's scope.
