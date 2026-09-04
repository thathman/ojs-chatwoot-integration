<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootConversationClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SafeExceptionMessage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ChatwootApiService implements ChatwootConversationClientInterface, ChatwootCaptainClientInterface {
    private Client $client;
    private string $baseUrl;
    private string $apiAccessToken;
    private int $accountId;
    /**
     * HAR-001: whether $accountId reflects a real, confirmed account
     * (resolved from a real /profile response, or explicitly set by a
     * caller) rather than the unconfirmed default. Every account-scoped
     * request (accounts/{id}/...) refuses to run while this is false —
     * see requestJson()'s own guard — rather than silently guessing.
     */
    private bool $accountResolved = false;

    /**
     * HAR-001/HAR-021: every construction used to perform its own
     * hidden `/profile` network call — a real request that instantiates
     * this service more than once for the same Chatwoot credentials
     * (e.g. a nested call, or a future call site) paid for that
     * resolution again each time, indistinguishable in a request trace
     * from genuinely new work. Cached per (baseUrl, token) for the
     * lifetime of the PHP process/request; a resolution failure is
     * deliberately never cached, so a transient outage does not lock
     * every later construction in the same request into the fail-closed
     * state once Chatwoot recovers.
     *
     * @var array<string,int>
     */
    private static array $resolvedAccountCache = [];

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
    public function setAccountId($id): void { $this->accountId = (int) $id; $this->accountResolved = true; }
    public function getAccountId(): int { return $this->accountId; }
    /** HAR-001: true only once $accountId reflects a real, confirmed account. */
    public function isAccountResolved(): bool { return $this->accountResolved; }

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

    /** HAR-011: never the raw exception message — see SafeExceptionMessage. */
    public function getLastErrorMessage(\Throwable $e): string {
        return SafeExceptionMessage::describe($e);
    }

    private function resolveAccountId(): void {
        $cacheKey = md5($this->baseUrl . '|' . $this->apiAccessToken);
        if (isset(self::$resolvedAccountCache[$cacheKey])) {
            $this->accountId = self::$resolvedAccountCache[$cacheKey];
            $this->accountResolved = true;
            return;
        }

        try {
            $profile = $this->getProfile();
            if (!empty($profile['account_id'])) {
                $this->accountId = (int) $profile['account_id'];
                $this->accountResolved = true;
                self::$resolvedAccountCache[$cacheKey] = $this->accountId;
            }
        } catch (\Throwable $e) {
            // HAR-001: account resolution failed — $accountResolved stays
            // false. Do not fall through and silently operate against the
            // unconfirmed default account ID; requestJson() below refuses
            // every accounts/{id}/... call until an account is confirmed.
            // Deliberately not cached, so a transient failure does not
            // poison later constructions in the same request/process.
        }
    }

    private function requestJson(string $method, string $uri, array $options = []): array {
        if (!$this->accountResolved && str_starts_with($uri, 'accounts/')) {
            return ['ok' => false, 'error' => 'Chatwoot account could not be confirmed; refusing to operate against an unresolved account ID (HAR-001).'];
        }
        try {
            $response = $this->client->request($method, $uri, $options);
            $body = (string) $response->getBody();
            $decoded = strlen($body) ? json_decode($body, true) : [];
            return ['ok' => true, 'data' => is_array($decoded) ? $decoded : []];
        } catch (GuzzleException $e) {
            // HAR-011: never surface a raw Guzzle exception message —
            // it embeds the full request URI (query-string tokens
            // included) and any response body. This is the single
            // funnel every Chatwoot HTTP call in this class goes
            // through, so sanitizing here protects every downstream
            // consumer (admin-visible error messages, exceptions built
            // from this text, scheduled-task logs) in one place.
            return ['ok' => false, 'error' => SafeExceptionMessage::describe($e)];
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

    /**
     * HAR-002: must throw on a real request failure, never collapse to
     * []. Both provisioner call sites use this as a dedup check before
     * creating a new remote resource — a failed request silently
     * returning [] would look identical to "genuinely zero custom
     * tools exist," causing a real duplicate to be created on retry
     * after a transient outage instead of correctly refusing to act.
     */
    public function listCaptainCustomTools(): array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/custom_tools");
        if (!$result['ok']) {
            throw new \RuntimeException('Chatwoot Captain custom_tools request failed: ' . ($result['error'] ?? 'unknown error'));
        }
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

    /** HAR-002: must throw on a real request failure — same reasoning as listCaptainCustomTools(), a real dedup-before-create check. */
    public function listCaptainScenarios(int $assistantId): array {
        $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/assistants/{$assistantId}/scenarios");
        if (!$result['ok']) {
            throw new \RuntimeException('Chatwoot Captain scenarios request failed: ' . ($result['error'] ?? 'unknown error'));
        }
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
     *
     * Real pagination, confirmed live against `support.airixmedia.com`:
     * the endpoint is Kaminari-paginated (`meta.total_count`/`meta.page`),
     * 25 rows per page in production, and a real assistant returned 209
     * rows (9 pages) — a single unpaginated fetch silently returning 25
     * of 209 rows would have made `FaqCacheSyncService::sync()`'s
     * `replaceAll()` delete 184 real, still-approved FAQs. This walks
     * every page until the accumulated count reaches `total_count`,
     * capped at 200 pages as a hard backstop against a malformed/looping
     * response, and still throws — never silently truncates — if the
     * cap is hit before completion.
     */
    public function listCaptainAssistantResponses(int $assistantId): array {
        $all = [];
        $page = 1;
        $totalCount = null;
        $maxPages = 200;

        do {
            $result = $this->requestJson('GET', "accounts/{$this->accountId}/captain/assistant_responses", [
                'query' => ['assistant_id' => $assistantId, 'page' => $page],
            ]);
            if (!$result['ok']) {
                throw new \RuntimeException('Chatwoot Captain assistant_responses request failed: ' . ($result['error'] ?? 'unknown error'));
            }
            $data = $result['data'] ?? [];
            $payload = is_array($data['payload'] ?? null) ? $data['payload'] : (is_array($data) ? $data : []);
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

            if (isset($meta['total_count']) && is_numeric($meta['total_count'])) {
                $totalCount = (int) $meta['total_count'];
            }

            if ($payload === []) {
                break;
            }

            $all = array_merge($all, $payload);
            $page++;
        } while ($totalCount !== null && count($all) < $totalCount && $page <= $maxPages);

        if ($totalCount !== null && count($all) < $totalCount) {
            throw new \RuntimeException('Chatwoot Captain assistant_responses pagination did not complete (' . count($all) . ' of ' . $totalCount . ' total after ' . $maxPages . ' pages) — refusing to treat it as the complete set');
        }

        return array_values(array_filter($all, 'is_array'));
    }
}
