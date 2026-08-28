# PKP Plugin Gallery Release & Compliance Plan

Source of release requirements verified against:

- PKP Plugin Guide: `pkp/pkp-docs/dev/plugin-guide/en/release.md`
- PKP Plugin Gallery repository/process

This is a release gate, not a “nice to have”.

## 1. Current repository blockers

At v2 inception:

1. `thathman/ojs-chatwoot-integration` is **private**.
2. no root `LICENSE` was present in the v1 import.
3. v1 version is `1.0.0.2`, which is a valid four-part form, but v2 needs its own immutable release line.
4. v1 claims “OJS 3.5+” rather than a tested Gallery compatibility list.
5. there is not yet a v2 automated test/release pipeline.

The repository does **not** need to be made public during private development. It must be publicly downloadable before Plugin Gallery submission. Visibility is an owner release decision and must not be changed automatically during development.

## 2. PKP requirements we must satisfy

### Public availability

Every release submitted to the Plugin Gallery must be publicly downloadable. A public source repository is recommended/preferred.

### GPL-compatible licence

The plugin must explicitly declare a GPL-compatible licence, normally through a root `LICENSE` file.

Project decision: GPL v3-compatible licensing; see `LICENSING.md`.

### Tests and reviewability

PKP encourages automated tests and acceptance is improved by a reviewable, maintained plugin. The release must be able to pass Gallery automated checks and human code review.

### Archive format

Release package must be `.tar.gz`.

The archive must contain **one top-level directory**, and that directory name must exactly match the Gallery `product` value.

For this project:

```text
chatwootIntegration-2.0.0.0.tar.gz
└── chatwootIntegration/
    ├── index.php
    ├── version.xml
    ├── LICENSE
    └── ...
```

`product` should remain `chatwootIntegration` unless a deliberate migration changes the installed plugin identity.

### Package hygiene

Do not include unnecessary development/dependency-manager files, demos/examples or content that introduces avoidable security risk. Build tooling should explicitly whitelist release contents or audit the archive.

### Four-part version

PKP Gallery release versions use four numeric components separated by periods.

v2 stable candidate:

`2.0.0.0`

Do not publish `2.0.0` as the Gallery package version.

### Exact compatibility

Each release declares one or more OJS compatibility ranges/versions supported by Gallery metadata. The plugin only appears for compatible application versions.

Rule: declare only OJS versions proven by CI/test evidence. “3.5+” is not release metadata.

### Package URL and MD5

Gallery XML includes a public package URL and MD5 checksum. The final archive is immutable after publication because changing it changes the MD5 and breaks the Gallery record.

### Gallery pull request

Submit/update plugin XML in `pkp/plugin-gallery` according to current Gallery structure/schema. The PR must pass automated validation and human review.

### Certification/review

PKP assigns certification/review status. A plugin can be rejected if review fails. Our job is to make the package, code, licence and tests reviewable.

## 3. Recommended build tooling

Use PKP’s documented plugin release tooling where compatible with the target release process, including `pkp-plugin-cli`/`pkp-plugin` commands referenced in the Plugin Guide.

Expected release command pattern from PKP documentation:

```bash
pkp-plugin release chatwootIntegration --newversion 2.0.0.0
```

The exact command/tool version must be pinned/tested in CI before release.

## 4. Versioning policy

### Development

`v2-dev` is not a release artifact.

### Stable

Use four-part versions in `version.xml` and release metadata.

Initial v2 stable: `2.0.0.0`.

Never edit/retarget a published release tag/package that has entered the Gallery. A fix becomes a new version and new archive/checksum.

## 5. Compatibility matrix policy

### OJS 3.5

Initial preservation target because v1 was designed for OJS 3.5+.

Test exact current/selected 3.5 release(s); declare only those proven compatible.

### OJS 3.6

Current `pkp/ojs` main reports `3.6.0.0`. Treat 3.6 as a separate compatibility target with adapters and tests. Do not declare it until green.

### Future OJS releases

For each new OJS version:

1. run compatibility CI;
2. re-check hooks/API/payment/mail surfaces;
3. add adapters/code if needed;
4. only then update Gallery compatibility metadata or release a new plugin version if code changed.

## 6. Licence/repository-publication sequence

Recommended sequence:

1. develop on private repo if desired;
2. include GPL-compatible root `LICENSE` in source and archive;
3. complete security review and purge secrets/history concerns;
4. create release candidate;
5. make source/release publicly accessible when owner approves;
6. create public immutable GitHub release asset `.tar.gz`;
7. calculate MD5 from exact asset;
8. submit Gallery PR.

Do not make a private package URL the Gallery URL.

## 7. Release archive allowlist

At minimum include runtime-required files:

- plugin entry/index;
- PHP source/services/handlers/providers/migrations;
- templates/assets;
- locale files;
- `version.xml`;
- root `LICENSE` inside the plugin archive;
- runtime metadata required by PKP.

Exclude unless runtime-required:

- `.git/`;
- `.github/` development workflow files from archive if unnecessary;
- local `.env`/secrets;
- test fixtures/credentials;
- development caches/build outputs;
- IDE files;
- docs that unnecessarily inflate runtime package (repository docs can remain in source repo);
- dependency-manager examples/tests/demos not needed at runtime.

## 8. Gallery XML template

The exact schema/element names must be refreshed from `pkp/plugin-gallery` at release time. Conceptually the package metadata must include:

```xml
<plugin category="generic">
  <product>chatwootIntegration</product>
  <name locale="en">Chatwoot Integration for OJS</name>
  <description locale="en">...</description>
  <homepage>PUBLIC_REPOSITORY_OR_PROJECT_URL</homepage>
  <maintainer>
    <!-- use actual maintainer details; do not invent -->
  </maintainer>
  <release date="YYYY-MM-DD">
    <version>2.0.0.0</version>
    <package>PUBLIC_IMMUTABLE_TAR_GZ_URL</package>
    <md5>FINAL_ARCHIVE_MD5</md5>
    <compatibility>
      <!-- exact tested OJS compatibility entries -->
    </compatibility>
  </release>
</plugin>
```

Do not commit invented maintainer email/institution. Populate from owner-provided/project-public details before submission.

## 9. CI release gates

A release pipeline must fail if:

- version is not four numeric components;
- `version.xml` and build version disagree;
- root/archive `LICENSE` missing;
- archive top-level directory is not exactly `chatwootIntegration`;
- multiple top-level archive entries exist;
- tests fail;
- supported-version compatibility job fails;
- package contains obvious secret files;
- generated public knowledge security test fails;
- high/critical dependency/security finding is unresolved without explicit documented decision;
- MD5 calculated before archive finalization changes;
- release URL is inaccessible publicly during final Gallery check.

## 10. Pre-release checklist

- [ ] `v2-dev` frozen for RC.
- [ ] docs/ADRs match implementation.
- [ ] all P0/P1 tasklist items closed or explicitly deferred.
- [ ] version `2.0.0.0` set.
- [ ] exact OJS compatibility matrix green.
- [ ] PHP/DB matrix green.
- [ ] upgrade from v1.0.0.2 green.
- [ ] install/enable/disable/uninstall smoke green.
- [ ] security/blind-review suite green.
- [ ] API/OpenAPI contracts green.
- [ ] Captain unavailable/disabled graceful-degradation green.
- [ ] MCP optional feature does not affect core install when disabled.
- [ ] public knowledge contains no private fixture data.
- [ ] archive content audited.
- [ ] root/archive licence present.
- [ ] public release URL prepared.

## 11. Final release procedure

1. merge reviewed RC changes according to project branching policy;
2. build final archive once;
3. run package smoke test against the final bytes;
4. calculate MD5;
5. create immutable public release/tag/asset;
6. verify public download and checksum;
7. prepare Gallery XML using exact package URL/checksum/compatibility;
8. open Plugin Gallery PR;
9. address automated checks;
10. address PKP code review;
11. do not replace the artifact after Gallery publication; publish a new version for fixes.

## 12. Current release readiness status

As of v2 inception: **NOT GALLERY READY**.

This is expected. The product-spec branch exists specifically to turn the current v1 import into a release-engineered v2. The immediate known hard gates are public-download availability, root licence, automated tests, exact compatibility evidence and a final immutable package.