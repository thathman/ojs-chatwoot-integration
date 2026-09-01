# OJS-Chatwoot Integration Plugin

This plugin integrates [Chatwoot](https://www.chatwoot.com/), an open-source customer engagement suite, with Open Journal Systems (OJS) 3.5. It adds a live chat widget with rich journal context (v1), and a full public Support Gateway — verified identity, a REST API, an MCP adapter for AI agents, generated public knowledge pages, and an admin console — for building AI/agent-assisted support on top of Chatwoot (v2).

Full documentation lives in [`docs/v2/`](docs/v2/); this README is a short orientation, not a substitute for it.

## Features

### 1. Live Chat Widget (v1)
- **Universal Access**: Adds the Chatwoot widget to both the frontend (reader/author view) and backend (dashboard).
- **Localization**: Automatically syncs the widget language with the OJS user's current locale.

### 2. Deep Contextual Awareness (v1)
The plugin passes rich data to Chatwoot, allowing support agents to see exactly who they are talking to and what they are looking at — presentation context only, never an authorization source (see [`docs/v2/CORE_BRIDGE_GUIDE.md`](docs/v2/CORE_BRIDGE_GUIDE.md)).

- **User Identity**: Name, Email, User ID, and an HMAC-SHA256 hash to let Chatwoot verify identity.
- **Rich Attributes**: OJS roles, ORCID iD, institutional affiliation, active-submission count.
- **Page Context**: Article Title/DOI/Article ID/Section Title on an article page; journal/page context everywhere else.

### 3. Privacy Mode (Blind Review Protection) (v1)
If **Privacy Mode** is enabled, any user with the **Reviewer** role is automatically masked (masked name, hashed email, hidden ORCID/affiliation) — preserving double-blind peer review even if a reviewer contacts support. See [`docs/v2/CORE_BRIDGE_GUIDE.md`](docs/v2/CORE_BRIDGE_GUIDE.md) §3.

### 4. Automation & Efficiency (v1)
- **Role-Based Visibility**: Configure which roles can see the chat widget.
- **Macros Sync**: "Sync Templates" pushes OJS Email Templates to Chatwoot as Canned Responses.
- **Editor Decision Notifications**: When an editor records a decision, a private note is posted to the author's conversation.

### 5. Support Gateway (v2)

A public REST API, verified-identity system, and generated public knowledge pages, so Chatwoot Captain (or any MCP-capable client) can answer real journal questions without ever seeing private submission/review data:

- **Verified identity**: a short-lived, server-side session binds an OJS user (or a PIN/link-verified external identity) to a Chatwoot conversation — never a client-claimed identifier. See [`docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md`](docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md).
- **REST Support API**: 14 endpoints (submission status, payment status, required actions, diagnostics, escalation, and more) — see [`docs/v2/REST_API_GUIDE.md`](docs/v2/REST_API_GUIDE.md) and [`docs/v2/openapi.json`](docs/v2/openapi.json).
- **MCP adapter**: the same Support API exposed as Model Context Protocol tools/resources for MCP-capable agent clients — see [`docs/v2/MCP_SETUP_GUIDE.md`](docs/v2/MCP_SETUP_GUIDE.md).
- **Public knowledge pages**: generated, always-public pages (fees, submissions, review, publication, policies, and more) at `/<journal>/support-knowledge/<category>`, safe to feed to Chatwoot Captain as a Knowledge Document — see [`docs/v2/KNOWLEDGE_PROVIDER_GUIDE.md`](docs/v2/KNOWLEDGE_PROVIDER_GUIDE.md).
- **Captain provisioning**: one-click provisioning of a Captain Knowledge Document, Custom Tools, and Scenarios — see [`docs/v2/CAPTAIN_PREREQUISITES_GUIDE.md`](docs/v2/CAPTAIN_PREREQUISITES_GUIDE.md).
- **Admin console**: a consolidated Support Gateway Health section, manual Captain sync/repair, dead-letter retry, a mail-transport test, and Event Bridge delivery-mode policy — see [`docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md`](docs/v2/TROUBLESHOOTING_HEALTH_GUIDE.md).

## Installation

See [`docs/v2/INSTALL_CONFIG_GUIDE.md`](docs/v2/INSTALL_CONFIG_GUIDE.md) for the full guide. In short: place the plugin at `plugins/generic/chatwootIntegration/` (the directory name matters — see that guide §2) and enable it from **Website > Plugins > Generic Plugins**.

Upgrading an existing v1 install requires one additional real step beyond replacing files — see [`docs/v2/UPGRADE_FROM_V1.md`](docs/v2/UPGRADE_FROM_V1.md).

## Configuration

Go to **Website > Plugins > Chatwoot Integration > Settings**. See [`docs/v2/INSTALL_CONFIG_GUIDE.md`](docs/v2/INSTALL_CONFIG_GUIDE.md) for a field-by-field walkthrough of the Connection/Support API/MCP sections, and [`docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md`](docs/v2/VERIFICATION_SECURITY_ADMIN_GUIDE.md) for the Event Bridge policy section.

## Requirements

OJS 3.5.x, PHP 8.2 or 8.3 (matching OJS 3.5's own real requirement — see [`docs/v2/VERIFICATION_MATRIX.md`](docs/v2/VERIFICATION_MATRIX.md)). No plugin-specific dependencies to install: Guzzle (used by the Chatwoot API client) is already bundled by OJS core.

## Technical notes

- **Real v1 hooks used** (each individually verified against OJS 3.5's own source — see [`docs/v2/V1_INVENTORY.md`](docs/v2/V1_INVENTORY.md)): `TemplateManager::display`/`TemplateManager::fetch`/`Templates::Common::Footer::PageFooter` for widget injection; `Decision::add` for editor decision notes; `Submission::add`, `Submission::updateStatus`, `Publication::publish` for other event notifications.
- Security model, threat model, and data retention: [`docs/v2/SECURITY_PRIVACY.md`](docs/v2/SECURITY_PRIVACY.md) and [`docs/v2/PRIVACY_DATA_RETENTION_GUIDE.md`](docs/v2/PRIVACY_DATA_RETENTION_GUIDE.md).
