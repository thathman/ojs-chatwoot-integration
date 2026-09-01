# REST / OpenAPI Guide (DOC-007)

How to call the Support Gateway REST API — the server-to-server surface
Chatwoot Captain (and any other HTTP-capable integration) uses. The full
machine-readable contract lives in [`openapi.json`](openapi.json)
(API-017); this guide is the human-readable walkthrough of the same real,
implemented surface.

## 1. Authentication

Every endpoint requires:

```
Authorization: Bearer <chatwootSupportApiToken>
```

Set `chatwootSupportApiToken` in the plugin's own settings form (the
same admin screen as `chatwootBaseUrl`/`chatwootApiAccessToken`). Rotate
tokens without downtime by configuring a comma-separated list —
`ServiceTokenAuthenticator::verify()` accepts any token in that list, so
you can add the new token, roll it out to callers, then remove the old
one on your own schedule.

This is a distinct credential from `chatwootApiAccessToken` (the token
this plugin uses to *call* Chatwoot) and from `mcpServiceToken` (the MCP
gateway's own credential — see `docs/v2/MCP_SETUP_GUIDE.md`). Never
reuse one for another.

## 2. Every request also carries a conversation tuple

Alongside whatever fields an endpoint needs, every endpoint reads these
three form fields to resolve a Support Session:

| Field | Meaning |
|---|---|
| `chatwootAccountId` | The Chatwoot account ID |
| `chatwootContactId` | The Chatwoot contact ID |
| `chatwootConversationId` | The Chatwoot conversation ID |

This tuple is how the gateway looks up (or fails to find) a bound
Support Session — never trust-on-first-use, never derived from anything
the client merely claims elsewhere.

## 3. Endpoint

```
POST /index.php/{journalPath}/ojsSupportGateway/{operation}
```

All 14 real operations are `application/x-www-form-urlencoded` POST
requests. See `openapi.json`'s `paths` for the complete, current list —
this guide covers the shape every operation shares, not a duplicate
listing that can drift out of sync with the schema.

## 4. Response envelope

Every response, success or error, is this same shape
(`SupportApiResponse`):

```json
{
  "ok": true,
  "data": { "...": "operation-specific" },
  "meta": { "apiVersion": "v1", "correlationId": "..." }
}
```

```json
{
  "ok": false,
  "error": { "code": "VALIDATION_ERROR", "message": "..." },
  "meta": { "apiVersion": "v1", "correlationId": "..." }
}
```

Always log `meta.correlationId` on your side — it is the value this
plugin's own audit log uses for the same request, and is the fastest way
to correlate a support ticket with server-side behavior.

## 5. The anti-enumeration rule

Every resource-scoped endpoint (`submissionVerify`, `submissionSupport`,
`requiredActions`, `publicationStatus`, `paymentStatus`) returns the
exact same generic shape (`resourceVerified: false`) whether the
conversation was never verified, the submission ID was guessed, the
caller has no real relationship to that submission, or the request just
came from the wrong journal. **Do not attempt to distinguish these
cases** — that indistinguishability is deliberate (see
`docs/v2/SECURITY_PRIVACY.md`), and a client that tries to infer which
reason applies is working against the design, not with it.

## 6. Using the OpenAPI schema

`openapi.json` is a real OpenAPI 3.0.3 document, contract-tested against
this plugin's own serializers (`tests/v2/openapi-contract.php` — a
schema/code drift fails CI). Feed it directly into:

- A code generator (`openapi-generator`, `swagger-codegen`) for a typed
  client in your own language.
- Any OpenAPI-aware API testing tool (Postman, Insomnia, Bruno) by
  importing the file directly.
- `swagger-ui`/`redoc` for a browsable reference, if you want one hosted
  alongside your own tooling.

The MCP (JSON-RPC) surface is intentionally **not** in this schema — it
isn't REST-shaped. See `docs/v2/MCP_SETUP_GUIDE.md` and
`docs/v2/API_MCP_SPEC.md` for that surface instead.

## 7. What's out of scope here

- Staff/editorial operations do not exist in this API at all yet (see
  POL-004 in `docs/v2/TASKLIST.md`) — every endpoint here is
  public-support-facing only.
- Verification issuance/confirmation (`verificationRequest`,
  `verificationConfirm`) establishes a Support Session for the *caller's
  own* claimed identity — it is not a way to look up another user.
