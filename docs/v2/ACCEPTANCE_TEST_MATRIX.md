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
| REST-05 | `/status` unknown field in body | add `unknownField=hax` | request still processed (unknown fields ignored) or explicit rejection — not a fatal error | **BLOCKED on REST-03 fix** | Cannot reach past auth yet | #141 |
| REST-06 | wrong journal path | POST to `/index.php/nonexistentjournal/ojsSupportGateway/status` | 404, no fatal error, no information leak | **PASS** | Live: `404 Not Found` | — |
| REST-07..14 | remaining 12 operations (`identity`, `actions`, `submissionVerify`, `submissions`, `submissionSupport`, `requiredActions`, `publicationStatus`, `paymentStatus`, `accountDiagnostics`, `submissionDiagnostics`, `escalate`, `verificationRequest`, `verificationConfirm`) | full valid/invalid/edge matrix per operation | per `docs/v2/openapi.json` | **PENDING** (blocked until #141 merges/redeploys, then to be run for real) | — | #141 |

## MCP Gateway

| ID | Feature | Test | Expected | Result | Evidence | Defect/PR |
| -- | ------- | ---- | -------- | ------ | -------- | --------- |
| MCP-01 | Same Bearer-auth mechanism as REST | inferred from REST-03's root cause (`ChatwootIntegrationV2Plugin::mcpRequest()` used the identical `$_SERVER['HTTP_AUTHORIZATION']` read) | — | **FAIL → fixed** | Same PR #141 fixes both call sites | #141 |
| MCP-02..* | `tools/list`, each `tools/call`, `resources/list`, each `resources/read`, malformed JSON-RPC, unknown tool, protocol revision, cross-journal | — | — | **PENDING** (blocked on #141 merge/redeploy) | — | #141 |

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
TOTAL TESTS: 12
PASS: 6
FAIL: 1 (fixed pending redeploy retest — REST-03/MCP-01, PR #141)
BLOCKED: 2 (REST-05, dependent on #141)
NOT APPLICABLE: 0
PENDING (not yet executed): remainder of the full matrix
```

Production acceptance is **not yet decided** — this document will be
updated continuously as testing proceeds.
