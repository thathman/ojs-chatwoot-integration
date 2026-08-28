# Contributing

## Branch model

- `main` — stable/v1 line until a reviewed v2 release transition.
- `v2-dev` — v2 integration/development branch.
- feature/fix branches — branch from `v2-dev` for v2 work and merge back only after review/tests.

Do not implement v2 directly on `main`.

## Before starting work

1. Read `docs/v2/README.md` and the relevant v2 spec.
2. Select/create task IDs from `docs/v2/TASKLIST.md`.
3. Check `docs/v2/VERIFICATION_MATRIX.md` for upstream assumptions.
4. If the change alters a governing architectural decision, add/supersede an ADR before implementation.

## Pull request expectations

A v2 PR should state:

- task IDs addressed;
- user/product behavior changed;
- security/privacy impact;
- OJS compatibility impact;
- Chatwoot/Captain feature or edition dependency;
- migrations/settings changes;
- tests added/run;
- documentation/ADR changes;
- known limitations/deferred work.

## Security

Never commit:

- Chatwoot API tokens;
- widget HMAC secrets;
- Support Gateway/MCP credentials;
- journal SMTP/payment/provider secrets;
- real verification codes/session tokens;
- private manuscript/review fixtures containing identifiable production data.

Use synthetic fixtures.

Security vulnerabilities should follow `SECURITY.md`, not a public issue first.

## OJS compatibility

Use supported PKP/OJS plugin APIs, handlers, hooks, repositories/services and compatibility adapters. Do not patch OJS core files.

A new OJS compatibility claim requires green tests against the exact target release(s). Do not replace explicit compatibility with “3.5+”.

## Chatwoot boundary

Do not vendor or copy Chatwoot enterprise implementation code into this GPL plugin. Integrate over supported network/widget APIs.

Captain-specific code must degrade gracefully when Captain/custom tools/documents/scenarios are unavailable in the target Chatwoot edition/deployment.

## Privacy rules for code review

Reviewers must specifically check that:

- LLM parameters are not treated as identity/authorization;
- relationship is checked for protected resources;
- public serializers use allowlists;
- reviewer/editorial confidential data cannot reach public consumers;
- cross-journal IDs are rejected;
- secrets/PII are absent from logs/errors;
- public knowledge providers emit only `public` classification.

## Testing

Follow `docs/v2/TEST_PLAN.md`. New behavior requires tests at the appropriate layer. Security-sensitive changes require negative/abuse tests, not only success-path tests.

## Release packaging

Do not manually publish a release archive ad hoc. Stable releases follow `docs/v2/RELEASE_GALLERY.md` and must be immutable after publication.

The Gallery package must extract to one top-level directory named `chatwootIntegration` and use a four-part version.