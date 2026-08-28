# v2 Test & Compatibility Plan

## 1. Release principle

The plugin must never advertise “OJS 3.5+” merely because one current version worked. PKP Plugin Gallery compatibility entries are release claims and must be backed by tests against exact OJS versions.

## 2. Compatibility targets

### Initial targets

- OJS 3.5.x: baseline because v1 targeted 3.5+.
- OJS 3.6.x: separate target; current OJS `main` reports 3.6.0.0.

Exact patch releases are selected when building CI/release candidates. A release declares only versions actually passed.

### PHP

Do not invent a plugin-specific PHP floor independent of OJS. Test the PHP versions supported/required by each target OJS release, including its minimum and the project’s chosen newest supported combination.

### Database

Exercise OJS-supported production databases relevant to PKP testing, with at least:

- PostgreSQL path;
- MySQL/MariaDB path where supported by target OJS.

Do not add unsupported database claims.

## 3. Test layers

### Unit

- value objects/DTOs;
- policy/capability rules;
- verification hash/expiry/rate rules;
- support state mappings;
- diagnostics;
- knowledge classification/fingerprint;
- serializers;
- event idempotency;
- URL/safe rendering helpers.

### OJS integration

- plugin install/enable/disable;
- settings persistence/migration;
- current user/context/role resolution;
- repositories/relationships;
- hooks/event capture;
- payment/APC reads;
- Mailable send path;
- custom handler/API routing;
- scheduled cleanup/retry jobs;
- generated knowledge pages.

### Chatwoot contract

Use controlled mocks/fixtures plus optional real integration environment to test:

- widget/HMAC payload contract;
- contact/conversation API calls;
- canned response compatibility retained from v1;
- Captain custom tool provisioning payload;
- Captain document create/sync payload;
- scenario provisioning payload;
- expected `X-Chatwoot-*` tool metadata headers;
- handling feature-disabled/403/404 responses.

Do not require Chatwoot enterprise source code inside the plugin test package.

### End-to-end

At least one representative supported OJS + Chatwoot environment:

1. author logs into OJS;
2. opens support;
3. Chatwoot identity is HMAC verified;
4. support session established;
5. author asks for own manuscript status;
6. Captain/tool call reaches gateway;
7. relationship is validated;
8. safe normalized result returned;
9. author tries a different submission ID and is denied;
10. audit records both outcomes.

External verification E2E:

1. anonymous/external contact asks protected question;
2. generic verification request response;
3. OJS Mailable issued;
4. correct code/link establishes session;
5. wrong/expired/replayed code rejected;
6. protected question succeeds only after relationship check.

## 4. Security test matrix

### Identity/verification

- nonexistent and existing emails have indistinguishable public response;
- resend invalidates old code;
- code stored hashed;
- five failed attempts locks challenge by default;
- expiry enforced;
- challenge cannot be consumed twice;
- challenge cannot move to another journal/conversation;
- support session expires/revokes;
- stale Chatwoot attribute alone cannot authorize.

### Service authentication

- unauthenticated Support API rejected;
- forged `X-Chatwoot-*` headers rejected without valid service auth;
- invalid bearer/API key rejected;
- public credential cannot call staff route;
- rate limits enforced.

### Authorization

- author A cannot read author B submission;
- same numeric resource in different journal cannot cross context;
- reviewer cannot read other reviewer identity;
- author/reviewer multi-role user gets resource-specific view;
- public user cannot query submission payment without relationship;
- staff capability requires staff identity/consumer plane.

### Blind review

Fixtures for single-anonymous/double-anonymous policies as supported by OJS target:

- reviewer identifying data absent from author response;
- hidden author identifying data absent from reviewer response where policy requires;
- internal editorial notes absent;
- confidential files absent;
- output schema contains allowlisted fields only.

### Knowledge leakage

Search generated pages for fixture secrets/PII/reviewer identities/private manuscript metadata. Build fails if found.

### Prompt/tool abuse

- user message instructs model to call tool with another submission ID;
- tool still denies;
- user tries to request internal fields not in schema;
- ignored/rejected;
- user attempts staff action from public plane;
- denied.

## 5. v1 regression matrix

Before refactoring, capture expected behavior for:

- widget enable/disable;
- frontend/backend visibility behavior;
- journal context resolution;
- role-based hiding;
- HMAC user identity;
- locale;
- article/DOI/section context;
- existing event sync;
- Chatwoot API health check;
- retry queue;
- email-template/canned-response sync;
- global/local setting behavior.

Where v2 intentionally changes behavior, record migration/ADR rather than silently breaking tests.

Known intended change: v1 global reviewer masking is replaced by resource-specific field policy.

## 6. Upgrade tests

Starting state: installed/enabled v1.0.0.2 with representative settings/events queued.

Validate:

- upgrade does not lose safe widget/Chatwoot settings;
- secret settings remain server-side and become masked;
- unsafe export behavior is disabled/migrated;
- new tables/migrations apply once;
- retry queue handling is preserved/migrated or explicitly drained;
- disabling/uninstalling follows documented data-retention behavior;
- downgrade is not promised unless explicitly implemented.

## 7. Knowledge tests

For every core provider:

- correct value from OJS source;
- context isolation;
- locale fallback;
- classification required;
- public renderer rejects non-public item;
- HTML sanitized;
- fingerprint stable if no source change;
- fingerprint changes on source change;
- generated root links every category;
- sitemap valid where provided;
- Captain sync failure does not corrupt OJS knowledge.

APC fixture specifically verifies amount/currency against OJS context/payment manager source.

## 8. Support State tests

Create workflow fixtures for supported OJS target and assert normalized states. Tests must explicitly cover unknown/new upstream values so a future OJS change cannot silently map to an incorrect user-facing answer.

Unknown upstream state -> `unknown`/safe fallback, not guessed state.

## 9. Diagnostic tests

Every diagnostic must test:

- positive problem case;
- healthy/no-problem case where meaningful;
- insufficient evidence case;
- provider failure case;
- public output;
- staff-only evidence separation;
- no sensitive exception leakage.

Upload example:

- OJS limit 20 MB + PHP limit 8 MB -> deterministic server-limit finding;
- no failed request/file size evidence -> do not claim that server limit caused a specific prior upload.

## 10. Event tests

- same normalized event delivered twice -> one idempotent effect;
- transient Chatwoot failure -> retry;
- max retry -> dead-letter/health signal;
- OJS workflow request returns without waiting on remote Chatwoot success;
- event policy `note` vs `open_update` vs `customer_message` respected;
- proactive customer message disabled by default;
- sensitive event context filtered.

## 11. Performance tests

Budgets to define during implementation, but test:

- widget/context code adds minimal page overhead and no synchronous remote Chatwoot API call during ordinary page rendering;
- Support API relationship/policy queries avoid N+1 patterns;
- knowledge generation handles realistic journal page/policy sizes;
- event queue does not grow unbounded;
- verification abuse cannot cause unbounded email/database work.

## 12. Failure-mode tests

- Chatwoot unavailable;
- Captain feature disabled;
- invalid Chatwoot API token;
- HMAC secret missing;
- payment provider unavailable;
- provider throws exception;
- knowledge sync fails;
- MCP disabled;
- DB migration partially attempted/rolled back per framework behavior;
- malformed external request;
- unsupported OJS version.

OJS page rendering/editorial workflow must remain operational wherever the failed feature is optional.

## 13. Localization/accessibility

- all user-visible plugin strings localized;
- verification emails localized/fallback safely;
- generated knowledge respects journal locale;
- settings forms use PKP conventions;
- keyboard/focus behavior for contextual launcher does not regress Chatwoot accessibility;
- RTL/multilingual content does not corrupt context.

## 14. Packaging smoke tests

For each release candidate:

- generate `.tar.gz`;
- archive contains exactly one top-level directory named `chatwootIntegration`;
- no `.git`, local secrets, test credentials, build cache, unnecessary dependency-manager demos/examples;
- `version.xml` four-part release value matches Gallery package metadata;
- `LICENSE` included;
- install archive into clean supported OJS;
- enable plugin;
- settings page loads;
- disable/re-enable;
- upgrade from previous supported release;
- calculate and verify MD5 against final immutable archive.

## 15. Gallery release evidence

Attach/record for release PR:

- CI URL/status;
- tested OJS versions;
- PHP/DB combinations;
- archive URL;
- MD5;
- package-content check;
- security review status;
- known limitations;
- compatibility XML entries.

## 16. Re-verification of upstream assumptions

Before stable release, rerun checks in `VERIFICATION_MATRIX.md` against then-current:

- `pkp/ojs` supported release branches/tags;
- PKP Plugin Guide;
- `chatwoot/chatwoot` current stable/develop implementation as appropriate.

If Chatwoot changes Custom Tool count/methods/headers or OJS changes hooks/payment/auth APIs, update adapters/specs before release.