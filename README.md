# OJS-Chatwoot Integration Plugin

This plugin integrates [Chatwoot](https://www.chatwoot.com/), an open-source customer engagement suite, with Open Journal Systems (OJS) 3.5+. It provides a seamless support experience for authors, reviewers, and readers directly within the journal interface.

## Features

### 1. Live Chat Widget
- **Universal Access**: Adds the Chatwoot widget to both the frontend (reader/author view) and backend (dashboard).
- **Localization**: Automatically syncs the widget language with the OJS user's current locale.

### 2. Deep Contextual Awareness
The plugin passes rich data to Chatwoot, allowing support agents to see exactly who they are talking to and what they are looking at.

- **User Identity**:
  - Name, Email, and User ID.
  - **HMAC Security**: Uses HMAC-SHA256 to verify user identity and prevent impersonation.
- **Rich Attributes**:
  - **Roles**: Displays the user's OJS roles (e.g., Manager, Author, Reviewer).
  - **ORCID iD**: Links to the user's ORCID profile if available.
  - **Affiliation**: Shows the user's institutional affiliation.
  - **Active Submissions**: Displays the count of active submissions for the user.
- **Page Context**:
  - **Article View**: When viewing an article, the plugin sends the `Article Title`, `DOI`, `Article ID`, and `Section Title` to Chatwoot.
  - **Workflow View**: When an author or editor is in the submission dashboard, the context is synced.

### 3. Workflow Integration
- **"Chat with Editor" Button**: In the submission workflow, a button is injected into the header. Clicking it opens the chat and automatically tags the conversation with the Submission ID and Title.
- **Editor Decision Notifications**: When an editor records a decision (e.g., Accept, Revision Required), the plugin automatically posts a **Private Note** to the author's conversation in Chatwoot. This gives agents immediate context if the author reaches out about the decision.

### 4. Privacy Mode (Blind Review Protection)
- **Reviewer Masking**: If **Privacy Mode** is enabled, any user with the **Reviewer** role will be automatically masked.
  - Name: "Reviewer (Masked)"
  - Email: Hashed/Anonymized
  - Attributes: ORCID and Affiliation are hidden.
- This ensures that double-blind peer review is preserved even if a reviewer contacts support.

### 5. Automation & Efficiency
- **Role-Based Visibility**: Configure which roles can see the chat widget (e.g., hide from Readers, show only to Authors and Managers).
- **Macros Sync**: A "Sync Templates" feature fetches all OJS Email Templates and pushes them to Chatwoot as **Canned Responses**. Agents can type `/` to access standard journal responses.
- **Smart Routing**: Use the `section_title` attribute in Chatwoot Automation Rules to route chats (e.g., "Cardiology" articles -> Medical Editors Team).

## Installation

1.  Clone or unzip this plugin into `plugins/generic/chatwootIntegration`.
2.  Run the OJS upgrade script or enable the plugin via **Website > Plugins > Generic Plugins**.

## Configuration

Go to **Website > Plugins > Chatwoot Integration > Settings**.

### Required Settings
- **Chatwoot Base URL**: The URL of your Chatwoot installation (e.g., `https://chat.myjournal.com`).
- **Website Token**: The unique token for your Inbox (found in Chatwoot: Settings > Inboxes > [Your Inbox] > Settings > Configuration).

### Optional / Advanced Settings
- **Identity Validation Secret**: The HMAC secret key (found in Chatwoot: Settings > Inboxes > [Your Inbox] > Settings > Configuration). **Highly Recommended** for security.
- **API Access Token**: An Agent/Admin API Token (found in Chatwoot: Profile Settings). Required for **Sync Templates** and **Editor Decision Notes**.
- **Enable Privacy Mode**: Check this to mask Reviewer identities.
- **Widget Visibility**: Toggle visibility for Guests and specific Roles.
- **Enable Debug Mode**: Logs payload details to the browser console for troubleshooting.

## Usage

### Syncing Email Templates
1.  Ensure **API Access Token** is configured.
2.  Go to the Plugins list.
3.  Click the arrow next to "Chatwoot Integration".
4.  Click **Sync Templates**.
5.  Wait for the success message indicating how many templates were synced.

### Technical Notes
- **Hooks Used**:
  - `TemplateManager::display`: For widget injection.
  - `EditorAction::recordDecision`: For posting decision notes.
- **Dependencies**: Requires Guzzle (included in OJS core).
