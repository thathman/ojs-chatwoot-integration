# PKP Plugin Gallery Release Metadata (RELS-010/011/012)

Final metadata and Gallery XML `<release>` snippet for the `2.0.0.0`
release. This document was rewritten once more immediately before
publication after a real packaging gap was found and fixed (`.forgejo/`,
`.github/`, dev tooling config, and the full `tests/` suite were being
bundled into the release archive; see `.gitattributes`) — the values
below are the true final ones, not the earlier, now-obsolete build.

## Real release facts

- **Version**: `2.0.0.0` (`version.xml`, real).
- **Release date**: 2026-09-01.
- **Final release commit**: `5cc04bc86f7e19e0df9d282f96f3d60d9e82b796`
  (both Forgejo `v2-dev` and GitHub `main` point to this commit).
- **Package**: `chatwootIntegration-2.0.0.0.tar.gz`, built via `git
  archive --format=tar --prefix=chatwootIntegration/ HEAD | gzip` from
  the commit above — a real, single top-level `chatwootIntegration/`
  directory (244 archive entries / 210 real files), verified to extract
  cleanly into a fresh directory, no `.git`/dev-CI/test content (excluded
  via `.gitattributes` `export-ignore`), no path traversal, `version.xml`
  reports `2.0.0.0`, `LICENSE` present and GPL-3.0-or-later SPDX-tagged.
- **Real file size**: 341510 bytes.
- **Real MD5**: `752d100032f333ba9d142c485bbfd8ac`
- **Real SHA-256**: `af16b0657585af6676c86cd0cdd08ec25323cc941616ead97b6117f5107fb727`

Final pre-publication verification against this exact commit: full
`tests/v2/*.php` suite green, `php-cs-fixer` clean, a real Semgrep scan
(`p/php`/`p/security-audit`/`p/secrets`) at 0 findings across 215 tracked
files, and a targeted secret-literal grep with no matches (SEC-010).

## Title / summary / description (RELS-011)

- **Title**: Chatwoot Integration
- **Summary** (one line, for the Gallery listing): Live chat and an
  AI-agent-ready public support gateway for your journal, powered by
  Chatwoot.
- **Description** (longer, for the Gallery detail page):

  > Adds a Chatwoot live chat widget to your OJS journal, with rich,
  > presentation-only context (role, ORCID, active submissions, article
  > context) sent to support agents — plus a full public Support Gateway:
  > a verified-identity system, a 14-endpoint REST API, an MCP adapter for
  > AI agent clients, generated public knowledge pages (fees,
  > submissions, review, policies, and more), and one-click Chatwoot
  > Captain provisioning (Knowledge Document, Custom Tools, Scenarios).
  > Includes a consolidated admin health dashboard, manual Captain
  > sync/repair, dead-letter retry, and configurable per-event delivery
  > policy (private note, customer-visible message with explicit opt-in,
  > or audit-only). Blind-review anonymity and cross-journal isolation are
  > enforced server-side, never left to client-supplied data.
  >
  > Does not include: OJS 3.6 compatibility, a public staff-mutation
  > plane (staff-facing writes/diagnostics remain out of scope for this
  > release), or the full admin-configurable verification EmailTemplate
  > migration (the built-in PIN/link verification emails work; promoting
  > them to editable OJS EmailTemplates is a documented follow-up).

- **Homepage**: `https://github.com/thathman/ojs-chatwoot-integration`
  (the real public GitHub repository — the actual publication target,
  now public).
- **Maintainer**: Hendrix Nwaokolo (`n.hendrix.e@gmail.com`).

## Compatibility matrix — supported vs. actually tested (RELS-008/RELS-017)

Two distinct claims, kept explicitly separate:

| | OJS's own stated support | This plugin's real integration testing |
|---|---|---|
| OJS version | 3.5.x | Real, live install/upgrade verified against a real OJS 3.5.0-5 instance (`docs/v2/TASKLIST.md` TST-004/RUN-001), including the real HTTP-route fixes from TST-014. |
| PHP | 8.2, 8.3 (OJS 3.5's own real `composer.json`/`PKPApplication::PHP_REQUIRED_VERSION` requirement, and OJS's own upstream CI matrix) | This plugin's CI runs PHP 8.2 and 8.3 on every change — matches OJS's real requirement exactly. |
| Database | MySQL/MariaDB and PostgreSQL are both supported by OJS 3.5 itself | **Only MySQL 8.0 has been integration-tested by this plugin** (the real live upgrade above). MariaDB and PostgreSQL are expected to work (OJS itself supports them, and this plugin's own migration uses portable Laravel schema-builder calls, not raw MySQL-specific SQL) but have **not** received equivalent real integration verification for this release. |

OJS 3.6 is explicitly **not** claimed — `pkp/ojs`'s own `main` branch
already reports `3.6.0.0`, a separate compatibility target this release
does not attempt.

## Gallery XML `<release>` snippet (RELS-012)

```xml
<release version="2.0.0.0" date="2026-09-01" lang="en">
    <package>PACKAGE_URL_PLACEHOLDER</package>
    <md5>752d100032f333ba9d142c485bbfd8ac</md5>
    <compatibility>
        <version>3.5.0.0</version>
    </compatibility>
</release>
```

`PACKAGE_URL_PLACEHOLDER` is replaced with the real, immutable GitHub
release asset URL once the release is created — see the real URL recorded
in this document's revision history / the actual Gallery PR, whichever is
more current. The MD5 above must be reconfirmed against the actual
uploaded asset (re-downloaded and re-hashed) before it is used in any
Gallery submission — never trusted from the local build alone.
