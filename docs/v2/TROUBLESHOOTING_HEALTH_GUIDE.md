# Troubleshooting & Health Guide (DOC-008)

How to read and use the plugin's own Support Gateway Health section and the
diagnostic actions next to it. Everything below describes the actual admin
UI as built (`templates/settingsForm.tpl`, `ChatwootSettingsForm.php`,
`docs/v2/TASKLIST.md` ADM-001 through ADM-006) — no planned or future
screen is described here.

Find this at: **Journal Manager → Website Settings → Plugins → Generic
Plugins → Chatwoot Integration → Settings**.

## 1. Where the health section appears

If the plugin can produce a real health summary, a **"Support Gateway
Health"** section appears at the very top of the settings form, above the
Connection/Support API/MCP/Event Bridge sections. If it doesn't appear at
all, nothing has been configured yet — start with the Connection section
below.

The section is color-coded by its own `overallState`:

- **Green** (`healthy`) — no signal reports a problem.
- **Yellow** (`degraded`) — something is not fully configured or not yet
  provisioned, but nothing has actually failed.
- **Red** (`failed`) — a real, actionable problem exists.

## 2. Reading each line

| Line | What it means |
|---|---|
| Overall state | The single rolled-up state described above. |
| Chatwoot connection configured | Whether `chatwootBaseUrl`/`chatwootWebsiteToken`/`chatwootIdentityValidationSecret`/`chatwootApiAccessToken`/`chatwootInboxId` are all set. |
| Support API configured | Whether `chatwootSupportApiToken` is set (required for REST Support API endpoints). |
| MCP configured | Whether `mcpServiceToken` is set (required for the MCP gateway). |
| Verification mail delivery configured | Whether OJS's own mail transport is configured (reused from the existing account mail diagnostic — this plugin does not have its own separate mail config). |
| Knowledge health | Only shown if there's a real Knowledge health signal to show; reflects the same state `KnowledgeHealthService` computes. |
| Captain provisioning health | Only shown if there's a real Captain provisioning signal to show. A fresh install with nothing provisioned yet is **not** shown as a problem here — provisioning something for the first time is what the button in §3 is for. |
| Pending event-queue entries | How many queued events are waiting to be delivered to Chatwoot right now. A normal, healthy number fluctuates near zero. |
| Failed event-queue entries (dead letters) | How many events exhausted their delivery attempts and stopped retrying automatically. Non-zero is the trigger for the retry button in §4. |

None of these numbers are a live Chatwoot/Captain API call made on your
page load — they are all local, already-computed signals, so viewing this
section never itself makes an outbound request.

## 3. "Sync/Repair Captain"

Always visible when the health section is shown. Re-provisions this
journal's Captain Knowledge Document, Custom Tools, and Scenarios on
demand, instead of waiting for the once-daily scheduled sync.

- Only ever creates or updates resources this plugin itself already owns
  (tracked by its own sync-state records). It never touches an
  administrator's own, separately-created Captain resources.
- Scoped to the current journal only — it does not touch any other
  journal's Captain resources, unlike the scheduled task which runs for
  every journal.
- Safe to click repeatedly; a resource that's already correctly
  provisioned is left as-is.

Use this when: you just changed the journal's public information (fees,
policies, etc.) and don't want to wait for the next scheduled sync, or
when Captain provisioning previously failed (e.g. during an outage) and
you want to retry immediately rather than waiting for the next scheduled
attempt.

## 4. "Retry Dead Letters"

Only appears when "Failed event-queue entries (dead letters)" above is
greater than zero. Resets up to 50 of this journal's failed events back to
`pending` with a fresh delivery-attempt budget, so the normal delivery
task picks them up again on its next run.

- Scoped to the current journal only.
- Never shows or exposes the failed events' actual content or error
  detail — only a count of how many were reset. If you need to know *why*
  events are failing, that requires checking the server's own application
  logs (this plugin never logs secret-bearing request/response bodies).
- Safe to click again if some events still fail afterward and become dead
  letters again — it will simply retry whatever is currently failed.

Use this when: dead letters accumulate after Chatwoot itself was
unreachable for a while and is now back up.

## 5. "Send Test Email"

Always visible when the health section is shown. Sends a test email to
**your own account** (the admin currently logged in and viewing this
page) to confirm OJS can hand a message to its configured mail transport.

- The recipient is always the current admin's own account email — there
  is no way to send it to any other address from this button.
- Success means only "OJS handed the message to its transport." It is
  **not** proof the message actually reached your inbox — this plugin (like
  OJS itself) has no visibility into downstream mail delivery. If you
  don't receive it, check your mail transport configuration
  (`config.inc.php`'s `[email]` section) and your spam folder before
  assuming this plugin is broken.
- Completely independent of the real verification PIN/link emails sent to
  end users — this diagnostic shares no code path with that system, so a
  successful test here does not by itself confirm verification emails
  will also send (though in practice they use the same underlying OJS
  mail transport).

## 6. Older diagnostics (still present, from before the health section)

Three older buttons remain above the health section for backward
compatibility with existing installs:

- **Health Check** — a simpler existing diagnostic that pops up its raw
  JSON result.
- **Test Message** — sends a real test message through the Chatwoot
  connection itself (not just the OJS mail transport) to confirm the
  Chatwoot API credentials work.
- **Export/Import Settings** — exports/imports this journal's settings as
  JSON. Every secret field (`chatwootIdentityValidationSecret`,
  `chatwootApiAccessToken`, `chatwootSupportApiToken`, `mcpServiceToken`)
  is always excluded from the export — the exported JSON never contains a
  plaintext secret.

## 7. If a setting won't save, or a secret field looks wrong

Every real secret field (`chatwootIdentityValidationSecret`,
`chatwootApiAccessToken`, `chatwootSupportApiToken`, `mcpServiceToken`) is
rendered as a password field showing a fixed mask (`********`) once a
value is saved — this is expected, not a bug, and is never the real
stored value. Leaving the mask unchanged on save keeps the existing
secret; typing a new value replaces it; clearing the field entirely and
saving removes it.

## 8. Where to look if the health section itself is missing entirely

The health section only renders when the plugin can compute a real
summary at all. If it's missing:

- Confirm you're looking at journal-level settings, not the site-wide
  admin plugin list (this section only computes for a specific journal
  context).
- Confirm the plugin was actually upgraded correctly — see
  `docs/v2/UPGRADE_FROM_V1.md`, particularly its real-behavior note about
  why simply replacing files is not enough on an existing v1 install.
