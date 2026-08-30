# v2 Journal Knowledge & Diagnostics Specification

## 1. Goal

Make support journal-aware without making Captain the source of truth. The plugin compiles approved OJS facts into a support knowledge model and exposes private/dynamic state through live tools.

## 2. Knowledge classes

Every knowledge item has:

- key;
- value/content;
- locale;
- classification;
- provider ID;
- source/provenance (source + optional sourceReference);
- generated/updated timestamp where known.

**As actually implemented:** classification is deliberately narrower than
originally specified above — `KnowledgeClassification` has exactly three
values, `public`/`private`/`unsupported` (no `protected`/`staff`/`secret`
tiers). `KnowledgeFact`'s constructor rejects any other string outright, and
`KnowledgeCompiler` only ever surfaces a fact whose classification is
*exactly* `public` — a fact with a missing or unrecognized classification is
rejected, never defaulted to public. Journal/context ID is not a `KnowledgeFact`
field: isolation is structural instead (`KnowledgeCompiler::compile()` is
always called once per context, and a fingerprint is computed per
compilation, never globally), verified by `tests/v2/knowledge-compiler.php`'s
multi-journal isolation tests. Only `public` knowledge is eligible for
generated pages.

## 3. Core knowledge domains

### Journal profile

- name, acronym/abbreviation where configured;
- publisher/contact details;
- ISSN and public identifiers where available;
- public journal URL;
- supported locales.

### Submission support

- whether submissions are available;
- author guidelines;
- submission checklist/requirements;
- sections;
- supported languages/metadata expectations exposed by OJS;
- public file/type instructions;
- official submission links.

### Review support

- public peer-review policy/model where configured/published;
- reviewer guidelines;
- public review timelines/policy text where available;
- public conflict/ethics guidance.

### Fees/payments

- payments enabled;
- publication fee enabled;
- configured publication fee;
- currency;
- public payment/waiver policy content;
- public payment instructions.

Submission-specific paid/waived/unpaid state is protected live data, not knowledge.

**As actually implemented (KNO-008):** `CorePaymentKnowledgeProvider` covers
`fee.publicationEnabled`/`Amount`/`Currency` (native OJS, same verified
`OJSPaymentManager` read `ojs_get_payment_status` uses) and
`fee.submissionEnabled`/`Amount`/`Currency` (Airix Submission Fee, via a new
policy-only adapter accessor, `getAirixSubmissionFeePolicy()`, that calls
only `PaymentHelper::feeEnabled()/amount()/currency()` — never
`hasPaid()`/`waiverDiscount()`/`needsRefundReview()`, and is a separate
method from `getAirixSubmissionFeeProvider()`, which remains the private
obligation path). Public waiver policy content and public payment
instructions are not implemented — no verified public-facing accessor for
either was found in `Airix360/ojs-request-waiver`'s public integration
surface; adding them later requires the same standard, not an inference
from obligation data.

### Publication

- open-access/copyright/licence policy;
- publication frequency where configured;
- DOI/public identifier information;
- archival/publication policy pages;
- public issue/article URLs.

**As actually implemented (KNO-007):** `CorePublicationKnowledgeProvider`
covers `publication.accessModel` (deterministic sentence from
`Journal::PUBLISHING_MODE_{OPEN,SUBSCRIPTION,NONE}`, with a configured
`delayedOpenAccessDuration` stated verbatim — never an invented timeline),
`policy.openAccessPolicy` (the journal's own configured text, sanitized),
`publication.doiAssigned` (boolean, from `enableDois`), and
`publication.currentIssueUrl`/`publication.archiveUrl` (the real
`issue/current`/`issue/archive` routes OJS core itself uses). DOI
prefix/suffix pattern, archival-preservation participation (CLOCKSS/LOCKSS),
and publication frequency are not implemented — no fabricated substitute
was added in their place.

### Accounts/support

- registration/login/reset paths and public instructions;
- ORCID availability where configured;
- support/contact details;
- journal-specific approved FAQs.

**As actually implemented (KNO-006 accounts slice):** `AccountsKnowledgeProvider`
covers `accounts.registrationAvailable` (the exact single boolean
`RegistrationHandler::validate()` itself gates registration on —
`disableUserReg` — never a guessed combination of conditions),
`accounts.registrationUrl` (only when registration is available, so a
disabled journal never publishes a misleading link), `accounts.loginUrl`,
`accounts.passwordResetUrl` (both always available, independent of
registration), and `accounts.orcidEnabled` (via ORCID's own published
`\PKP\orcid\OrcidManager::isEnabled($context)`, not a raw settings read).
Journal-specific approved FAQs are not implemented yet.

## 4. Generated Captain knowledge

Recommended generated hierarchy:

```text
/support-knowledge/
  about
  submissions
  review
  fees
  publication
  accounts
  policies
```

The root page must contain normal direct links to every generated page because current Chatwoot simple crawl extracts links from the supplied page. A sitemap is recommended.

Generated pages must:

- be public and not require OJS login;
- contain only `public` classifications;
- identify journal and last generated time;
- use canonical URLs;
- be localized or explicitly identify locale;
- exclude scripts/forms/private tokens;
- be stable enough for crawling;
- include provenance-friendly headings;
- return non-200 on generation failure rather than stale private/debug output.

**As actually implemented (KNO-013/014/015):** `/support-knowledge/` (root),
`/about`, `/submissions`, `/review`, `/fees`, `/publication`, `/pages`,
`/accounts`, `/policies`, and a generated sitemap all exist. The sitemap
route is `/support-knowledge/sitemap`, not `sitemap.xml` — PKP's
page/operation routing dispatches the URL's operation segment directly to
a same-named PHP method, and `.` is not a legal PHP method-name character,
so a literal `sitemap.xml` segment cannot map to any handler. The response
still sends `Content-Type: application/xml`, which is what a sitemap
consumer actually keys on. Both the root navigation and the sitemap draw
their category list from the same `KnowledgeRouteCatalog` — there is no
second, independently maintained list to drift out of sync. The root page
links every category page that does exist. "Identify
journal and last generated time" and "return non-200 on generation failure"
are not yet implemented — the current pages render the journal name and
compiled facts but no explicit generation timestamp or failure status code;
a category with no public facts renders a valid empty-state page rather
than an error.

## 4a. Official public pages (KNO-010)

The only page surface this codebase reads from is OJS core's own **Static
Pages** plugin (`pkp/staticPages`) — journal-manager-authored pages that are
by definition explicitly public. This is deliberately not a crawl of the
journal's website: a page a manager did not create through this plugin
never becomes a KnowledgeFact, sidestepping the provenance/injection/
staleness problems a full-domain crawl would create.

Each static page becomes one `officialPage.<path>` fact (title + sanitized
content). `OfficialPageKnowledgeProvider` reads through
`Ojs35CompatibilityAdapter::getOfficialPublicPages()`, which returns `[]`
whenever the plugin is absent or disabled — never a fatal.

## 4b. Source precedence and conflicts

When two facts collide on the same `(locale, key)`, `KnowledgeSourcePrecedence`
decides deterministically, per the ranked tiers in §1:

1. structured live OJS configuration (`ojs.context`, `ojs.payment_manager`, `ojs.dispatcher`, `ojs.section_repository`);
2. an explicitly verified structured third-party provider (`airix.submission_fee_policy`);
3. an official OJS-managed public page (`ojs.static_page`);
4. approved FAQ/support content (`faq` — provider not built yet).

A source string this table doesn't recognize ranks last, never wins by
accident. `KnowledgeCompiler` keeps only the highest-ranked fact per
colliding key and records every loser as a `KnowledgeConflict`
(`tests/v2/knowledge-official-pages.php` proves a stale official page and
an unrecognized-source fact both lose to structured configuration, and
that the losing values never leak into the rendered facts). Conflicts are
never rendered on a generated page — they exist purely as an internal
health signal for a future admin screen (KNO-020).

## 5. Knowledge sync

Knowledge Providers compute fingerprints. When a fingerprint changes:

1. mark generated knowledge stale;
2. regenerate public pages;
3. update internal fingerprint;
4. if Captain provisioning/sync integration is configured, request document sync;
5. otherwise surface `Captain knowledge sync required` in plugin health UI.

Never make Chatwoot the master copy of journal policy.

**As actually implemented (KNO-020, partial):** `KnowledgeHealthService`/`KnowledgeHealthReport`
exist as a **read-only, request-time** snapshot of one compilation —
`contextId`, `locale`, `fingerprint`, `state` (`healthy`/`degraded`/`empty`/`failed`),
`publicFactCount`, per-provider `healthyProviders`/`failedProviders`,
`excludedPrivateCount`/`excludedUnsupportedCount`, `conflictCount` plus
safe conflict metadata (`key`/`winnerSource`/`loserSource` only — never the
losing fact's value), and `generatedRoutes` (from `KnowledgeRouteCatalog`).
`state` rules are deterministic: every provider failed -> `failed`; some
(not all) failed -> `degraded`; no failures but zero public facts ->
`empty`; otherwise `healthy`. An absent optional sibling plugin (Airix
Submission Fee, Static Pages) returns no facts without throwing and is
recorded as a healthy provider — its absence never looks like a failure.
There is deliberately no persisted `lastGeneratedAt`/`lastSyncedAt`/`stale`
yet — those require a sync record that will exist once Captain Document
provisioning (§6) is built; fabricating that semantics now would be
inventing state the system doesn't actually have. No admin UI consumes
this service yet — it exists as the service/model only.

## 6. Captain provisioning

Where the installed Chatwoot edition/API credentials permit:

- create/find a Captain Document pointing to `/support-knowledge/`;
- trigger document sync;
- provision/update the plugin’s canonical Custom Tools;
- optionally provision recommended Scenarios;
- report drift between expected and actual Chatwoot configuration.

Provisioning is idempotent. Never delete unrelated administrator-created Captain tools/documents.

## 7. Recommended Captain scenarios

### Journal information

Uses knowledge/FAQ lookup only when possible.

### Account support

Uses identity/verification/account diagnostic tools.

### Submission support

Uses identity/submission/status/required-action tools.

### Payment/publication support

Uses payment/publication tools after appropriate verification.

### Human escalation

Uses structured escalation/handoff tool.

Scenarios narrow available tools; authorization remains server-side.

## 8. Feedback and learning loop

Current Captain has FAQ suggestion infrastructure. v2 may surface recurring suggestions to journal staff, but the loop is approval-based:

```text
support conversations
 -> Captain/analytics detect recurring question
 -> suggestion
 -> human review
 -> approved FAQ or OJS content update
 -> Knowledge Compiler refreshes
```

Never automatically promote a conversation answer or Captain memory into authoritative journal policy.

## 9. Diagnostic framework

A diagnostic is a named rule set with explicit prerequisites, evidence sources and safe output.

Fields:

- diagnostic ID/version;
- required capability;
- target resource type;
- public-safe findings;
- staff-only evidence;
- recommended actions;
- confidence;
- known limitations.

## 10. Account diagnostics

Candidate checks, subject to current OJS/PKP interfaces:

- is caller already authenticated?;
- is support session valid?;
- registration/login/reset functions available?;
- account state/enablement where safely authorized;
- email validation state where OJS exposes it;
- journal role/author access where relevant;
- duplicate-account suspicion for staff escalation only.

Anti-enumeration rule applies to all anonymous account checks.

## 11. Submission diagnostics

Candidate checks:

- authenticated identity and author role/relationship;
- journal accepting submissions/configuration where available;
- draft submission presence;
- current submission progress/stage;
- expected/required metadata/file state exposed by OJS;
- relevant user permissions;
- current route/page context;
- known workflow blockers;
- provider-specific blockers.

If the engine cannot prove the exact blocker it returns `unknown`/`needs_human`; Captain must not invent one.

## 12. Upload diagnostics

Potential evidence:

- PHP upload/post limits;
- OJS-configured limits;
- allowed file type/genre constraints where available;
- request/error instrumentation;
- attempted file size/type only when safely captured.

A configuration mismatch such as “OJS allows 20 MB but PHP only accepts 8 MB” can be deterministic. Claiming the exact cause of a user’s past failure without matching telemetry is not.

## 13. Payment diagnostics

Evidence can include:

- publication fee enabled/amount/currency;
- completed payment for the authorized submission;
- waiver state;
- configured payment method/provider health where safe;
- outstanding/queued state where supported.

Provider timeout returns `unknown`, never automatically `unpaid`.

## 14. Publication/DOI diagnostics

Potential output:

- publication status;
- issue assignment;
- DOI assigned/registered state where OJS/provider exposes it;
- public URL;
- provider error state for staff where available.

Crossref/DataCite-specific diagnosis requires separate provider adapters and must be verified against those plugins/APIs before release claims.

## 15. Email diagnostics

Scope is intentionally limited to what the journal application can prove:

- configured mail transport/path;
- ability to enqueue/attempt send;
- known application-level error;
- verification email issuance state.

Do not promise proof that a recipient mailbox/provider ultimately delivered or displayed a message unless an external delivery provider supplies such evidence.

## 16. Public vs staff diagnostic output

Example public output:

```json
{
  "status": "problem_found",
  "code": "UPLOAD_SERVER_LIMIT",
  "publicExplanation": "The journal server is currently rejecting files larger than the amount allowed by its web server configuration.",
  "recommendedAction": "contact_support"
}
```

Staff evidence may include safe technical values such as configured limits, but only under staff capability.

## 17. Provider registry contract

A provider should advertise support like:

```json
{
  "id": "core.payments",
  "version": "1",
  "capabilities": [
    "knowledge.fees",
    "payment.read_own_status"
  ],
  "diagnostics": ["payment.status"],
  "classification": {
    "fee_policy": "public",
    "submission_payment": "protected"
  }
}
```

Third-party providers can register through a documented PKP hook/service contract introduced by v2.

## 18. Knowledge correctness tests

Each core provider requires tests proving:

- correct journal scoping;
- correct locale selection/fallback;
- private fields never render in public knowledge;
- configured fee/currency match OJS source;
- generated root links include all generated pages;
- fingerprints change when authoritative source changes;
- unchanged source does not trigger unnecessary sync;
- disabled/unavailable provider fails safely;
- HTML is sanitized/escaped;
- multi-journal content cannot cross-contaminate.

## 19. Knowledge health UI

Per journal, report:

- providers enabled/healthy/unavailable;
- public knowledge last generated;
- current fingerprint;
- Captain document provisioned/not provisioned;
- last Captain sync / sync error where available;
- stale status;
- public generated-page links;
- excluded protected/staff providers count.

This gives administrators evidence about what Captain actually knows rather than assuming it is current.