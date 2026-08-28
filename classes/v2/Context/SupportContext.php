<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

final class SupportContext
{
    public function __construct(
        private int $contextId,
        private string $contextPath,
        private ?int $userId,
        private array $roleIds,
        private string $page,
        private string $operation,
        private string $locale = ''
    ) {
        $this->roleIds = array_values(array_unique(array_map('intval', $this->roleIds)));
        sort($this->roleIds);
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function contextPath(): string
    {
        return $this->contextPath;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null && $this->userId > 0;
    }

    public function roleIds(): array
    {
        return $this->roleIds;
    }

    public function page(): string
    {
        return $this->page;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function toArray(): array
    {
        return [
            'journal' => [
                'id' => $this->contextId,
                'path' => $this->contextPath,
            ],
            'user' => [
                'authenticated' => $this->isAuthenticated(),
                'id' => $this->userId,
                'roleIds' => $this->roleIds,
            ],
            'location' => [
                'page' => $this->page,
                'operation' => $this->operation,
            ],
            'locale' => $this->locale,
        ];
    }
}
