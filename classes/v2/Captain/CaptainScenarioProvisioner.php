<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;

/**
 * Idempotent provisioning of CanonicalScenarioCatalog against Chatwoot's
 * real Scenarios API (verified against `chatwoot/chatwoot` `develop`
 * `enterprise/app/controllers/api/v1/accounts/captain/scenarios_controller.rb`,
 * nested under one assistant).
 *
 * Scenarios narrow which tools/instructions Captain reaches for; they are
 * never authorization. Every tool call a scenario's instruction leads to
 * still runs through the full Support API pipeline
 * (Identity -> Relationship -> Capability -> Serializer) exactly as if no
 * scenario existed — this provisioner has no way to weaken that even if
 * it wanted to, since it never touches server-side policy at all.
 *
 * Depends on Custom Tool provisioning having already run: a scenario's
 * instruction can only reference a tool by its real Chatwoot-assigned
 * `slug` (never a name/title match), so any canonical tool this
 * catalog's scenarios reference must already exist remotely. A tool that
 * is not yet resolvable fails that one scenario closed
 * (`required_tool_unavailable`) rather than provisioning a scenario with
 * a broken/missing tool reference.
 */
final class CaptainScenarioProvisioner
{
    public function __construct(
        private ChatwootCaptainClientInterface $client,
        private SupportKnowledgeSyncRepositoryInterface $syncRepository
    ) {
    }

    /** @return array<string,CaptainSyncResult> keyed by canonical scenario key */
    public function provisionAll(int $contextId, string $locale, int $assistantId, int $now): array
    {
        [$toolSlugsByKey, $toolTitlesByKey] = $this->resolveToolReferences();

        $existingScenarioTitles = null; // fetched at most once per call, only if actually needed
        $results = [];

        foreach (CanonicalScenarioCatalog::all() as $scenario) {
            $results[$scenario->key()] = $this->provisionOne(
                $contextId,
                $locale,
                $assistantId,
                $scenario,
                $toolSlugsByKey,
                $toolTitlesByKey,
                $now,
                $existingScenarioTitles
            );
        }

        return $results;
    }

    /**
     * @return array{0:array<string,string>,1:array<string,string>} [canonicalToolKey => slug, canonicalToolKey => title]
     */
    private function resolveToolReferences(): array
    {
        $toolTitlesByKey = [];
        foreach (CanonicalToolCatalog::all() as $tool) {
            $toolTitlesByKey[$tool->key()] = $tool->title();
        }

        $slugByTitle = [];
        foreach ($this->client->listCaptainCustomTools() as $existingTool) {
            $title = (string) ($existingTool['title'] ?? '');
            $slug = $existingTool['slug'] ?? null;
            if ($title !== '' && is_string($slug) && $slug !== '') {
                $slugByTitle[$title] = $slug;
            }
        }

        $toolSlugsByKey = [];
        foreach ($toolTitlesByKey as $key => $title) {
            if (isset($slugByTitle[$title])) {
                $toolSlugsByKey[$key] = $slugByTitle[$title];
            }
        }

        return [$toolSlugsByKey, $toolTitlesByKey];
    }

    /** @param array<int,string>|null $existingScenarioTitles passed by reference so it is fetched at most once across the whole provisionAll() call */
    private function provisionOne(
        int $contextId,
        string $locale,
        int $assistantId,
        CanonicalScenarioDefinition $scenario,
        array $toolSlugsByKey,
        array $toolTitlesByKey,
        int $now,
        ?array &$existingScenarioTitles
    ): CaptainSyncResult {
        $instruction = $scenario->resolveInstruction($toolSlugsByKey, $toolTitlesByKey);
        if ($instruction === null) {
            return CaptainSyncResult::failed('required_tool_unavailable');
        }

        $definition = [
            'title' => $scenario->title(),
            'description' => $scenario->description(),
            'instruction' => $instruction,
            'enabled' => true,
        ];
        $fingerprint = hash('sha256', (string) json_encode($definition));

        $state = $this->syncRepository->find($contextId, $locale, CaptainSyncState::RESOURCE_SCENARIO, $scenario->key());

        if ($state !== null && $state->isOwned()) {
            if ($state->lastSuccessfulFingerprint() === $fingerprint) {
                return CaptainSyncResult::noop($fingerprint);
            }

            $updated = $this->client->updateCaptainScenario($assistantId, (string) $state->remoteResourceId(), $definition);
            if (!$updated) {
                $this->syncRepository->save($state->withError('update_failed', $now));
                return CaptainSyncResult::failed('update_failed');
            }

            $this->syncRepository->save($state->withSuccess($fingerprint, $now));
            return CaptainSyncResult::synced($fingerprint);
        }

        if ($existingScenarioTitles === null) {
            $existingScenarioTitles = array_map(
                static fn (array $s): string => (string) ($s['title'] ?? ''),
                $this->client->listCaptainScenarios($assistantId)
            );
        }

        if (in_array($scenario->title(), $existingScenarioTitles, true)) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $contextId,
                $locale,
                CaptainSyncState::RESOURCE_SCENARIO,
                $scenario->key(),
                'unmanaged_scenario_exists',
                $now
            ));
            return CaptainSyncResult::conflict('unmanaged_scenario_exists');
        }

        $created = $this->client->createCaptainScenario($assistantId, $definition);
        $remoteId = is_array($created) ? ($created['id'] ?? null) : null;
        if ($remoteId === null) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $contextId,
                $locale,
                CaptainSyncState::RESOURCE_SCENARIO,
                $scenario->key(),
                'create_failed',
                $now
            ));
            return CaptainSyncResult::failed('create_failed');
        }

        $this->syncRepository->save(CaptainSyncState::created(
            $contextId,
            $locale,
            CaptainSyncState::RESOURCE_SCENARIO,
            $scenario->key(),
            (string) $remoteId,
            $fingerprint,
            $now
        ));
        return CaptainSyncResult::created($fingerprint);
    }
}
