<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootConversationClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ChatwootApiService implements ChatwootConversationClientInterface {
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

    public function createConversationNote($conversationId, $content) {
        $result = $this->requestJson('POST', "accounts/{$this->accountId}/conversations/{$conversationId}/notes", [
            'json' => ['content' => $content]
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
}
