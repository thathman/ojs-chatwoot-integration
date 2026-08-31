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

    /**
     * All Custom Tools currently configured on the account (verified
     * against `chatwoot/chatwoot` `develop`
     * `enterprise/app/controllers/api/v1/accounts/captain/custom_tools_controller.rb`'s
     * `index` action — unpaginated in practice, since the account-wide cap
     * is 15 tools, `Captain::CustomTool::MAX_PER_ACCOUNT`). Used only to
     * detect an unmanaged tool by title before ever creating one.
     *
     * @return array<int,array{id:int|string,title:string}>
     */
    public function listCaptainCustomTools(): array;

    /**
     * @param array{title:string,description:string,endpoint_url:string,http_method:string,auth_type:string,auth_config:array<string,string>,param_schema:array<int,array{name:string,type:string,description:string,required:bool}>,request_template:?string,response_template:?string} $definition
     *
     * @return array{id:int|string}|null
     */
    public function createCaptainCustomTool(array $definition): ?array;

    /** @param array<string,mixed> $definition Same shape as createCaptainCustomTool(). */
    public function updateCaptainCustomTool(string $toolId, array $definition): bool;

    /**
     * Verified against `chatwoot/chatwoot` `develop`
     * `enterprise/app/controllers/api/v1/accounts/captain/scenarios_controller.rb`
     * (nested under `assistants`, per `config/routes.rb`'s
     * `resources :assistants do ... resources :scenarios end end`).
     *
     * @return array<int,array{id:int|string,title:string}>
     */
    public function listCaptainScenarios(int $assistantId): array;

    /**
     * @param array{title:string,description:string,instruction:string,enabled:bool} $definition
     *
     * @return array{id:int|string}|null
     */
    public function createCaptainScenario(int $assistantId, array $definition): ?array;

    /** @param array<string,mixed> $definition Same shape as createCaptainScenario(). */
    public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool;
}
