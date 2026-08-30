<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncState;

interface SupportKnowledgeSyncRepositoryInterface
{
    public function find(int $contextId, string $locale, string $resourceType, string $resourceKey = ''): ?CaptainSyncState;

    public function save(CaptainSyncState $state): void;
}
