<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportKnowledgeSyncRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;

/**
 * Idempotent Captain Document provisioning (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
 * §6). The OJS Knowledge Compiler stays authoritative — Chatwoot never
 * becomes the master copy of journal policy; this class only ever pushes
 * a pointer (the generated `/support-knowledge/` URL) and asks Chatwoot
 * to (re-)crawl it.
 *
 *   current fingerprint
 *        v
 *   local ownership record for (context, locale, captain_document)?
 *        no  -> an unmanaged document already at this URL?
 *                  yes -> conflict (never adopt, never duplicate)
 *                  no  -> create, record ownership
 *        yes -> fingerprint unchanged?
 *                  yes -> no-op
 *                  no  -> sync (re-crawl), record new fingerprint
 *
 * Never deletes, never overwrites a document this codebase did not create
 * itself (proven only by the local CaptainSyncState record — a name/URL
 * match alone is never ownership proof).
 */
final class CaptainDocumentProvisioner
{
    public function __construct(
        private ChatwootCaptainClientInterface $client,
        private SupportKnowledgeSyncRepositoryInterface $syncRepository
    ) {
    }

    public function provision(
        KnowledgeCompilation $compilation,
        int $assistantId,
        string $documentName,
        string $knowledgeRootUrl,
        int $now
    ): CaptainSyncResult {
        $fingerprint = $compilation->fingerprint();
        $state = $this->syncRepository->find($compilation->contextId(), $compilation->locale(), CaptainSyncState::RESOURCE_DOCUMENT);

        if ($state !== null && $state->isOwned()) {
            return $this->resync($state, $fingerprint, $now);
        }

        return $this->createOrDetectConflict($compilation, $assistantId, $documentName, $knowledgeRootUrl, $fingerprint, $now);
    }

    private function resync(CaptainSyncState $state, string $fingerprint, int $now): CaptainSyncResult
    {
        if ($state->lastSuccessfulFingerprint() === $fingerprint) {
            return CaptainSyncResult::noop($fingerprint);
        }

        $remoteId = $state->remoteResourceId();
        $synced = $remoteId !== null && $this->client->syncCaptainDocument($remoteId);
        if (!$synced) {
            $this->syncRepository->save($state->withError('sync_failed', $now));
            return CaptainSyncResult::failed('sync_failed');
        }

        $this->syncRepository->save($state->withSuccess($fingerprint, $now));
        return CaptainSyncResult::synced($fingerprint);
    }

    private function createOrDetectConflict(
        KnowledgeCompilation $compilation,
        int $assistantId,
        string $documentName,
        string $knowledgeRootUrl,
        string $fingerprint,
        int $now
    ): CaptainSyncResult {
        $existing = $this->client->findCaptainDocumentByExternalLink($assistantId, $knowledgeRootUrl);
        if ($existing !== null) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $compilation->contextId(),
                $compilation->locale(),
                CaptainSyncState::RESOURCE_DOCUMENT,
                'unmanaged_document_exists',
                $now
            ));
            return CaptainSyncResult::conflict('unmanaged_document_exists');
        }

        $created = $this->client->createCaptainDocument($assistantId, $documentName, $knowledgeRootUrl);
        $remoteId = is_array($created) ? ($created['id'] ?? null) : null;
        if ($remoteId === null) {
            $this->syncRepository->save(CaptainSyncState::unresolved(
                $compilation->contextId(),
                $compilation->locale(),
                CaptainSyncState::RESOURCE_DOCUMENT,
                'create_failed',
                $now
            ));
            return CaptainSyncResult::failed('create_failed');
        }

        $this->syncRepository->save(CaptainSyncState::created(
            $compilation->contextId(),
            $compilation->locale(),
            CaptainSyncState::RESOURCE_DOCUMENT,
            (string) $remoteId,
            $fingerprint,
            $now
        ));
        return CaptainSyncResult::created($fingerprint);
    }
}
