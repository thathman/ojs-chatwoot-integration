# PKP Plugin Gallery Release Metadata (RELS-010/011/012)

Draft metadata and a draft Gallery XML `<release>` snippet for the
`2.0.0.0` release, prepared ahead of actual publication per the standing
release directive's "prepare, do not publish" instruction. **Nothing in
this document has been submitted anywhere** — the package download URL
below is an explicit placeholder, since no public release artifact exists
yet (creating one is gated by the standing PUBLIC RELEASE GATE).

## Real release facts

- **Version**: `2.0.0.0` (`version.xml`, real — see `docs/v2/TASKLIST.md` RELS-007).
- **Release date**: 2026-09-01.
- **Package**: `chatwootIntegration-2.0.0.0.tar.gz`, built via `git archive
  --format=tar --prefix=chatwootIntegration/ HEAD | gzip` from `v2-dev` at
  commit `997ae6d` — a real, single top-level `chatwootIntegration/`
  directory, verified to extract cleanly (293 real files).
- **Real file size**: 509711 bytes.
- **Real MD5**: `d68300580b044df8d3185c12717d6802`

(Re-run the exact same `git archive` command against the actual commit
being tagged for release to regenerate this file/MD5 before publication —
the ones above are current as of this preparation pass, not necessarily
the final tagged commit.)

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

- **Homepage**: `https://git.airixmedia.com/thathman/ojs-chatwoot-integration`
  (the real, current repository location; this repo's own description
  names GitHub as the eventual public-publication target — that URL does
  not exist publicly yet and is not used here).
- **Maintainer**: Hendrix Nwaokolo (`n.hendrix.e@gmail.com`).

## Draft Gallery XML `<release>` snippet (RELS-012)

Follows the real PKP Plugin Gallery XML release-entry shape (a `<release>`
element inside the plugin's own gallery-registered `<plugin>` entry —
this snippet covers only the new release entry to add, not a full
from-scratch gallery XML file, since this plugin is not yet Gallery-listed
at all):

```xml
<release version="2.0.0.0" date="2026-09-01" lang="en">
    <package>PLACEHOLDER_NO_PUBLISHED_PACKAGE_URL_YET</package>
    <md5>d68300580b044df8d3185c12717d6802</md5>
    <compatibility>
        <version>3.5.0.0</version>
    </compatibility>
</release>
```

**The `<package>` URL is a real, explicit placeholder** — filling it in
with a real GitHub release asset URL requires RELS-002/009 (creating a
public release artifact), both gated by the standing PUBLIC RELEASE GATE
and not attempted here. `<compatibility><version>` names OJS `3.5.0.0`
only, matching this release's real, tested target (`docs/v2/
VERIFICATION_MATRIX.md`'s PHP support matrix and TST-004/RUN-001's real
install/upgrade verification) — OJS 3.6 remains explicitly out of scope
and is not listed.

## What remains before this can actually be submitted

- RELS-002/009: an actual public, downloadable release package and an
  immutable GitHub release/tag — both real publication actions gated by
  the standing PUBLIC RELEASE GATE.
- RELS-013: validating the final XML/package URL/MD5 once a real package
  URL exists.
- RELS-014: opening the actual PR to `pkp/plugin-gallery` — gated by the
  same PUBLIC RELEASE GATE.
