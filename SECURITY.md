# Security Policy

## Supported versions

Security support follows released plugin versions and the compatibility matrix documented for those releases. `v2-dev` is a development branch and must not be treated as a stable production security commitment until released.

## Reporting a vulnerability

Please report suspected security vulnerabilities **privately** to the repository maintainers. Prefer GitHub’s private security-advisory/reporting mechanism when it is enabled for this repository.

Do not disclose a vulnerability first in a public GitHub issue, pull request, discussion, support conversation or Chatwoot inbox when the report contains an exploitable security detail.

Useful information includes:

- affected plugin version/commit;
- OJS version;
- relevant Chatwoot deployment/feature information without exposing credentials;
- reproduction steps;
- impact;
- logs/screenshots with secrets, manuscript content and personal data redacted;
- suggested remediation if known.

Never include API tokens, HMAC secrets, verification codes, session tokens, private manuscript/review contents or other credentials in a report unless a maintainer provides a secure method for doing so.

## v2 security architecture

The governing v2 requirements are documented in `docs/v2/SECURITY_PRIVACY.md`.

Key rules include:

- OJS remains the authorization source for OJS resources;
- Chatwoot attributes are context, not authorization;
- verification creates short-lived server-side support sessions;
- resource relationship is checked separately from identity;
- public Captain never receives reviewer identities/confidential editorial fields;
- public and staff automation planes use separate capabilities/credentials;
- PIN plaintext and secrets are never logged;
- all protected operations are auditable;
- remote Chatwoot/Captain failure must not break OJS editorial workflows.

## Public disclosure

After a fix is available, maintainers may publish an advisory describing affected versions, impact and upgrade/remediation guidance. Coordinate disclosure timing for vulnerabilities that can expose unpublished manuscripts, peer-review identities or credentials.