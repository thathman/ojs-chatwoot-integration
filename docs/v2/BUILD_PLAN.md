# v2 Build Plan

## Development rule

All v2 work targets `v2-dev` or feature branches created from it. `main` remains the v1/stable line until a separately reviewed v2 release candidate is ready. No implementation phase begins until the relevant spec/ADR and acceptance criteria are present.

## Phase 0 — Foundation, gallery baseline and v1 decomposition

### Goal

Make the repository safe to evolve and release before adding ambitious features.

### Work

- Add GPL-compatible root licence and release metadata.
- Establish docs/product bible, ADRs, security policy and contribution rules.
- Preserve existing `chatwootIntegration` product/folder identity.
- Inventory v1 settings, hooks, API behavior and backwards-compatibility requirements.
- Split monolithic responsibilities behind service interfaces without changing behavior.
- Create compatibility adapters for OJS 3.5 and later tested versions.
- Introduce automated test scaffolding and CI.
- Add package/build validation using PKP tooling.
- Review secret handling; stop exporting secrets by default in v2.
- Create migration strategy for existing v1 installations/settings.

### Exit criteria

- v1 behavior covered by baseline tests.
- plugin installs/enables/disables without fatal error on the initial supported OJS matrix.
- package can be built as one `chatwootIntegration` directory in `.tar.gz`.
- root licence present.
- no implementation claims exceed `VERIFICATION_MATRIX.md`.

## Phase 1 — Context Engine and Chatwoot bridge hardening

### Goal

Turn v1 contextual awareness into explicit, testable services.

### Work

- `ContextService` / context DTO.
- authenticated OJS user resolver.
- journal-role resolver.
- page/resource context adapters.
- Chatwoot widget service and HMAC identity hardening.
- safe custom-attribute contract.
- contextual launcher intents.
- replace role-wide reviewer masking with relationship-aware policy foundation.
- Chatwoot API client refactor, retry/idempotency groundwork.
- capability/feature detection for Chatwoot where possible.

### Exit criteria

- logged-in vs guest context deterministic.
- multi-journal context isolation tests pass.
- Chatwoot HMAC identity works.
- widget outage never breaks OJS page rendering.
- no custom attribute is treated as authorization.

## Phase 2 — Verification Engine and Support Sessions

### Goal

Support secure identity for both embedded OJS users and external channels.

### Work

- verification challenge migration/repository.
- support session migration/repository.
- server-side authenticated-OJS support handshake prototype and chosen ADR.
- OJS Mailable for verification code.
- secure-link verification flow.
- request/confirm endpoints.
- rate limiting, expiry, replay protection, resend controls.
- anti-enumeration response behavior.
- session revocation/cleanup scheduled task.
- audit events for verification lifecycle.

### Exit criteria

- logged-in user can reach V2 without PIN and without LLM-supplied user ID becoming authority.
- external user can verify by OJS-delivered PIN/link.
- expired/replayed/forged challenges fail.
- account existence cannot be inferred from public response/timing within practical test tolerance.
- verification is conversation/context scoped.

## Phase 3 — Relationship, Capability and Support API

### Goal

Create the secure live-data plane Captain and MCP can use.

### Work

- Relationship Resolver.
- Policy/Capability Engine.
- public/staff consumer planes.
- support serializers/field allowlists.
- REST authentication/correlation/rate limiting.
- normalized error envelope.
- `get_support_identity`.
- `list_my_submissions`.
- `get_submission_support`.
- `get_required_actions`.
- `get_available_actions`.
- Support State Engine v1 mappings.
- blind-review redaction tests.
- OpenAPI/schema contract.

### Exit criteria

- horizontal IDOR test fails closed.
- author cannot obtain reviewer identity/internal fields.
- multi-role users are authorized by resource relationship, not global role.
- every protected endpoint records allow/deny audit.
- Captain receives normalized DTOs only.

## Phase 4 — Journal Knowledge Compiler and Captain provisioning

### Goal

Make Captain reliably know the journal from OJS-owned sources.

### Work

- Knowledge Provider interfaces/registry.
- core journal/contact provider.
- submission-guidelines provider.
- review-policy provider.
- publication/open-access provider.
- payment/APC provider.
- DOI provider.
- official-page/navigation provider.
- classification/provenance/fingerprint model.
- generated `/support-knowledge/` pages + sitemap.
- sanitization/private-data exclusion.
- optional Captain Document provisioning/sync.
- optional canonical Custom Tool provisioning.
- optional Scenario provisioning.
- drift/health UI.

### Exit criteria

- APC/public facts match OJS configuration.
- generated knowledge contains no protected/staff data.
- root page directly links all generated pages.
- changing authoritative OJS data changes fingerprint/content.
- Captain document can be provisioned/synced where feature/API available, otherwise a clear manual path exists.

## Phase 5 — Payments, publication and diagnostics

### Goal

Answer the most common real support questions with evidence.

### Work

- protected publication-fee status provider.
- publication/issue/DOI support provider.
- account diagnostic rules.
- submission-flow diagnostics.
- upload/configuration diagnostics.
- payment diagnostics.
- publication/DOI diagnostics.
- mail configuration/send-path diagnostics.
- public vs staff evidence serializers.
- `unknown`/`needs_human` behavior.

### Exit criteria

- “How much is publication?” answered from OJS source.
- authorized “Have I paid?” returns safe status.
- unauthorized payment query fails closed.
- diagnostics never fabricate a cause.
- staff evidence is unavailable to public plane.

## Phase 6 — Event Bridge, proactive support and handoff

### Goal

Make Chatwoot continuously aware of meaningful OJS changes without creating noisy or unsafe messaging.

### Work

- normalize event model.
- queued/idempotent event delivery.
- decision/submission/publication event migration from v1.
- revision/review/payment/DOI events where stable hooks/providers exist.
- configurable delivery policy: context update/private note/open-update/customer message.
- proactive-message opt-in per journal/event.
- structured human handoff summary.
- dead-letter/retry/health visibility.

### Exit criteria

- duplicate upstream event does not create duplicate customer-facing effect.
- private note context is safe.
- public outbound messages only occur for configured events.
- Chatwoot outage does not block OJS workflow.

## Phase 7 — MCP adapter and provider SDK

### Goal

Expose the Support Core beyond Captain and make the gateway extensible.

### Work

- select supported MCP protocol/version/library strategy.
- MCP client authentication/registration.
- public read tool/resource definitions.
- separate staff MCP capabilities.
- shared REST/MCP contract tests.
- provider registration hooks/documentation.
- reference third-party provider example.
- OpenClaw integration test.

### Exit criteria

- MCP and REST return equivalent authorized domain results.
- MCP cannot bypass Policy Engine.
- public MCP client cannot invoke staff tools.
- no Chatwoot-native MCP dependency.

## Phase 8 — Hardening, compatibility and Plugin Gallery release

### Goal

Ship a stable, reviewable `2.0.0.0` candidate.

### Work

- complete security test matrix.
- complete OJS compatibility matrix.
- PHP/database matrix through PKP-compatible testing approach.
- accessibility/localization review.
- upgrade/migration/uninstall tests.
- performance/load checks for knowledge generation and support endpoints.
- dependency/SBOM review where applicable.
- package audit: no dev demos/examples/secrets/nonessential dependency files.
- build immutable `.tar.gz` release.
- calculate MD5.
- create public GitHub release/tag.
- prepare Plugin Gallery XML snippet.
- make repository/release publicly accessible before Gallery submission.
- open Plugin Gallery PR and address automated/code review findings.

### Exit criteria

- exact OJS compatibility entries backed by green tests.
- public immutable package downloadable.
- package extracts to single `chatwootIntegration` directory.
- four-part version everywhere.
- root GPL-compatible licence.
- Gallery XML validates and MD5 matches.
- no known high/critical security issue.
- docs accurately distinguish Core vs Captain vs MCP requirements.

## Release train after 2.0

- patch: `2.0.0.x` for backwards-compatible fixes where PKP versioning/release policy supports the chosen sequence;
- minor capability release: `2.x.0.0` according to documented project policy;
- every published Gallery package is immutable;
- never retarget/replace an already-published Gallery artifact;
- new OJS compatibility is added only after testing, via Gallery metadata update or new plugin release when code changes are required.

## Definition of Done for every task

A task is not done until applicable items are complete:

- implementation;
- unit tests;
- integration/contract tests;
- security/privacy impact reviewed;
- localization strings used instead of hard-coded customer UI text;
- no secrets/PII in logs;
- docs/API schema updated;
- compatibility adapter impact reviewed;
- migration/upgrade impact reviewed;
- changelog/ADR updated when behavior or architecture changes;
- acceptance criteria demonstrated.