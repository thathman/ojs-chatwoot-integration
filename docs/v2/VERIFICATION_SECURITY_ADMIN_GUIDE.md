# Verification & Event Security Admin Guide (DOC-004)

What an admin can and cannot configure about identity verification and
outbound Chatwoot event delivery. Everything below describes the actual
built settings and code — no planned admin control is described as if it
already existed.

For secret fields, health signals, and the admin diagnostic buttons, see
`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md` instead — this guide does not
repeat that content.

## 1. Identity verification: fixed by design, not admin-configurable

There is currently **no settings-form UI for verification parameters**.
The PIN/link challenge behavior is fixed in code
(`classes/v2/Verification/`), not exposed as journal settings:

| Parameter | Real value | Where |
|---|---|---|
| PIN length | 6 digits | `VerificationSecretHasher::PIN_LENGTH` |
| Secure link token size | 32 random bytes | `VerificationSecretHasher::LINK_TOKEN_BYTES` |
| Maximum verification attempts | 5 | `VerificationChallengeService`'s default `maxAttempts` |
| Challenge expiry, resend cooldown | set at construction, not exposed as a setting | `VerificationChallengeService` |

This is a deliberate current scope, not an oversight: `docs/v2/
SECURITY_PRIVACY.md` §5 documents these as "configurable only within safe
ranges" as a design *principle* for a future admin control, not a
control that ships yet. If you need a different value today, it requires
a code change, not a settings-form change. Do not tell administrators
these are adjustable from the UI.

What *is* already true regardless of configurability, and worth knowing:

- A challenge secret is always stored as a keyed cryptographic hash, never
  the plaintext PIN/token (`VerificationSecretHasher`).
- Only one active challenge exists per conversation/purpose/identity at a
  time; issuing a new one revokes the previous one.
- A successful verification immediately consumes the challenge — it can
  never be replayed.
- Every attempt (success or failure) is audited with a reason code, never
  the plaintext PIN.
- The response to a verification request is always the same generic
  message regardless of whether the claimed account exists — this plugin
  never reveals account existence through this endpoint.

These are proven by `tests/v2/external-verification.php` and
`tests/v2/forged-chatwoot-header.php`, not just asserted here.

## 2. Event Bridge: what is admin-configurable

Unlike verification, event delivery **is** admin-configurable, in the
settings form's "Event Bridge" section (see
`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md` for where to find it on the
page):

### Default Delivery Mode

A dropdown selecting what a queued event (submission created, decision
recorded, publication published, etc.) does by default. The default
option, `(use the legacy Sync Mode setting above)`, preserves an existing
install's current behavior unchanged until an admin deliberately opts
into a new mode — upgrading the plugin never silently changes event
delivery behavior. The other real options are:

| Option shown | Real mode value |
|---|---|
| Private note (staff-only, never customer-visible) | `private_note` |
| Open or update a conversation (private note) | `open_update_conversation` |
| Update conversation context only | `update_context` |
| Audit only (never sent to Chatwoot) | `audit_only` |
| Send a customer-visible message (requires consent below) | `opt_in_customer_message` |

### Customer-Visible Messages (consent checkbox)

This is the one real security-relevant control in this section: checking
it is the *only* way `opt_in_customer_message` can ever actually be
applied, whether selected as the default mode above or as a per-event
override below. **Leaving it unchecked silently downgrades any
`opt_in_customer_message` selection to a private note instead** — a
customer-visible message is never sent by accident just because a mode
was picked. This is enforced in `EventDeliverySettingsResolver`, proven in
`tests/v2/event-delivery-settings-resolver.php`.

### Per-Event Overrides

A JSON textarea mapping one event type to one mode, overriding the
default above for just that event. Example:

```json
{"publication.published": "audit_only"}
```

Recognized event types: `submission.created`,
`submission.decision_recorded`, `submission.revision_requested`,
`submission.accepted`, `submission.rejected`, `publication.scheduled`,
`publication.published`, `submission.review_submitted`. An unrecognized
event type or mode in this field is silently ignored, not an error —
malformed input never breaks event delivery, it just has no effect
(fails closed to "no override," never fails closed to "block all
delivery").

## 3. What this section does not cover

- MCP/Support API tokens and Chatwoot connection secrets — see
  `docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md` §7 and §1–5 respectively.
- Blind-review anonymity and cross-journal isolation — these are always-on
  code guarantees (`tests/v2/blind-review-anonymity.php`,
  `tests/v2/context-resolver-isolation.php`), not something an admin
  configures or can disable.
- Staff-plane mutations (review reminders, editorial decisions) — not
  built yet; explicitly deferred, see `docs/v2/TASKLIST.md`'s POL-004/
  POL-007 entries.
