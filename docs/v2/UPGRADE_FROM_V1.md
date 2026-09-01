# Upgrade From v1 Guide (DOC-009)

How to upgrade an existing v1 (`1.0.0.2` or earlier) install of this plugin to
v2. Every step below was actually run, in order, against a real OJS 3.5.0-5 +
MySQL 8.0 install to confirm it works (see `docs/v2/TASKLIST.md` TST-014/
RUN-001) — nothing here is a planned or assumed step.

## 1. What actually changes

- Five new database tables are created: `chatwoot_support_sessions`,
  `chatwoot_support_verification_challenges`,
  `chatwoot_support_knowledge_sync`, `chatwoot_support_audit_log`,
  `chatwoot_support_event_queue`. Nothing existing is dropped, renamed, or
  altered — the migration only adds tables, and does so idempotently (it
  checks `Schema::hasTable()` before creating each one, so re-running it is
  always safe).
- Every v1 setting (`chatwootBaseUrl`, `chatwootWebsiteToken`,
  `chatwootIdentityValidationSecret`, `chatwootApiAccessToken`,
  `chatwootInboxId`, the widget/privacy/visibility flags, the event-sync
  settings) is preserved exactly as-is — v2 reads the same setting keys v1
  did (see `docs/v2/V1_INVENTORY.md` for the full classification). Nothing
  needs to be re-entered.
- v1's own behavior (widget injection, event delivery in its existing
  mode) keeps working unchanged after the upgrade — v2 is additive, not a
  replacement of v1's runtime behavior.
- The new Support Gateway/MCP/Support Knowledge HTTP surface (REST API,
  MCP gateway, public knowledge pages) becomes reachable only after the
  upgrade step in §3 actually runs — installing new plugin files alone is
  not enough (see §4 for why).

## 2. Before you upgrade

1. Back up your database. This plugin's own migration is additive and
   idempotent, but a full-install backup is still the right general
   practice before any plugin version change.
2. Confirm your OJS install is on a supported version (OJS 3.5.x; see
   `docs/v2/VERIFICATION_MATRIX.md`'s "PHP support matrix" section — PHP
   must be 8.2 or 8.3, matching OJS 3.5's own real requirement).
3. Replace the plugin's files at `plugins/generic/chatwootIntegration/`
   with the new v2 release (or, in a source checkout, fast-forward that
   directory's git checkout to the release tag/branch — this is exactly
   how the real verification above was performed).

## 3. Run the real upgrade step

Simply replacing the files is not enough — OJS only re-runs a plugin's
install migration when it detects the plugin's own version has changed.
Run OJS's real, built-in single-plugin upgrade tool from your OJS
installation root:

```
php lib/pkp/tools/installPluginVersion.php plugins/generic/chatwootIntegration/version.xml
```

This is a core PKP tool (`PKP\cliTool\InstallTool`'s sibling for a single
plugin), not something this plugin adds. It reads the plugin's
`version.xml`, records the new version in the `versions` table, and runs
the plugin's migration (creating the five new tables from §1). It prints
nothing on success; check its exit code, or verify directly (§5).

If you are running OJS inside Docker, run this inside the application
container, e.g.:

```
docker exec <ojs-container-name> php lib/pkp/tools/installPluginVersion.php plugins/generic/chatwootIntegration/version.xml
```

## 4. Why the upgrade step matters (real, confirmed behavior)

Real OJS page dispatch resolves the active plugin class by convention —
`PKP\plugins\PluginRegistry::instantiatePlugin()` guesses the class name
from the plugin's own install directory, and Composer's real PSR-4
autoloading resolves that guess straight to a file on disk. This plugin's
own `index.php` wrapper is **not** part of that path. Every real page
request always runs whatever class currently lives at that conventional
location, regardless of `index.php`.

This has one practical consequence worth knowing: the plugin's own files
being present is what makes the new v2 classes exist on disk, but the
*version record* in the database (updated by §3's tool) is what OJS uses
to decide whether anything changed and re-run the install migration. Skip
§3, and the new tables in §1 are never created — you'd have the new code
but not its schema.

## 5. Verify the upgrade succeeded

Check the real database record:

```sql
SELECT product, major, minor, revision, build, current
FROM versions
WHERE product = 'chatwootIntegration'
ORDER BY date_installed DESC;
```

The most recent row should show the new release number with `current = 1`,
and the previous v1 row should now show `current = 0`.

Check the five new tables exist:

```sql
SHOW TABLES LIKE 'chatwoot_support%';
```

You should see all five listed in §1.

Finally, confirm the site itself is unaffected: load any normal journal
page and confirm the Chatwoot widget still appears exactly as it did
before the upgrade (v1 behavior is untouched), and that no PHP error
appears in your web server's error log across a few page loads.

## 6. What is not yet upgraded automatically

- **Admin console settings** (masked secrets, MCP token, Support Gateway
  Health, Event Bridge policy) are all new, optional settings sections —
  see the plugin's own Journal Manager settings page after upgrading.
  None of them are required for v1 behavior to keep working; they are
  opt-in additions.
- **MCP** and the new REST Support API endpoints are dormant until an
  admin explicitly configures the relevant tokens (`mcpServiceToken`,
  `chatwootSupportApiToken`) in the settings form — no new external
  surface is exposed just by upgrading.
- The full verification-email `EmailTemplate` migration (promoting the
  PIN/link emails into admin-configurable OJS EmailTemplates) has not
  shipped yet — this remains a documented, specified follow-up (see
  `docs/v2/TASKLIST.md`'s ADM-006 entry). The existing fixed-content
  verification emails continue to work unchanged after upgrading.
