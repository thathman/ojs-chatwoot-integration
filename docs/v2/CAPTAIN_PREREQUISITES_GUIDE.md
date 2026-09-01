# Captain Intelligence Prerequisites Guide (DOC-003)

What an admin actually needs before Captain provisioning (Knowledge
Document, Custom Tools, Scenarios) works — a short, admin-facing summary.
For the full technical behavior of provisioning itself, see
`docs/v2/KNOWLEDGE_DIAGNOSTICS.md` §6 — this guide does not repeat that
detail, only what you need to have in place first.

## 1. A Chatwoot edition/plan with Captain

Captain lives in Chatwoot's Enterprise-Edition-gated code (verified
against a real `chatwoot/chatwoot` checkout — see
`docs/v2/KNOWLEDGE_DIAGNOSTICS.md` §6 for the exact citation). This means:

- **Self-hosted Chatwoot**: your install must be running the Enterprise
  Edition, not the open-source-only build.
- **Chatwoot Cloud**: your account's plan must include Captain.

If Captain is not available on your Chatwoot install/plan, provisioning
never fails loudly — every call degrades to "unavailable" and the health
section (`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md`) reports it as such,
rather than as an error. There is no separate "Captain not licensed"
error message to look for; an unchanging `not_provisioned`/`failed`
Captain state after clicking Sync/Repair Captain is the real signal.

## 2. Real API credentials with sufficient permissions

Provisioning uses the same **API Access Token**
(`chatwootApiAccessToken`, see `docs/v2/INSTALL_CONFIG_GUIDE.md` §3) this
plugin already uses for other advanced Chatwoot features. It must belong
to an Agent/Admin account with permission to create Captain Documents,
Custom Tools, and Scenarios in your Chatwoot account — the same
permission level Chatwoot's own Captain admin UI requires.

## 3. A Chatwoot inbox and Support API already configured

Custom Tools call back into this plugin's own REST Support API — so the
**Support API Token** (`chatwootSupportApiToken`, see
`docs/v2/INSTALL_CONFIG_GUIDE.md` §4) must already be set before
provisioning Custom Tools, since each tool's auth configuration is stamped
with that token at provisioning time. If you provision Custom Tools
before setting a Support API token, or later rotate the token, re-run
Sync/Repair Captain (`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md` §3) —
Custom Tools have a real update endpoint, so a token rotation is treated
as a genuine change and re-pushed, never silently ignored.

## 4. Once prerequisites are met

Use the **Sync/Repair Captain** button
(`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md` §3) to provision on demand
rather than waiting for the once-daily scheduled sync. It only ever
creates or updates resources this plugin itself owns — an existing,
unrelated Chatwoot resource with the same name is left alone and recorded
as a conflict, never adopted or overwritten.

## 5. What this does not require

- No separate Captain-specific credential — the same connection
  credentials from `docs/v2/INSTALL_CONFIG_GUIDE.md` are reused.
- No manual document upload — the Knowledge Document is generated and
  kept in sync automatically from your journal's own public information
  (see `docs/v2/KNOWLEDGE_DIAGNOSTICS.md` for what's included).
