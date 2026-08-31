<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

/**
 * Idempotent provisioning of the fixed CanonicalToolCatalog against
 * Chatwoot's real Custom Tools API (verified against `chatwoot/chatwoot`
 * `develop` `config/routes.rb`'s `resources :custom_tools do post :test,
 * on: :collection end` and the enterprise controller's actual permitted
 * params: `title`, `description`, `endpoint_url`, `http_method`,
 * `request_template`, `response_template`, `auth_type`, `enabled`,
 * `auth_config`, `param_schema`).
 *
 * Unlike Documents, Custom Tools DO have a real update endpoint — so
 * "idempotent create-or-sync" here genuinely means create-or-update,
 * keyed on a fingerprint over the whole remote-facing definition
 * (title/description/endpoint/schema/templates/auth — a service-token
 * rotation is a real definition change and must push an update, not be
 * silently ignored).
 *
 * Ownership is proven only by a local CaptainSyncState record
 * (resourceType=RESOURCE_CUSTOM_TOOL, resourceKey=the canonical tool
 * key) — an existing remote tool with the same title but no local record
 * is a conflict, never adopted, never duplicated, exactly like Documents.
 */
final class CaptainCustomToolProvisioner
{
    public function __construct(
        private ChatwootCaptainClientInterface $client,
        private SupportKnowledgeSyncRepositoryInterface $syncRepository
    ) {
    }

    /**
     * @param array<string,string> $operationUrls operation => absolute Support API endpoint URL
     *
     * @return array<string,CaptainSyncResult> keyed by canonical tool key
     */
    public function provisionAll(int $contextId, string $locale, array $operationUrls, string $serviceToken, int $now): array
    {
        $existingTitles = null; // fetched at most once per call, only if actually needed
        $results = [];

        foreach (CanonicalToolCatalog::all() as $tool) {
            $endpointUrl = $operationUrls[$tool->operation()] ?? '';
            if ($endpointUrl === '') {
                $results[$tool->key()] = CaptainSyncResult::failed('endpoint_url_unavailable');
                continue;
            }

            $results[$tool->key()] = $this->provisionOne($contextId, $locale, $tool, $endpointUrl, $serviceToken, $now, $existingTitles);
        }

        return $results;
    }

    /** @param array<int,string>|null $existingTitles passed by reference so it is fetched at most once across the whole provisionAll() call */
    private function provisionOne(
        int $contextId,
        string $locale,
        CanonicalToolDefinition $tool,
        string $endpointUrl,
        string $serviceToken,
        int $now,
        ?array &$existingTitles
    ): CaptainSyncResult {
        $definition = [
            'title' => $tool->title(),
            'description' => $tool->description(),
            'endpoint_url' => $endpointUrl,
            'http_method' => 'POST',
            'auth_type' => 'bearer',
            'auth_config' => ['token' => $serviceToken],
            'param_schema' => $tool->paramSchema(),
            'request_template' => $tool->requestTemplate(),
            'response_template' => null,
            'enabled' => true,
        ];
        $fingerprint = hash('sha256', (string) json_encode($definition));

        $state = $this->syncRepository->find($contextId, $locale, CaptainSyncState::RESOURCE_CUSTOM_TOOL, $tool->key());

        if ($state !== null && $state->isOwned()) {
            if ($state->lastSuccessfulFingerprint() === $fingerprint) {
                return CaptainSyncResult::noop($fingerprint);
            }

            $updated = $this->client->updateCaptainCustomTool((string) $state->remoteResourceId(), $definition);
            if (!$updated) {
                $this->syncRepository->save($state->withError('update_failed', $now));
                return CaptainSyncResult::failed('update_failed');
            }

            $this->syncRepository->save($state->withSuccess($fingerprint, $now));
            return CaptainSyncResult::synced($fingerprint);
        }

        if ($existingTitles === null) {
            $existingTitles = array_map(
                static fn (array $t): string => (string) ($t['title'] ?? ''),
                $this->client->listCaptainCustomTools()
            );
        }

        if (in_array($tool->title(), $existingTitles, true)) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $contextId,
                $locale,
                CaptainSyncState::RESOURCE_CUSTOM_TOOL,
                $tool->key(),
                'unmanaged_tool_exists',
                $now
            ));
            return CaptainSyncResult::conflict('unmanaged_tool_exists');
        }

        $created = $this->client->createCaptainCustomTool($definition);
        $remoteId = is_array($created) ? ($created['id'] ?? null) : null;
        if ($remoteId === null) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $contextId,
                $locale,
                CaptainSyncState::RESOURCE_CUSTOM_TOOL,
                $tool->key(),
                'create_failed',
                $now
            ));
            return CaptainSyncResult::failed('create_failed');
        }

        $this->syncRepository->save(CaptainSyncState::created(
            $contextId,
            $locale,
            CaptainSyncState::RESOURCE_CUSTOM_TOOL,
            $tool->key(),
            (string) $remoteId,
            $fingerprint,
            $now
        ));
        return CaptainSyncResult::created($fingerprint);
    }
}
