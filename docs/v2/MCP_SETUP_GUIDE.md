# MCP Setup Guide (DOC-006)

How to point an MCP-capable client (an agent framework, an MCP Inspector session,
or any tool speaking the Model Context Protocol) at this plugin's MCP gateway.
Covers what is actually built and tested (MCP-001 through MCP-009,
`docs/v2/ADRS.md` ADR-023) — nothing here describes a future/planned surface.

## 1. What this is (and isn't)

The MCP gateway is a **separate, read-only adapter** onto the same Support
Core REST already uses — it is not a second, divergent API. Every tool
response is produced by the exact same allowlist serializer the REST
endpoint for that concept uses (proven in `tests/v2/mcp-security.php`).

- Protocol revision: `2026-07-28`, stateless Streamable HTTP. There is no
  `initialize`/`initialized` handshake and no `Mcp-Session-Id` — every
  request is self-describing.
- Only `tools/list`, `tools/call`, `resources/list`, and `resources/read`
  are currently registered. `server/discover` is a supported method name
  (it will never 404) but has no handler yet, so calling it returns
  `METHOD_NOT_FOUND` — safe, never a fatal.
- This is the **public** MCP plane only. A separate staff plane
  (`CapabilityRequest::CONSUMER_MCP_STAFF`) is reserved in code but has no
  capabilities or tools behind it at all — see MCP-005 in
  `docs/v2/TASKLIST.md`.

## 2. Endpoint

```
POST /index.php/{journalPath}/ojsMcpGateway
```

One endpoint for every MCP method — the JSON-RPC `method` field in the
request body selects the operation, not the URL path.

## 3. Authentication

Every request must carry:

```
Authorization: Bearer <mcpServiceToken>
```

`mcpServiceToken` is a **distinct credential** from the Chatwoot API
token (`chatwootApiAccessToken`) or the REST Support API's own service
token — never reuse one for the other. Authentication happens before the
request body is ever parsed, so a malformed body from an unauthenticated
caller can never be used as a probing oracle.

Set it from the journal's own **Settings → Website → Plugins →
Chatwoot Integration** settings form, under the "MCP" section
(ADM-001) — the field is masked after saving, same as the other
credential fields, and the form also shows this journal's real MCP
endpoint URL and protocol revision. If you generate the token
yourself rather than typing one in, treat the value exactly like an API key: generate it with a
cryptographically random source, store it only in your MCP client's own
secret store, and rotate it the same way you would `chatwootApiAccessToken`.

A valid `mcpServiceToken` proves only "this application may talk to the
MCP server" — it never grants access to a specific user's data by itself.
Every tool that touches a specific submission or identity still requires
the real Chatwoot conversation tuple (see §5) and independently
re-verifies the caller's relationship to that resource.

## 4. Discovering tools and resources

```json
{"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}
```

Response `result.tools` is an array of `{name, description, inputSchema}`
— exactly what MCP-011 requires: never a tool's internal handler, only
what a client needs to decide whether/how to call it.

The same shape applies to resources:

```json
{"jsonrpc": "2.0", "id": 2, "method": "resources/list", "params": {}}
```

## 5. Calling a tool

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "identity.get_support_identity",
    "arguments": {
      "chatwootAccountId": "1",
      "chatwootContactId": "42",
      "chatwootConversationId": "1001"
    }
  }
}
```

Most tools require this same three-field Chatwoot conversation tuple —
it is how the gateway resolves a Support Session, exactly like REST reads
the same three fields as POST form fields. This is data the gateway
already needs from your MCP client's own integration with Chatwoot (the
account/contact/conversation IDs of the conversation you're assisting),
never derived from MCP transport state.

Submission-scoped tools (`submission.get_support_status`,
`submission.get_required_actions`, `publication.get_status`,
`payment.get_submission_status`, `diagnostics.submission`) additionally
require a `submissionId` argument.

### The full built tool list

| Tool | Equivalent REST concept |
|---|---|
| `journal.get_profile` | Public journal facts |
| `journal.get_submission_policy` | Public submission-guideline facts |
| `journal.get_fee_policy` | Public fee-policy facts |
| `identity.get_support_identity` | `ojs_get_support_identity` |
| `submission.list_mine` | Submissions list |
| `submission.get_support_status` | `ojs_get_submission_support` |
| `submission.get_required_actions` | `ojs_get_required_actions` |
| `publication.get_status` | `ojs_get_publication_status` |
| `payment.get_submission_status` | `ojs_get_payment_status` |
| `diagnostics.account` | `ojs_diagnose_account` |
| `diagnostics.submission` | `ojs_diagnose_submission` |
| `capabilities.list_available` | Actions/capability discovery |
| `support.escalate` | `ojs_escalate_support` |

`identity.request_verification`/`identity.confirm_verification` are not
built yet for MCP (the REST equivalents exist; this is a real, scoped-out
next slice — see `docs/v2/API_MCP_SPEC.md` §10).

## 6. Reading a resource

```json
{
  "jsonrpc": "2.0",
  "id": 4,
  "method": "resources/read",
  "params": {"uri": "ojs://journal/7/support-profile"}
}
```

Resources are Knowledge-Compiler-only, journal-scoped public facts. They
never expose submission- or user-specific live state — that always goes
through a tool call, so authorization is evaluated at read time, not
baked into a cacheable static resource.

| Resource URI | Content |
|---|---|
| `ojs://journal/{contextId}/support-profile` | Public `journal.*` facts |
| `ojs://journal/{contextId}/submission-guidelines` | Public `submission.*` facts |
| `ojs://journal/{contextId}/fee-policy` | Public `fee.*` facts |

## 7. Error handling

Every response is JSON-RPC 2.0 shaped. On error:

```json
{"jsonrpc": "2.0", "id": 3, "error": {"code": -32003, "message": "..."}}
```

| Code | Meaning |
|---|---|
| `-32700` | Malformed JSON |
| `-32600` | Invalid request shape |
| `-32601` | Unknown/unregistered method |
| `-32602` | Invalid params |
| `-32603` | Internal error (never leaks the real exception) |
| `-32001` | Unsupported protocol revision |
| `-32002` | Unknown tool or resource |
| `-32003` | Unauthorized |
| `-32004` | Rate limited |

## 8. Testing against this gateway without a real client

`tests/v2/mcp-openclaw-integration.php` is a self-contained example of a
generic MCP client driving the real dispatcher pipeline over raw JSON-RPC
bytes — useful as a reference for building your own client's request
shapes, independent of any specific MCP SDK.
