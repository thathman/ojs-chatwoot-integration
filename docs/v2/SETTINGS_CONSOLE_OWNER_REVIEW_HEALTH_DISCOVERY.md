# SETTINGS CONSOLE OWNER BROWSER REVIEW — HEALTH UX + DISCOVERY PERSISTENCE

Owner review date: 2026-09-04.

This is blocking Settings Console acceptance and must be handled inside the uninterrupted B–K console workstream before moving to unrelated backlog work.

## Real browser findings

The owner reviewed the effective console after the theme-override fix and identified three product defects.

### 1. Health Check still exposes raw JSON

The Overview action currently renders a payload such as:

```json
{
  "sdkReachable": true,
  "apiTokenValid": true,
  "identityHmacValid": true,
  "configured": {
    "baseUrl": true,
    "websiteToken": true,
    "apiAccessToken": true,
    "identitySecret": true
  }
}
```

This is implementation data, not administrator UX. Source confirms `settingsForm.tpl` currently calls `JSON.stringify()` for the health-check success result.

Replace it with a human result that updates the Overview/connection status UI, for example:

- **Chatwoot widget service — Reachable**
- **API access — Verified**
- **Identity validation — Verified**
- **Connection settings — Complete**
- **Last checked — just now / timestamp**

Do not show booleans or raw JSON as the normal result. A developer-details disclosure may exist under Advanced if genuinely useful, but the default experience must be human.

### 2. Overview is still a raw fact list

The current Overview visibly renders lines such as:

- Overall state: degraded
- Chatwoot connection configured: Yes
- Support API configured: Yes
- MCP configured: No
- Verification mail delivery configured: Yes
- Knowledge health: healthy
- Captain provisioning health: degraded
- Pending event-queue entries: 0
- Failed event-queue entries (dead letters): 1
- Retrying event-queue entries: 0
- Dead letters with error code `delivery_failed`: 1

This is materially cleaner than the legacy page but it is still an operator/debug dump rather than the requested Support Gateway dashboard.

Item F must replace this list with a clean, actionable health presentation.

Recommended information architecture:

### Overall banner

Translate internal state vocabulary for humans.

Instead of simply `degraded`, prefer a clear state such as **Needs attention** with the actual reason summarized, e.g.:

> 2 items need attention: Captain provisioning and 1 failed event delivery.

Internal state codes may remain in diagnostics, but the primary UI should explain impact.

### Status cards / grouped rows

Show at minimum:

- **Chatwoot** — Connected / Needs attention / Not configured
- **Website Inbox** — selected human name + verified state
- **Identity validation** — Protected / Needs configuration
- **Widget** — Enabled / Disabled
- **Captain** — Healthy / Needs attention / Not configured
- **Knowledge** — Healthy / Stale / Needs attention
- **Event delivery** — Healthy / 1 failed delivery / retrying count
- **Verification mail** — Ready / Needs attention
- **Support API** — Configured / Off / Needs attention
- **MCP** — Optional: Off / Configured / Needs attention
- **Integrations** — summary when item I exists

Configured must not mean healthy. Optional/off must not mean degraded.

Zeros should generally disappear unless they help explain a healthy state. For example, do not make the user read three separate queue counters when `No deliveries waiting` communicates the same thing.

A dead letter should be actionable:

> **1 delivery failed**  [Retry failed delivery] [View details]

Do not surface `delivery_failed` as the primary user-facing sentence. Safe technical reason codes may appear in details.

### Health action

`Run Health Check` should refresh the cards/status rows and show `Last checked`, rather than append a raw payload under the button.

### Test message

Keep the test-message action separate from health. Explain exactly what it proves and what it does not prove.

## 3. Discovery/resource state is not durable across refresh

This is a real behavior defect, not just wording.

After **Test Connection & Discover** succeeds, the current browser can show the discovered account/inbox/assistant names. After closing/reopening or refreshing the settings page, source currently reconstructs the saved values as:

- `Not tested yet. (ID 15)` for Website Inbox
- `Not tested yet. (ID 2)` for Captain Assistant
- discovery summary resets to `Not tested yet`

Source confirms the cause: discovery populates human labels only into the current DOM; initial template rendering knows only the persisted numeric ID and hard-codes the `discover.notRunYet` label.

This is unacceptable for the finished console. A resource that was successfully discovered and selected must not become visually "untested" merely because the modal reloaded.

### Required durable model

Persist or safely reconstruct **last-known verified resource metadata** per journal, without persisting secrets or raw external payloads.

At minimum the console should be able to restore:

- selected Chatwoot account ID + human name
- selected Website Inbox ID + human name + useful safe website/domain metadata where available
- selected Captain Assistant ID + human name
- last successful discovery/verification timestamp
- safe verification/ownership state

Use an internal state/cache model rather than making administrators edit these values. If plugin settings are used for safe metadata, classify them as internal/non-secret/non-global/non-user-editable and keep them out of ordinary import/export unless explicitly justified.

### Initial page load behavior

When a saved selection exists:

- show its human name immediately from last-known verified metadata;
- show `Last verified ...` rather than `Not tested yet`;
- optionally revalidate asynchronously or on explicit Test Connection & Discover;
- if revalidation fails, retain the saved human selection and mark it `Could not verify now` / `Last verified ...`, rather than erasing the identity or pretending it was never tested.

If an old install has an ID but no cached human metadata yet, use a transitional label such as `Saved inbox (ID 15) — verify connection to load details`, not `Not tested yet`, and hydrate it on the first successful discovery.

### Save/reload acceptance

Real browser acceptance must prove:

1. Run Test Connection & Discover.
2. Select real Website Inbox by human name and real Captain Assistant by human name.
3. Save settings.
4. Close the modal.
5. Reopen settings / hard refresh.
6. The same human account, Inbox, and Captain names are still shown as selected.
7. The console shows last verified state/time rather than `Not tested yet`.
8. Re-run discovery and confirm selections remain stable if the same resources still exist.
9. Simulate/induce a discovery failure where practical and prove last-known names are retained with a stale/unverified-now warning instead of being replaced by empty/unknown state.

Numeric IDs may be available in Advanced/details, but not as the primary identity of a resource.

## 4. Captain Sync/Repair result must also stop dumping JSON

The AI & Knowledge tab currently displays results like:

```json
{
  "document": "synced",
  "tools": {"noop": 6, "failed": 6},
  "scenarios": {"noop": 1, "failed": 4}
}
```

Replace this with a human summary, e.g.:

> **Captain sync completed with issues**
>
> Knowledge document: Synced  
> Custom Tools: 6 already current, 6 failed  
> Scenarios: 1 already current, 4 failed

Provide a safe `View details`/diagnostic path for failure reason codes if useful. Never dump raw Chatwoot bodies, secrets, guardrails, or confidential assistant configuration.

If all resources are current, say so plainly, e.g. `Captain is up to date`.

## 5. Visual cleanliness requirements

Use the console's scoped CSS and OJS-native patterns. Prefer:

- compact cards or grouped status rows;
- status icon/badge + title + one-line explanation;
- action buttons only when actionable;
- progressive disclosure for technical details;
- consistent `Healthy / Needs attention / Off / Not configured / Stale / Failed` vocabulary;
- tooltips/helper text where a term is not obvious.

Avoid turning the Overview into a wall of cards if simple grouped rows communicate better. The goal is clarity, not decoration.

## Acceptance gate

These are now part of Item F/K and also reopen the incomplete portion of Item B dealing with durable resource identity.

Do not call the Settings Console complete until the real browser proves:

- no raw health JSON in normal UI;
- no raw Captain sync JSON in normal UI;
- Overview is an actionable human status dashboard, not a fact list;
- discovered Chatwoot account/Inbox/Captain names survive save + modal reopen + page refresh;
- `Not tested yet (ID X)` no longer appears for a resource that was previously successfully discovered;
- last-verified/stale/failure semantics are truthful;
- selected resources remain scoped to the correct Chatwoot account and journal;
- no secret or confidential external payload is persisted in the metadata cache.

Continue immediately with the remaining Settings Console sequence after fixing these findings. Do not return to unrelated backlog work.