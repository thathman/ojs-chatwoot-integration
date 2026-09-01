# Knowledge Provider Guide (DOC-005)

What journal information this plugin publishes as public "knowledge"
(for Chatwoot Captain and for public visitors/crawlers), where it comes
from, and what never appears there. For the full technical
classification/precedence/conflict model, see
`docs/v2/KNOWLEDGE_DIAGNOSTICS.md` — this guide is the short, admin-facing
version.

## 1. What "knowledge" means here

A small set of real facts about your journal — configuration and public
content already in OJS, never anything private — compiled into plain
public pages at `/<journal>/support-knowledge/<category>`. These pages
exist for two audiences at once: a human visitor or search
crawler reading them directly, and Chatwoot Captain, which can be pointed
at this same URL tree as its Knowledge Document source (see
`docs/v2/CAPTAIN_PREREQUISITES_GUIDE.md`).

These pages are always public and unauthenticated — no login, no
verification session, no capability check. If your journal's own content
should not be public, don't rely on hiding it here; the underlying rule
(see §3) is that only content already public elsewhere in OJS is ever
included.

## 2. The real categories, and where each comes from

| URL path | What it covers | Real source |
|---|---|---|
| `/support-knowledge/about` | Journal name, description, contact info | Live OJS journal configuration |
| `/support-knowledge/submissions` | Submission requirements/guidelines | Live OJS journal configuration |
| `/support-knowledge/review` | Review process information | Live OJS journal configuration |
| `/support-knowledge/fees` | Publication fee amount/currency, whether fees are enabled | Live OJS payment manager configuration (and, where configured, a verified third-party fee-policy provider — see `docs/v2/PROVIDER_INTEGRATION_GUIDE.md`) |
| `/support-knowledge/publication` | Publication/issue information | Live OJS publication configuration |
| `/support-knowledge/pages` | Your journal's own Static Pages | OJS core's Static Pages plugin — only pages a journal manager actually created there; never a crawl of your website |
| `/support-knowledge/accounts` | Account help topics | Built-in account-support content |
| `/support-knowledge/policies` | Journal policies | Live OJS journal configuration |
| `/support-knowledge/sitemap` | A real sitemap of every category above | Generated from the same category list, never a second, separately-maintained list |

This exact category list is the plugin's single, real source of truth
(`KnowledgeRouteCatalog`) — the navigation on every generated page and the
sitemap are always in sync, because both are generated from it.

## 3. The one rule that decides what's public

Every fact this system knows about carries a classification —
`public`, `private`, or `unsupported`. **Only a fact classified exactly
`public` can ever appear on a generated page.** A fact with a missing or
unrecognized classification is rejected outright, never defaulted to
public — there is no way for new or third-party knowledge content to leak
onto a public page by omission.

If two sources disagree about the same fact, live OJS configuration
always wins over a static page or FAQ content — see
`docs/v2/KNOWLEDGE_DIAGNOSTICS.md` §4b for the exact precedence order.
The losing value is never shown; it's recorded internally only as a
health signal.

## 4. What never appears here

- Anything from a real submission, review, or user record — this system
  only ever reads journal-level configuration and explicitly public
  pages, never per-submission or per-user content.
- Anything from a page your journal manager didn't create through OJS's
  own Static Pages plugin — no crawling of your live website.
- Approved FAQ content — the provider for this is specified but not built
  yet (see `docs/v2/TASKLIST.md` KNO-011); this category currently has no
  content from that source.
