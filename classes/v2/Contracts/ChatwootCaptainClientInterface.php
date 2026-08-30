<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

/**
 * Chatwoot Captain provisioning surface — verified against a real local
 * checkout of `chatwoot/chatwoot` (`develop`, `config/routes.rb`
 * `namespace :captain do ... resources :documents, only: [:index, :show,
 * :create, :destroy] do post :sync ... end`, and
 * `enterprise/app/controllers/api/v1/accounts/captain/documents_controller.rb`).
 * Captain (including Documents) is an Enterprise-Edition-gated feature in
 * self-hosted Chatwoot — an implementation may reasonably be unavailable
 * even when the base Chatwoot API is reachable; callers must treat every
 * method here failing/returning null as "Captain provisioning
 * unavailable," never a fatal.
 *
 * There is deliberately no update/PATCH method: Chatwoot's own Documents
 * API has none for web-sourced documents — content changes are picked up
 * by re-triggering `sync` (a re-crawl of the same `external_link`), not
 * by editing fields directly.
 */
interface ChatwootCaptainClientInterface
{
    /**
     * Looks up an existing Captain document by exact external_link match
     * — used only to detect an unmanaged document before ever creating
     * one, never as a substitute for local ownership records.
     *
     * @return array{id:int|string}|null
     */
    public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array;

    /** @return array{id:int|string}|null */
    public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array;

    public function syncCaptainDocument(string $documentId): bool;
}
