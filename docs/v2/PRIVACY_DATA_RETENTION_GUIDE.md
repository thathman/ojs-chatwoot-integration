# Privacy & Data Retention Guide (DOC-010)

What this plugin actually stores, for how long, and what an admin can do
about it. Every table and behavior below is read directly from the real
migration (`classes/v2/Migration/InstallSupportGatewayMigration.php`) and
the real scheduled purge task
(`classes/v2/Task/PurgeExpiredSupportDataTask.php`) — nothing here is a
planned data-handling policy.

## 1. What this plugin stores, and why

This plugin loads live OJS state (submissions, users, payments) on demand
from OJS's own database each time it's needed — it does not copy or
duplicate that data into its own tables. It has five tables of its own,
all created by the migration above:

| Table | What it stores | Never stores |
|---|---|---|
| `chatwoot_support_sessions` | A short-lived binding between an OJS user id and a Chatwoot conversation (account/contact/conversation IDs), plus timing (created/last-used/idle-expiry/absolute-expiry/revoked timestamps) and a hashed one-time binding token. | The plaintext binding token. |
| `chatwoot_support_verification_challenges` | A hashed PIN/link secret, the OJS user id it resolved to, the Chatwoot conversation it's bound to, attempt count, and timing. | The plaintext PIN or link token, or a claimed email address (once an account is resolved, the OJS user id is what's kept — see `docs/v2/ADRS.md` ADR-005). |
| `chatwoot_support_knowledge_sync` | Which Captain resources (documents/tools/scenarios) this plugin has provisioned, and their sync state — bookkeeping only. | Any submission/user/review content. |
| `chatwoot_support_audit_log` | Correlation ID, endpoint, decision (allow/deny), reason code, context id, assurance level — see `docs/v2/SECURITY_PRIVACY.md` §17. | Request/response bodies, secrets, or plaintext verification codes. |
| `chatwoot_support_event_queue` | A queued outbound event's type, resource reference, delivery mode, and status/attempt bookkeeping. | — |

## 2. What is purged automatically, and when

A real scheduled task
(`PurgeExpiredSupportDataTask`, registered via this plugin's
`HasTaskScheduler` implementation) runs and:

- deletes every `chatwoot_support_sessions` row whose expiry has already
  passed;
- deletes every `chatwoot_support_verification_challenges` row whose
  expiry has already passed;
- deletes every `chatwoot_support_audit_log` row older than **90 days**
  (`PurgeExpiredSupportDataTask::AUDIT_LOG_RETENTION_SECONDS`) — a
  starting default set in code, not yet an admin-configurable setting.

**`chatwoot_support_event_queue` and `chatwoot_support_knowledge_sync`
rows are not purged by this task or anything else yet.** Delivered and
failed event-queue rows accumulate indefinitely (the health section's
"dead letter" retry action, see `docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md`
§4, resets a failed row's status but does not delete it). This is a real,
current gap, not a documented retention policy — if unbounded table
growth becomes a concern for your install, that is worth raising as a
follow-up, not something you can currently configure around.

## 3. Uninstalling the plugin

Uninstalling the plugin drops all five tables above
(`InstallSupportGatewayMigration::down()`) — every session, verification
challenge, knowledge-sync record, audit log row, and queued event this
plugin ever created is deleted along with them. This is real OJS plugin
uninstall behavior, not a separate data-cleanup step you need to run
first.

Uninstalling does **not** touch anything in OJS's own core tables — no
submission, user, payment, or review data is created or modified by this
plugin in the first place, so there is nothing else to clean up there.

## 4. What never leaves this OJS install

- No table above stores a plaintext credential (PIN, link token, binding
  token, or API secret) — every one of those is stored as a keyed hash.
- Settings-form secret export (`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md`
  §6) never includes `chatwootIdentityValidationSecret`,
  `chatwootApiAccessToken`, `chatwootSupportApiToken`, or
  `mcpServiceToken`.
- The audit log intentionally never stores a request or response body —
  only the allowlisted fields in the table above — so it can never become
  a secondary place a secret or private submission detail leaks into.
