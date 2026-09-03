<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootConversationClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ChatwootApiService implements ChatwootConversationClientInterface, ChatwootCaptainClientInterface {
    private Client $client;
    private string $baseUrl;
    private string $apiAccessToken;
    private int $accountId;

    public function __construct($baseUrl, $apiAccessToken) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiAccessToken = $apiAccessToken;
        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/api/v1/',
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => [
                'api_access_token' => $this->apiAccessToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
        $this->accountId = 1;
        $this->resolveAccountId();
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function setAccountId($id): void { $this->accountId = (int) $id; }
    public function getAccountId(): int { return $this->accountId; }

    public function checkSdkReachable(): bool {
        $sdkClient = new Client(['timeout' => 8, 'connect_timeout' => 4]);
        try {
            $response = $sdkClient->request('GET', $this->baseUrl . '/packs/js/sdk.js');
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 400;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function validateApiToken(): bool { return !empty($this->getProfile()); }

    public function getProfile(): ?array {
        $result = $this->requestJson('GET', 'profile');
        return $result['ok'] ? ($result['data'] ?? []) : null;
    }

    /**
     * Fetch one conversation by the account-facing display ID.
     * The response includes `meta.hmac_verified` and sender identity evidence.
     */
    public function getConversation(int $conversationDisplayId): ?array {
        if ($conversationDisplayId <= 0) return null;
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/conversations/{$conversationDisplayId}");
        if (!$result['ok']) return null;
        $data = $result['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    public function getLastErrorMessage(\Throwable $e): string {
        if ($e instanceof \RuntimeException) return $e->getMessage();
        return $e->getMessage() ?: 'Unknown Chatwoot API error';
    }

    private function resolveAccountId(): void {
        try {
            $profile = $this->getProfile();
            if (!empty($profile['account_id'])) $this->accountId = (int) $profile['account_id'];
        } catch (\Throwable $e) {
            // Keep default account ID fallback.
        }
    }

    private function requestJson(string $method, string $uri, array $options = []): array {
        try {
            $response = $this->client->request($method, $uri, $options);
            $body = (string) $response->getBody();
            $decoded = strlen($body) ? json_decode($body, true) : [];
            return ['ok' => true, 'data' => is_array($decoded) ? $decoded : []];
        } catch (GuzzleException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function getCannedResponses() {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/canned_responses");
        return $result['ok'] ? ($result['data'] ?? []) : [];
    }

    public function createCannedResponse($shortCode, $content) {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/canned_responses", [
            'json' => ['short_code' => $shortCode, 'content' => $content]
        ]);
        if (!$result['ok']) return ['success' => false, 'error' => $result['error'] ?? 'Unknown API error'];
        return ['success' => true];
    }

    public function findContactByEmail($email) {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/contacts/search", ['query' => ['q' => $email]]);
        if (!$result['ok']) return null;
        $data = $result['data'] ?? [];
        $payload = $data['payload'] ?? [];
        if (!is_array($payload) || empty($payload)) return null;
        $target = strtolower(trim((string) $email));
        foreach ($payload as $contact) {
            $contactEmail = strtolower(trim((string) ($contact['email'] ?? '')));
            if ($target !== '' && $contactEmail === $target) return $contact;
        }
        return null;
    }

    public function createContact(string $email, string $name = '', string $identifier = ''): ?array {
        $payload = ['email' => $email];
        if ($name !== '') $payload['name'] = $name;
        if ($identifier !== '') $payload['identifier'] = $identifier;
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/contacts", ['json' => $payload]);
        if (!$result['ok']) return null;
        return $result['data']['payload']['contact'] ?? ($result['data']['payload'] ?? null);
    }

    /**
     * A "private note" is not a distinct Chatwoot resource — there is no
     * real `/conversations/{id}/notes` endpoint. Confirmed live against
     * https://support.airixmedia.com: POSTing there returns a real HTTP
     * 404, meaning this method silently returned false on every call
     * since it was written, and every 'private_note'-mode delivery (the
     * real production default `eventSyncMode`) has been failing in
     * production without ever throwing. The real API creates a private
     * note the same way as any message, via `/messages` with
     * `private: true` — verified live to return HTTP 200.
     */
    public function createConversationNote($conversationId, $content) {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/conversations/{$conversationId}/messages", [
            'json' => ['content' => $content, 'private' => true]
        ]);
        return (bool) $result['ok'];
    }

    /**
     * EVT-013: the real Chatwoot conversation messages endpoint — verified
     * against a real chatwoot/chatwoot `develop` checkout
     * (`app/controllers/api/v1/accounts/conversations/messages_controller.rb`
     * + `app/builders/messages/message_builder.rb`, which reads
     * `params[:private]` (default `false`) and `params[:message_type]`
     * (default `'outgoing'`)). `createConversationNote()` posts to this
     * exact same endpoint with `private` hardcoded `true`; `private: false`
     * here is what actually makes a message visible to the contact.
     */
    public function createConversationMessage($conversationId, $content, bool $private = true) {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/conversations/{$conversationId}/messages", [
            'json' => ['content' => $content, 'private' => $private]
        ]);
        return (bool) $result['ok'];
    }

    public function createConversation(int $contactId, int $inboxId, string $message = ''): ?array {
        $payload = [
            'source_id' => 'ojs-' . $contactId . '-' . time(),
            'contact_id' => $contactId,
            'inbox_id' => $inboxId,
            'status' => 'open',
        ];
        if ($message !== '') $payload['message'] = ['content' => $message];
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/conversations", ['json' => $payload]);
        if (!$result['ok']) return null;
        return $result['data'] ?? null;
    }

    public function getContactConversations($contactId) {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/contacts/{$contactId}/conversations");
        if (!$result['ok']) return [];
        $data = $result['data'] ?? [];
        return $data['payload'] ?? [];
    }

    /**
     * Captain Documents index supports `search_key` (name/external_link)
     * filtering server-side, but this still confirms an exact
     * `external_link` match client-side rather than trusting a fuzzy
     * server-side match — a near-miss must never be treated as "found."
     */
    public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/documents", [
            'query' => ['assistant_id' => $assistantId, 'search_key' => $externalLink],
        ]);
        if (!$result['ok']) return null;
        $data = $result['data'] ?? [];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : []);
        foreach ($payload as $document) {
            if (is_array($document) && (string) ($document['external_link'] ?? '') === $externalLink) {
                return $document;
            }
        }
        return null;
    }

    public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/captain/documents", [
            'json' => ['assistant_id' => $assistantId, 'name' => $name, 'external_link' => $externalLink],
        ]);
        if (!$result['ok']) return null;
        $data = $result['data'] ?? [];
        return is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : null);
    }

    public function syncCaptainDocument(string $documentId): bool {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/captain/documents/{$documentId}/sync");
        return (bool) $result['ok'];
    }

    public function listCaptainCustomTools(): array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/custom_tools");
        if (!$result['ok']) return [];
        $data = $result['data'] ?? [];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : []);
        return array_values(array_filter($payload, 'is_array'));
    }

    public function createCaptainCustomTool(array $definition): ?array {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/captain/custom_tools", [
            'json' => ['custom_tool' => $definition],
        ]);
        if (!$result['ok']) return null;
        $data = $result['data'] ?? [];
        return is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : null);
    }

    public function updateCaptainCustomTool(string $toolId, array $definition): bool {
        $result = $this->requestJson('PATCH', "accounts/{$this->accountId}/captain/custom_tools/{$toolId}", [
            'json' => ['custom_tool' => $definition],
        ]);
        return (bool) $result['ok'];
    }

    public function listCaptainScenarios(int $assistantId): array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/assistants/{$assistantId}/scenarios");
        if (!$result['ok']) return [];
        $data = $result['data'] ?? [];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : []);
        return array_values(array_filter($payload, 'is_array'));
    }

    public function createCaptainScenario(int $assistantId, array $definition): ?array {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/captain/assistants/{$assistantId}/scenarios", [
            'json' => ['scenario' => $definition],
        ]);
        if (!$result['ok']) return null;
        $data = $result['data'] ?? [];
        return is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : null);
    }

    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool {
        $result = $this->requestJson('PATCH', "accounts/{$this->accountId}/captain/assistants/{$assistantId}/scenarios/{$scenarioId}", [
            'json' => ['scenario' => $definition],
        ]);
        return (bool) $result['ok'];
    }

    /**
     * KNO-011 hardening: a real API/HTTP failure must throw, never
     * degrade to an empty array — the caller (FaqCacheSyncService)
     * distinguishes "the account genuinely has zero approved FAQs
     * right now" from "the request failed" specifically so an outage
     * never clears an already-synced local cache. Every other
     * list-/get-style method on this class collapses failure to []/null
     * because those callers only ever add/update, never destructively
     * replace a whole local dataset — this one is different.
     */
    public function listCaptainAssistantResponses(int $assistantId): array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/assistant_responses", [
            'query' => ['assistant_id' => $assistantId],
        ]);
        if (!$result['ok']) {
            throw new \RuntimeException('Chatwoot Captain assistant_responses request failed: ' . ($result['error'] ?? 'unknown error'));
        }
        $data = $result['data'] ?? [];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : []);

        // Completeness guard: a paginated response with more pages than
        // were fetched here must never be treated as the whole
        // authoritative set — that would let FaqCacheSyncService's
        // replaceAll() delete real, still-approved FAQs simply because
        // they weren't on this page. Verified against `chatwoot/chatwoot`
        // `develop`'s `Api::V1::Accounts::Captain::AssistantResponsesController#index`,
        // which paginates via Kaminari and reports `meta.total_count`.
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        if (isset($meta['total_count']) && is_numeric($meta['total_count']) && (int) $meta['total_count'] > count($payload)) {
            throw new \RuntimeException('Chatwoot Captain assistant_responses returned a partial page (' . count($payload) . ' of ' . (int) $meta['total_count'] . ' total) — refusing to treat it as the complete set');
        }

        return array_values(array_filter($payload, 'is_array'));
    }
}
