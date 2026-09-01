# Install & Configuration Guide (DOC-001)

How to install this plugin on OJS 3.5 and configure the Chatwoot
connection for the first time. Covers only the real, built settings-form
fields (`templates/settingsForm.tpl`) — nothing planned or future is
described here.

If you are upgrading an existing v1 install rather than installing fresh,
use `docs/v2/UPGRADE_FROM_V1.md` instead — it covers a real, additional
step v1 installs need that a fresh install does not.

## 1. Requirements

- OJS 3.5.x. PHP 8.2 or 8.3 (matching OJS 3.5's own real requirement — see
  `docs/v2/VERIFICATION_MATRIX.md`'s "PHP support matrix" section; PHP 8.1
  is not supported by OJS 3.5 itself, let alone this plugin).
- MySQL/MariaDB or PostgreSQL, whichever your OJS install already uses —
  this plugin has been live-verified against a real MySQL 8.0 install
  (see `docs/v2/TASKLIST.md` TST-004/RUN-001).
- A Chatwoot account with an existing inbox, and an admin able to read
  that inbox's Website Token and (optionally) create an HMAC identity
  secret and an API Access Token.

## 2. Installing the plugin files

Place the plugin at `plugins/generic/chatwootIntegration/` inside your
OJS installation (the directory name must be exactly `chatwootIntegration`
— OJS resolves the active plugin class by convention from this directory
name, see `docs/v2/UPGRADE_FROM_V1.md` §4 for exactly how). There is no
separate build or install step — this plugin ships no `composer.json` of
its own and runs entirely inside your OJS install's existing PHP
environment.

Enable it from **Site Administration → Plugins** (or **Journal Manager →
Website Settings → Plugins** for a journal-scoped enable), under
**Generic Plugins → Chatwoot Integration**.

## 3. Configuring the Chatwoot connection

Go to the plugin's **Settings** action, journal by journal (this plugin's
connection settings are per-journal, not site-wide). You'll land on the
**Connection** section first:

| Field | What to put there |
|---|---|
| Chatwoot Base URL | The base URL of your Chatwoot installation, e.g. `https://app.chatwoot.com`. Required. |
| Website Token | The website token from your Chatwoot inbox's own settings page. Required. |
| Identity Validation Secret | The HMAC secret key for identity validation. Optional but recommended — without it, OJS-authenticated users are still identified to Chatwoot, just without HMAC-verified identity. |
| API Access Token | An Agent/Admin API Access Token, needed for advanced features like Canned Responses and Notes. |
| Inbox ID | Required only for "open/update conversation" event-delivery mode when no existing conversation is found for a contact — see `docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` §2 for the delivery-mode options. |

Every field except Base URL/Website Token is optional at this stage —
save with just those two set and the widget will work; add the rest as
you need the features they unlock.

## 4. Optional: Support API and MCP

Two further sections appear below Connection: **Support API** and
**MCP**. Both are optional and dormant until configured — enabling either
does not change any existing behavior:

- **Support API Token**: a shared secret Chatwoot Captain must send as a
  Bearer token to use the conversation-bound REST Support API. See
  `docs/v2/REST_API_GUIDE.md` for the full endpoint reference.
- **MCP**: an `mcpServiceToken` field plus a read-only display of the
  real MCP endpoint URL and protocol revision for this journal. See
  `docs/v2/MCP_SETUP_GUIDE.md` for how to point an MCP client at it.

## 5. Confirm it's working

After saving Connection settings, the settings form's own **Support
Gateway Health** section (see `docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md`)
shows whether the connection is recognized as configured. Use its **Send
Test Email** and (existing v1) **Test Message** buttons to confirm the
mail transport and Chatwoot API credentials work, respectively, without
needing to visit a real journal page first.

## 6. What this guide does not cover

- Everything below Support API/MCP in the settings form (widget
  visibility, event sync, performance, Event Bridge policy) is existing,
  separately-documented behavior — see
  `docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md` for Event Bridge, and the
  field labels in the settings form itself for the rest (visibility/
  performance toggles are self-explanatory v1 settings, unchanged by v2).
- Captain provisioning (Knowledge Document, Custom Tools, Scenarios)
  requires a Chatwoot plan that exposes those APIs — that's a separate
  concern from the connection settings here.
