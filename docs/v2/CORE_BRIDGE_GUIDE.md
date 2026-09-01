# Core Chatwoot Bridge Guide (DOC-002)

What the Chatwoot chat widget actually shows a visitor, what real context
gets sent to Chatwoot when it loads, and the real visibility/performance
settings that control it. Read directly from
`ChatwootIntegrationBasePlugin::addChatwootWidget()` — nothing here is a
planned behavior.

For identity verification (the separate, server-side PIN/link challenge
system) and the REST/MCP Support API, see
`docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md`, `docs/v2/REST_API_GUIDE.md`,
and `docs/v2/MCP_SETUP_GUIDE.md` instead — this guide covers only the
widget itself.

## 1. What the widget is

The Chatwoot chat widget script, injected into ordinary OJS pages via
OJS's own `TemplateManager::display`/`TemplateManager::fetch`/
`Templates::Common::Footer::PageFooter` hooks — the exact same mechanism
any theme uses to add scripts to a page, not a special integration point.
Whether it appears at all, and what it knows about the current visitor,
is controlled entirely by the settings below.

## 2. What context is sent to Chatwoot on every page load

Every widget load sends a `custom_attributes` payload built fresh from
the current request — never cached, never stale from a previous visit:

- `journal_id`, `journal_name` — the current journal.
- `requested_page`, `requested_op` — the current OJS page/operation.
- If a logged-in user: `roles` (comma-separated role IDs),
  `active_submissions` (a live count from OJS's own submission
  collector), `orcid`, `affiliation`.
- If viewing an article page (`article`/`view`): `context_type` = `article`,
  plus `article_title`, `article_doi`, `article_id`, and `section_title`.

If a logged-in user is present, the widget also sets Chatwoot's own
identity fields — `email`, `name` (full name), and `identifier` (the OJS
user id) — plus, when **Identity Validation Secret** (see
`docs/v2/INSTALL_CONFIG_GUIDE.md` §3) is configured, an HMAC hash of the
identifier so Chatwoot can mark the contact `hmac_verified`.

**This is presentation context only, never an authorization source.**
Nothing in the widget payload is trusted by the Support API/MCP gateway
to decide what a Chatwoot conversation may see — those endpoints
independently re-resolve identity and relationship server-side every
time (see `docs/v2/SECURITY_PRIVACY.md` §4). A Captain agent reading these
custom attributes gets useful context, never elevated access.

## 3. Privacy Mode (blind-review protection)

When **Enable Privacy Mode (Blind Review Protection)** is on and the
current logged-in user is a reviewer for this journal, the real identity
fields above are replaced with masked values instead of being sent
plainly:

- `identifier` becomes a one-way hash (`reviewer_` + SHA-256 of the user
  id and journal id) — not reversible back to the real user id from the
  Chatwoot side.
- `name` becomes the literal string `Reviewer (Masked)`.
- `email` becomes a masked placeholder derived from the real email's MD5
  (`reviewer_<8 hex chars>@masked.local`), never the real address.
- `is_masked: true` is added to the custom attributes so an agent can see
  masking is active, rather than silently getting a plausible-looking
  fake identity.

Non-reviewer users are never masked by this setting — it targets exactly
the blind-review anonymity concern, not general privacy.

## 4. Visibility settings

| Setting | Real effect |
|---|---|
| Enable Chat Widget | Master on/off switch. Everything else in this guide is moot if this is off. |
| Hide for Guests (Unauthenticated) | Widget never loads for a visitor with no OJS session. |
| Hide for \<Role\> (per role checkbox) | Widget never loads for a logged-in user holding that specific role in this journal — checked per role the user actually holds, not just their "primary" role. |
| Excluded Pages (comma-separated page names) | The widget never loads on any OJS page whose requested-page name is in this list — matched against the exact real `$request->getRequestedPage()` value, not a URL pattern. |
| Enable Debug Mode | Adds a `console.log` of the real identifier/HMAC hash/custom-attributes payload to the browser console. Only for diagnosing what's actually being sent — never enable on a production install serving real reviewers/authors, since it puts the (unmasked, if privacy mode is off) HMAC hash in the browser console. |

## 5. Performance settings

| Setting | Real effect |
|---|---|
| Lazy-load Chat Widget | Defers loading the widget script until idle or first interaction (see the Lazy Load Trigger option), rather than blocking page render. |
| Launcher Bottom Offset (px) | **Correction: this setting is currently dead.** It saves and loads a value, but nothing in `addChatwootWidget()`'s real widget-script assembly ever reads it — setting it has no effect today. Documented as a real, verified gap in `docs/v2/V1_INVENTORY.md` rather than silently fixed here. |
| Skip Widget on Workflow Page | Never loads the widget on OJS's editorial workflow pages specifically. |
| Enable CSP-safe Script Mode | Adjusts how the widget script is injected for installs running a strict Content-Security-Policy. |
| Widget Settings JSON (advanced) | Raw JSON merged directly into `window.chatwootSettings` before the widget script runs — for real Chatwoot widget options this plugin has no dedicated field for. Invalid JSON here is a configuration mistake this plugin does not validate for you; check your browser console. |
