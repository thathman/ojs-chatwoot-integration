<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Contracts;

interface OjsCompatibilityAdapterInterface
{
    public function versionFamily(): string;
    public function supportsVersion(string $version): bool;
    public function getContext($request);
    public function getUser($request);
    public function getRoleIds($user, int $contextId): array;
    public function getUserById(int $userId);
    public function getRequestedPage($request): string;
    public function getRequestedOperation($request): string;
}
