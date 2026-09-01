# v2.0.0.0 Real-World Acceptance Test Matrix

This is a real acceptance pass against the live, shared infrastructure on
`dell` (OJS at `https://ojs-demo.airixmedia.com`, Chatwoot Enterprise at
`https://support.airixmedia.com` — note the owner's initial instruction
spelled this `aitrixmedia.com`, which does not resolve; the real, live
Chatwoot host is `airixmedia.com`, confirmed by DNS/HTTP and by the
existing OJS plugin's own `chatwootBaseUrl` setting). CI, unit tests,
source inspection, and the earlier release-engineering upgrade test are
**not** treated as substitutes here — every row below reflects an actual
exercise of the running application, or an explicit, evidenced BLOCKED /
NOT APPLICABLE.

Secrets (API tokens, HMAC secrets, webhook secrets) are never printed in
this document or in any commit/PR built from this pass — only
existence/length/behavior is recorded.

## Environment

| Field | Value |
| --- | --- |
| Dell OJS container | `ojs-fresh-ojs-1` (bind-mounts plugin source from `/home/hendrix/ojs-fresh/plugins-src/ojs-chatwoot-integration`, a real git checkout of this repo) |
| OJS URL | `https://ojs-demo.airixmedia.com` (routed via Cloudflare Tunnel + local nginx) |
| OJS version | 3.5.0.5 (confirmed via page generator meta tag and `version.xml`) |
| DB | MySQL 8.0 (`ojs-fresh-db-1`), single journal `ajdsi` (journal_id=1) |
| Plugin code under test | `5cc04bc86f7e19e0df9d282f96f3d60d9e82b796` — the exact `v2.0.0.0` release commit, checked out live into the demo's plugin directory for this pass (was previously on an older TST-014-era commit) |
| Chatwoot URL | `https://support.airixmedia.com` (owner-supplied `aitrixmedia.com` does not resolve — documented discrepancy) |
| Chatwoot version/edition | `chatwoot/chatwoot:v4.14.2`, `CW_EDITION=ee` (Enterprise) |
| Captain | Enabled (`Account#feature_enabled?("captain_integration")` → true, confirmed via `rails runner`). A real "OJS Demo (AJDSI)" WebWidget inbox (id 15) already exists, but **no Captain assistant is bound to it yet** — Sync/Repair Captain has never been run for this integration (existing custom tools/scenarios in the account belong to unrelated inboxes) |
| Mail | OJS `config.inc.php` routes SMTP to local Mailpit (`ojs-fresh-mailpit-1`, UI on :8025) by design, so demo/test mail never consumes the real Brevo sending allowance; Brevo settings are preserved in config but not the active demo transport |
| Payment | Not yet inspected this pass (Bachs/Paystack) |

## Fresh install / plugin lifecycle

| ID | Feature | Environment | Test | Expected | Result | Evidence | Defect/PR |
| -- | ------- | ----------- | ---- | -------- | ------ | -------- | --------- |
| LIFE-01 | Exact `v2.0.0.0` release code runs live | ojs-demo.airixmedia.com | Checked out plugin source to exact commit `5cc04bc`, reloaded journal homepage | 200, no fatal error | **PASS** | `curl -H Host:ojs-demo.airixmedia.com http://localhost:8181/index.php/ajdsi/` → HTTP 200, real page content, no error in container logs since swap | — |
| LIFE-02 | v2 DB migration idempotent across code versions | ojs-demo.airixmedia.com | Confirmed all 5 `chatwoot_support_*` tables already exist after code swap; migration guards (`Schema::hasTable`) prevent re-run damage | Tables present, no migration error | **PASS** | `SHOW TABLES LIKE '%chatwoot%'` → 5 tables | — |
| LIFE-03 | Clean install of the exact `.tar.gz` artifact into a fresh OJS instance | separate clean OJS instance on dell (not yet created) | Not yet run | package upload/install/enable/settings/disable/re-enable produce no fatal errors | **PENDING** | — | — |
| LIFE-04 | v1.0.0.2 → v2.0.0.0 upgrade path | already proven earlier this project (real live upgrade, TST-004/RUN-001) | Retained as separate acceptance case per instructions, not re-run | — | **PASS (prior evidence)** | `docs/v2/TASKLIST.md` TST-004/RUN-001 | — |

## REST Support Gateway (14 operations)

| ID | Feature | Test | Expected | Result | Evidence | Defect/PR |
| -- | ------- | ---- | -------- | ------ | -------- | --------- |
| REST-01 | `/status` wrong verb (GET) | GET instead of POST | 405 VALIDATION_ERROR | **PASS** | Live response: `405`, `{"code":"VALIDATION_ERROR","message":"This endpoint only accepts POST."}` | — |
| REST-02 | `/status` over plain HTTP, no forwarded-proto trust | POST direct to app container, no `X-Forwarded-Proto` | 400 AUTHENTICATION_FAILED, "HTTPS is required." | **PASS** | Live response confirms API-007's transport gate is active | — |
| REST-03 | `/status` real HTTPS + real Bearer token | POST to `https://ojs-demo.airixmedia.com/.../ojsSupportGateway/status` with the real configured `chatwootSupportApiToken` | 200 (or a well-formed unverified-identity success body) | **FAIL → fixed** | Live response: `401 AUTHENTICATION_FAILED` even with the correct token. Root cause: Apache/mod_php (`apache2handler` SAPI) does not populate `$_SERVER['HTTP_AUTHORIZATION']`; confirmed via a direct PHP header-introspection script (`getallheaders()` saw the header, `$_SERVER` did not). **Fixed in PR #141 (TST-017)** — retest pending merge + redeploy | https://git.airixmedia.com/thathman/ojs-chatwoot-integration/pulls/141 |
| REST-04 | `/status` missing/bad token | omit Authorization / wrong token | 401 AUTHENTICATION_FAILED | **PASS** (same real behavior as REST-03's failure case, i.e. correctly denies — the defect is that it *also* denies the valid case) | Live responses, all 401 | — |
| REST-05 | `/status` unknown field in body | add `unknownField=hax` | explicit rejection, no fatal error | **PASS** (retested live after #141 deployed) | `400 VALIDATION_ERROR "Unexpected field: unknownField."` | #141 |
| REST-06 | wrong journal path | POST to `/index.php/nonexistentjournal/ojsSupportGateway/status` | 404, no fatal error, no information leak | **PASS** | Live: `404 Not Found` | — |
| REST-07 | `identity`, `actions`, `submissionVerify`, `submissions`, `submissionSupport`, `requiredActions`, `publicationStatus` — valid auth, fake/unbound tuple | POST each with real token + fake conversation tuple | 200, `verified:false, assurance:v0`, no fatal error | **PASS** | All 7 returned live `200` with correct unverified-identity shape after #141 deployed | — |
| REST-08 | `paymentStatus` — valid auth, fake tuple | as above | 200, correct fee/currency surfaced even for unverified caller (public fee info) | **PASS** | Live: `feeEnabled:true, amount:50000, currency:"NGN"` — confirms real payment config (see Payment section) | — |
| REST-09 | `accountDiagnostics`/`submissionDiagnostics` — invalid `scope` | wrong scope value | 400 with the real valid-scope list in the error message | **PASS** | Live: lists real scopes (`account_access, login, password_reset, profile, mail_configuration` / `submission_access, submission_progress, required_action, review_access, publication, payment, required_files, upload_limit`) | — |
| REST-10 | `accountDiagnostics` — valid scope | `scope=login` | 200 | **PASS** | Live 200, `diagnosed:false` (unverified caller) | — |
| REST-11 | `escalate` | fake tuple + reason | 200, `escalated:true, noteCreated:false` for an unbound/fake conversation (must not create a real Chatwoot note for a nonexistent conversation) | **PASS** | Live 200 with correct `noteCreated:false` | — |
| REST-12 | `verificationRequest` — real registered user email | real email, real purpose | 200, real PIN email delivered via the journal's mail transport | **FAIL → fixed (2 real defects)** | See TST-018 and TST-019 below | #143, #144 |
| REST-13 | `verificationConfirm` | pending real end-to-end PIN confirm once #144 is deployed | 200, `verified:true` on correct PIN | **PENDING** (redeploy of #144 not yet done as of this matrix snapshot) | — | #144 |
| REST-14 | HTTPS/transport gate (API-007) | plain HTTP request, no forwarded-proto trust | 400 `HTTPS is required.` | **PASS** | Confirmed both via direct-to-app HTTP and via spoofed `X-Forwarded-Proto` without going through the real proxy | — |

## MCP Gateway

| ID | Feature | Test | Expected | Result | Evidence | Defect/PR |
| -- | ------- | ---- | -------- | ------ | -------- | --------- |
| MCP-01 | Same Bearer-auth mechanism as REST | inferred from REST-03's root cause (`ChatwootIntegrationV2Plugin::mcpRequest()` used the identical `$_SERVER['HTTP_AUTHORIZATION']` read) | — | **FAIL → fixed** | PR #141 fixes both call sites | #141 |
| MCP-02..* | `tools/list`, each `tools/call`, `resources/list`, each `resources/read`, malformed JSON-RPC, unknown tool, protocol revision, cross-journal | — | — | **PENDING** — not yet executed live | — | — |

## TST-018 — identity refresh / real-user verification never worked (SEVERE)

Real acceptance testing found `verificationRequest` returned success but
**never actually sent mail for a real, enabled OJS user**
(`r.adeyemi@example.com`, user_id=2). Root-caused with a temporary,
uncommitted `file_put_contents` diagnostic on dell's checkout (reverted
before any commit): `Ojs35CompatibilityAdapter::getUserByEmail()` and
`getUserById()` both called `\PKP\user\Repo`, a class that **has never
existed** in OJS 3.5 — confirmed via both a local `pkp-lib`
`stable-3_5_0` checkout and the real live container
(`docker exec ojs-fresh-ojs-1 grep ... classes/facades/Repo.php` shows no
`user()` method on `\PKP\facades\Repo`; the real method lives on the
app-level `\APP\facades\Repo`, which even `lib/pkp/pages/login/
LoginHandler.php` itself imports). `getUserById()` backs
`ContextResolver::resolveContextForUser()` — the identity-refresh step
every verified Support API call runs after a real conversation binds —
so **no real authenticated support session had ever fully resolved a
fresh identity in production**. Every existing unit test mocked its own
fictional `\PKP\user\Repo` class matching the same wrong namespace,
so the whole suite was internally consistent and blind to the bug.

**Fixed in PR #143** (merged into `v2-dev`, commit `8720b73`). Redeployed
to ojs-demo.airixmedia.com and retested live: `verificationRequest` for
`r.adeyemi@example.com` now delivers a real PIN email via Mailpit
("Support verification for AIRIX Journal of Digital Scholarship and
Innovation", real 6-digit PIN observed in the message body). **PASS**
(mail delivery confirmed; full PIN-confirm round trip pending TST-019's
fix, see below, since the two bugs compounded on the same endpoint).

## TST-019 — verificationRequest never returned a usable challenge reference

Immediately after confirming TST-018's fix (real mail now sends),
attempting the natural next step — calling `verificationConfirm` with the
real PIN — surfaced a second, independent, real defect:
`verificationRequest`'s response was always exactly
`{"verificationRequested":true}`, with no field carrying the opaque
`challenge` reference `verificationConfirm` requires (confirmed against
the real DB row: `chatwoot_support_verification_challenges.public_reference
= 9e761f2ea26b849d7649887b28421dd6`, never surfaced anywhere in the API
response). **No real Chatwoot client could ever have completed the PIN or
link verification flow, in any deployment, since this endpoint was
written** — independent of TST-018, and not something CI, unit tests, or
source inspection had caught, since no existing test asserted the
response body's actual shape end-to-end.

**Fixed in PR #144** (open as of this matrix snapshot): the endpoint now
generates a same-shape dummy reference unconditionally, before the
found/not-found branch runs, and always returns it — a real challenge's
reference replaces the dummy only on the real success path, preserving
anti-enumeration (every other branch's dummy never matches a stored row).
**Result: FAIL → fixed, live redeploy + full PIN-confirm retest pending.**

## Remaining domains (not yet executed this pass)

Admin UI, real Chatwoot widget behavior, support session binding, external
verification (PIN/link via Mailpit), authors/reviewers workflow states,
Knowledge Compiler routes, Captain Sync/Repair (real provisioning against
the existing but unbound "OJS Demo (AJDSI)" inbox), Event Bridge delivery
modes, queue/dead-letters, scheduled tasks, mail, payment (Bachs/Paystack),
publication status, diagnostics, human handoff, multi-journal isolation
(a second journal does not yet exist on this instance and needs to be
created), and security cross-cutting checks are still pending and will be
appended to this document as they are actually executed against the real
environment.

## Running totals (partial — pass in progress)

```
TOTAL TESTS: 22
PASS: 16
FAIL: 3 (all fixed — REST-03/MCP-01 PR #141, REST-12/TST-018 PR #143, REST-12/TST-019 PR #144)
BLOCKED: 0
NOT APPLICABLE: 0
PENDING (not yet executed / retest pending redeploy): REST-13, LIFE-03, MCP-02+, and everything in "Remaining domains" below
```

Production acceptance is **not yet decided** — this document will be
updated continuously as testing proceeds.
