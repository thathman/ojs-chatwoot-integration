# v2 Master Tasklist

This is the implementation backlog. Checkboxes are not release claims; they become complete only with acceptance tests.

## FND — Foundation

- [ ] **FND-001** Add root GPL-compatible `LICENSE`.
- [ ] **FND-002** Add root `SECURITY.md` and private vulnerability process.
- [ ] **FND-003** Add `CONTRIBUTING.md` and v2 branch/review conventions.
- [ ] **FND-004** Inventory all v1 settings and classify keep/migrate/deprecate/remove.
- [ ] **FND-005** Inventory all v1 hooks against supported OJS versions.
- [ ] **FND-006** Baseline v1 widget/API/event regression tests.
- [ ] **FND-007** Define service/container structure for v2 modules.
- [ ] **FND-008** Create OJS compatibility adapter interface(s).
- [ ] **FND-009** Build install/upgrade/uninstall migration framework.
- [ ] **FND-010** Remove secrets from normal settings export/default diagnostics.
- [ ] **FND-011** Add coding/static-analysis rules compatible with PKP target.
- [ ] **FND-012** Add CI skeleton for package/tests.

## CTX — OJS Context

- [ ] **CTX-001** Implement journal/context resolver.
- [ ] **CTX-002** Implement authenticated user resolver.
- [ ] **CTX-003** Implement current journal role resolver.
- [ ] **CTX-004** Implement page/operation context DTO.
- [ ] **CTX-005** Implement article context adapter.
- [ ] **CTX-006** Implement submission/workflow context adapter.
- [ ] **CTX-007** Implement review context adapter.
- [ ] **CTX-008** Implement payment/support intent context.
- [ ] **CTX-009** Add multi-journal isolation tests.
- [ ] **CTX-010** Add locale normalization/fallback tests.

## CWO — Chatwoot Connector

- [ ] **CWO-001** Refactor Chatwoot API client behind interface.
- [ ] **CWO-002** Preserve/verify HMAC `setUser` integration.
- [ ] **CWO-003** Define safe custom-attribute schema.
- [ ] **CWO-004** Remove any authorization dependency on custom attributes.
- [ ] **CWO-005** Implement contextual launcher intents.
- [ ] **CWO-006** Add idempotent contact/conversation lookup strategy.
- [ ] **CWO-007** Add queued retry/dead-letter structure.
- [ ] **CWO-008** Detect/report Captain API feature availability where possible.
- [ ] **CWO-009** Implement optional Captain Document provisioning.
- [ ] **CWO-010** Implement optional Captain Custom Tool provisioning.
- [ ] **CWO-011** Implement optional Captain Scenario provisioning.
- [ ] **CWO-012** Ensure provisioner never deletes unrelated admin resources.
- [ ] **CWO-013** Add configuration drift/health report.

## IDN — Identity & Verification

- [ ] **IDN-001** Define verification assurance levels and data types.
- [ ] **IDN-002** Create verification challenge migration/repository.
- [ ] **IDN-003** Create support session migration/repository.
- [ ] **IDN-004** Prototype secure OJS-session ↔ Chatwoot conversation binding.
- [ ] **IDN-005** Write ADR selecting authenticated-session handshake.
- [x] **IDN-006** Implement logged-in OJS silent V2 identity path. — authenticated OJS session bootstraps a V2 support session with no PIN/email step (`SupportSessionService::bootstrapAuthenticated`).
- [ ] **IDN-007** Implement OJS verification-code Mailable/template.
- [ ] **IDN-008** Implement verification request endpoint.
- [ ] **IDN-009** Implement verification confirm endpoint.
- [ ] **IDN-010** Implement secure verification link.
- [ ] **IDN-011** Store only challenge secret hash.
- [ ] **IDN-012** Implement single-use/expiry/revocation.
- [ ] **IDN-013** Implement resend invalidation/cooldown.
- [ ] **IDN-014** Implement attempt lockout/rate limits.
- [ ] **IDN-015** Implement anti-enumeration response/timing tests.
- [ ] **IDN-016** Implement support-session idle/absolute expiry.
- [ ] **IDN-017** Implement session revocation/cleanup task.
- [ ] **IDN-018** Implement conversation/context binding checks.
- [ ] **IDN-019** Audit verification lifecycle.

## REL — Relationship Resolver

- [x] **REL-001** Define normalized relationship enum/model. — `ResourceRelationship` (types set, not single role).
- [x] **REL-002** Resolve author/submitter relationship. — via workflow-stage access (`OjsSubmissionRelationshipEvidenceProvider`).
- [x] **REL-003** Resolve reviewer relationship per review assignment. — actual `Repo::reviewAssignment()` lookup, not the journal-level Reviewer role.
- [x] **REL-004** Resolve editor/sub-editor/assistant/manager/site-admin relationship.
- [ ] **REL-005** Resolve reader/subscriber relationship if used.
- [x] **REL-006** Confirm context/resource ownership before relationship evaluation. — `SubmissionRelationshipResolver` fails closed on a cross-journal submission.
- [x] **REL-007** Multi-role resource-specific relationship tests. — `tests/v2/submission-verify.php` (author+reviewer same submission, plural, not collapsed).
- [x] **REL-008** Horizontal IDOR tests. — `tests/v2/submission-verify.php` (guessed submission ID, cross-journal submission, unrelated user all resolve to empty/no-error).

## POL — Capability & Privacy Policy

- [ ] **POL-001** Define capability namespace.
- [ ] **POL-002** Implement deny-by-default Policy Engine.
- [ ] **POL-003** Define public-support consumer plane.
- [ ] **POL-004** Define staff consumer plane.
- [ ] **POL-005** Implement author support policy.
- [ ] **POL-006** Implement reviewer support policy.
- [ ] **POL-007** Implement staff read policy.
- [ ] **POL-008** Implement field allowlist serializers.
- [ ] **POL-009** Implement blind-review author serializer tests.
- [ ] **POL-010** Implement reviewer anonymity serializer tests.
- [ ] **POL-011** Replace v1 global reviewer masking behavior.
- [ ] **POL-012** Implement `get_available_actions`.
- [ ] **POL-013** Audit allow/deny reason codes.

## API — REST Support API

- [x] **API-001** Implement API route/PageHandler strategy per supported OJS versions. — `LoadHandler` + `SupportGatewayPageHandler`, same pattern as `/bind`; status/identity/actions operations.
- [x] **API-002** Implement service authentication. — `ServiceTokenAuthenticator`, Bearer token, comma-separated for rotation, constant-time comparison.
- [x] **API-003** Require HTTPS/config check for production. — `SupportApiRequestResolver::transportSecure()`; accepts direct HTTPS or `X-Forwarded-Proto: https` from a fronting reverse proxy. No trusted-proxy allowlist yet (see its docblock).
- [x] **API-004** Implement correlation/request IDs. — `CorrelationId`; echoes a caller-supplied `X-Correlation-Id` when it matches a safe pattern, otherwise generates one.
- [x] **API-005** Implement common response/error envelope. — `SupportApiResponse` (`{ok,data,meta}` / `{ok,error,meta}`), used by status/identity/actions. `/bind` intentionally keeps PKP `JSONMessage` (browser handshake, separate transport).
- [ ] **API-006** Implement request validation/unknown-field rejection. — required-field presence is validated; extra/unknown fields are not yet rejected.
- [ ] **API-007** Implement REST rate limits. — best-effort fixed-window limiter (`RateLimiter`) exists and is wired in, but fails open without APCu and is per-worker only; not yet a real cross-worker ceiling.
- [x] **API-008** `ojs_get_support_identity`. — `POST /ojsSupportGateway/identity`, sanitized via `SupportIdentitySerializer` (no email, no raw relationship evidence, no raw OJS object).
- [x] **API-009** `ojs_list_my_submissions`. — `POST /ojsSupportGateway/submissions`, gated on `submission.list_own`, candidates from the OJS-native submission collector filtered through `SubmissionRelationshipResolver`. No milestone/date field yet.
- [ ] **API-010** `ojs_get_submission_support`.
- [ ] **API-011** `ojs_get_required_actions`.
- [ ] **API-012** `ojs_get_payment_status`.
- [ ] **API-013** `ojs_get_publication_status`.
- [ ] **API-014** `ojs_diagnose_account`.
- [ ] **API-015** `ojs_diagnose_submission`.
- [ ] **API-016** `ojs_escalate_support`.
- [ ] **API-017** Generate OpenAPI/machine-readable schema.
- [ ] **API-018** Contract-test all public responses.
- [ ] **API-019** Keep Captain-provisioned custom tools <=12.

## STA — Support State Engine

- [x] **STA-001** Define normalized support states. — `SupportStateMapper` (`classes/v2/State/`) now covers draft/submitted/review_in_progress/revision_requested/copyediting_in_progress/production_in_progress/published/declined/scheduled_for_publication/unknown. Correction to an earlier note: draft did *not* need a separate candidate-discovery path — OJS 3.5 creates the author's StageAssignment immediately at submission creation (verified against `pkp-lib` stable-3_5_0 `PKPSubmissionController::add()`), so the existing `assignedTo()`-based discovery already reaches drafts; only `submissionProgress` needed reading. Still missing: revision_received (needs revision-file evidence).
- [x] **STA-002** Map submission stages/statuses for OJS 3.5 target. — `status`/`stageId` mapped as before; `revision_requested` now additionally derived from the current review round's live `status` column (`ReviewRoundDAO::getLastReviewRoundBySubmissionId`, verified against `pkp-lib` stable-3_5_0), read-only, never recomputed independently.
- [ ] **STA-003** Map OJS 3.6 target after compatibility verification.
- [ ] **STA-004** Determine safe reviewer-progress facts without identities.
- [ ] **STA-005** Determine author-action-required rules.
- [ ] **STA-006** Determine revision status/deadline rules.
- [ ] **STA-007** Determine copyediting/production/publication rules.
- [ ] **STA-008** Return confidence/evidence codes.
- [x] **STA-009** Unknown-state fallback tests. — `tests/v2/submission-list.php` covers unrecognized status, unrecognized stageId, and missing status/stageId all falling back to `unknown` rather than a guess.

## KNO — Journal Knowledge Compiler

- [ ] **KNO-001** Define `KnowledgeProvider` contract.
- [ ] **KNO-002** Define classification/provenance model.
- [ ] **KNO-003** Core journal/contact provider.
- [ ] **KNO-004** Submission guidelines/checklist provider.
- [ ] **KNO-005** Sections/languages provider.
- [ ] **KNO-006** Review policy provider.
- [ ] **KNO-007** Publication/open-access/licence provider.
- [ ] **KNO-008** APC/payment public policy provider.
- [ ] **KNO-009** DOI/public identifier provider.
- [ ] **KNO-010** Official page/navigation provider.
- [ ] **KNO-011** Approved FAQ/support knowledge provider.
- [ ] **KNO-012** Fingerprinting/staleness model.
- [ ] **KNO-013** Generate `/support-knowledge/` root.
- [ ] **KNO-014** Generate category pages.
- [ ] **KNO-015** Generate sitemap.
- [ ] **KNO-016** HTML sanitization/escaping.
- [ ] **KNO-017** Private-data exclusion tests.
- [ ] **KNO-018** Multi-locale support.
- [ ] **KNO-019** Captain document sync trigger/manual fallback.
- [ ] **KNO-020** Knowledge health UI.

## PAY — Payment & Publication

- [ ] **PAY-001** Read public publication fee/currency through OJS adapter.
- [ ] **PAY-002** Resolve completed publication payment for authorized submission.
- [ ] **PAY-003** Normalize `not_applicable|unpaid|paid|waived|unknown`.
- [ ] **PAY-004** Verify unauthorized submission payment query is denied.
- [ ] **PAY-005** Ensure payment provider failure returns unknown, not unpaid.
- [ ] **PAY-006** Publication status provider.
- [ ] **PAY-007** Issue assignment/public URL provider.
- [ ] **PAY-008** DOI safe status provider.
- [ ] **PAY-009** Keep public plane payment writes disabled.

## DIA — Diagnostics

- [ ] **DIA-001** Define diagnostic result schema.
- [ ] **DIA-002** Account/login diagnostic.
- [ ] **DIA-003** Registration/reset-path diagnostic.
- [ ] **DIA-004** Submission access diagnostic.
- [ ] **DIA-005** Submission workflow/progress diagnostic.
- [ ] **DIA-006** Required metadata/file diagnostic where deterministic.
- [ ] **DIA-007** Upload PHP/OJS limit diagnostic.
- [ ] **DIA-008** Review access diagnostic.
- [ ] **DIA-009** Payment diagnostic.
- [ ] **DIA-010** Publication/DOI diagnostic.
- [ ] **DIA-011** Mail configuration/send-path diagnostic.
- [ ] **DIA-012** Public vs staff diagnostic serializers.
- [ ] **DIA-013** Unknown/needs-human behavior tests.

## EVT — Event Bridge

- [ ] **EVT-001** Define normalized `SupportEvent` model.
- [ ] **EVT-002** Stable idempotency keys.
- [ ] **EVT-003** Migrate v1 submission-created event.
- [ ] **EVT-004** Migrate v1 decision event.
- [ ] **EVT-005** Migrate v1 status/publication event.
- [ ] **EVT-006** Add revision event adapters where stable.
- [ ] **EVT-007** Add review event adapters where stable.
- [ ] **EVT-008** Add payment event adapters where stable.
- [ ] **EVT-009** Add DOI event adapters where stable.
- [ ] **EVT-010** Delivery policy per journal/event.
- [ ] **EVT-011** Private note delivery.
- [ ] **EVT-012** Open/update conversation delivery.
- [ ] **EVT-013** Opt-in proactive message delivery.
- [ ] **EVT-014** Dead-letter/retry UI.
- [ ] **EVT-015** Replay/duplicate tests.

## HOF — Human Handoff

- [ ] **HOF-001** Define safe handoff summary DTO.
- [ ] **HOF-002** Include verification method/expiry safely.
- [ ] **HOF-003** Include verified relationship/resource.
- [ ] **HOF-004** Include normalized support state/action.
- [ ] **HOF-005** Include safe recent event/diagnostic context.
- [ ] **HOF-006** Explicitly exclude confidential review/editorial data.
- [ ] **HOF-007** Create/update private note idempotently.

## PRV — Provider Registry

- [ ] **PRV-001** Define provider registry service.
- [ ] **PRV-002** Define registration hook/API.
- [ ] **PRV-003** Provider applicability/health contract.
- [ ] **PRV-004** Provider capability/classification contract.
- [ ] **PRV-005** Reference example provider.
- [ ] **PRV-006** Provider isolation/failure tests.
- [ ] **PRV-007** Document third-party integration guide.

## MCP — MCP Adapter

- [ ] **MCP-001** Select protocol/library implementation strategy.
- [ ] **MCP-002** Define MCP authentication/client model.
- [ ] **MCP-003** Implement public/read MCP tool set.
- [ ] **MCP-004** Implement safe public resources.
- [ ] **MCP-005** Separate staff tool namespace/credential.
- [ ] **MCP-006** REST/MCP equivalence contract tests.
- [ ] **MCP-007** Verify public client cannot reach staff capability.
- [ ] **MCP-008** OpenClaw integration test.
- [ ] **MCP-009** Document that Chatwoot-native MCP is not required.

## AUD — Audit & Observability

- [ ] **AUD-001** Audit migration/repository. — placeholder sink only (`ErrorLogSupportApiAuditLogger` writes to `error_log()`); no persisted/queryable audit table yet.
- [x] **AUD-002** Correlation IDs across REST/events/Chatwoot. — Support API side only so far (`CorrelationId`); not yet threaded through the event/Chatwoot side of the plugin.
- [ ] **AUD-003** Verification lifecycle audit.
- [x] **AUD-004** Protected read allow/deny audit. — `SupportApiRequestResolver` records every allow/deny decision (with reason code) through `SupportApiAuditLoggerInterface`.
- [ ] **AUD-005** Staff mutation audit.
- [ ] **AUD-006** Secret/PII log redaction tests.
- [ ] **AUD-007** Configurable retention/purge.
- [ ] **AUD-008** Health dashboard for components/providers/queues.

## TST — Test Program

- [ ] **TST-001** Unit test suite.
- [ ] **TST-002** OJS integration test harness.
- [ ] **TST-003** Chatwoot mock/contract harness.
- [ ] **TST-004** OJS 3.5 exact-version matrix.
- [ ] **TST-005** OJS 3.6 exact-version matrix.
- [ ] **TST-006** Supported MySQL/MariaDB matrix where practical.
- [ ] **TST-007** Supported PostgreSQL matrix where practical.
- [ ] **TST-008** PHP matrix derived from target OJS requirements.
- [ ] **TST-009** Multi-journal tests.
- [ ] **TST-010** Multi-role tests.
- [ ] **TST-011** Blind-review/privacy tests.
- [ ] **TST-012** Verification abuse tests.
- [ ] **TST-013** API contract tests.
- [ ] **TST-014** Upgrade from v1.0.0.2 test.
- [ ] **TST-015** Package install/enable/disable/uninstall smoke test.
- [ ] **TST-016** Chatwoot Captain unavailable/disabled graceful-degradation test.

## SEC — Security Hardening

- [ ] **SEC-001** Threat-model review before Phase 2 merge.
- [ ] **SEC-002** Forged Chatwoot header test.
- [x] **SEC-003** Cross-journal IDOR test. — `tests/v2/submission-verify.php` (submission in another journal resolves no relationship; earlier chatwoot-binding tests cover the conversation-binding side).
- [ ] **SEC-004** Cross-conversation session replay test.
- [ ] **SEC-005** OTP brute-force/rate-limit test.
- [ ] **SEC-006** Prompt-injection/tool-abuse test.
- [ ] **SEC-007** Secrets absent from browser/logs/export.
- [ ] **SEC-008** Public knowledge contains no protected data.
- [ ] **SEC-009** Dependency/security scan.
- [ ] **SEC-010** Security review before stable release.

## RELS — PKP Plugin Gallery Release

- [ ] **RELS-001** Repository is public before Gallery submission.
- [ ] **RELS-002** Public release package available for download.
- [ ] **RELS-003** Root GPL-compatible `LICENSE`.
- [ ] **RELS-004** Tests/CI green.
- [ ] **RELS-005** Build `.tar.gz` with a single `chatwootIntegration` directory.
- [ ] **RELS-006** Remove nonessential dependency-manager demos/examples/dev files.
- [ ] **RELS-007** Four-part release version, e.g. `2.0.0.0`.
- [ ] **RELS-008** Exact OJS compatibility versions tested.
- [ ] **RELS-009** Create immutable GitHub release/tag/package.
- [ ] **RELS-010** Calculate/record MD5 for Gallery XML.
- [ ] **RELS-011** Prepare title/summary/description/homepage/maintainer metadata.
- [ ] **RELS-012** Prepare Gallery XML release snippet.
- [ ] **RELS-013** Validate XML/package URL/MD5.
- [ ] **RELS-014** Open PR to `pkp/plugin-gallery`.
- [ ] **RELS-015** Address PKP automated checks/code review.
- [ ] **RELS-016** Never modify an already-published Gallery release artifact.
- [ ] **RELS-017** Document compatibility-update process for future OJS releases.

## DOC — Documentation

- [ ] **DOC-001** User install/config guide.
- [ ] **DOC-002** Core Chatwoot Bridge guide.
- [ ] **DOC-003** Captain Intelligence prerequisites/provisioning guide.
- [ ] **DOC-004** Verification/security admin guide.
- [ ] **DOC-005** Knowledge provider guide.
- [ ] **DOC-006** MCP setup guide.
- [ ] **DOC-007** REST/OpenAPI guide.
- [ ] **DOC-008** Troubleshooting/health guide.
- [ ] **DOC-009** Upgrade from v1 guide.
- [ ] **DOC-010** Privacy/data-retention guide.
- [ ] **DOC-011** Gallery release runbook.

## Deferred / explicit post-baseline ideas

These remain in the product vision but are not silently included in the initial v2 release:

- [ ] staff write actions such as review reminders/deadline changes;
- [ ] secure author file download links;
- [ ] duplicate-account staff resolution workflow;
- [ ] Crossref/DataCite-specific deep diagnostics;
- [ ] payment-provider-specific remediation actions;
- [ ] automatic support analytics/FAQ approval workflow;
- [ ] proactive WhatsApp/Telegram messaging beyond Chatwoot’s configured channel capabilities;
- [ ] any native Chatwoot MCP integration if upstream later implements one.