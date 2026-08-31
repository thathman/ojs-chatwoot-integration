<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Context;

/**
 * Detection-only description of the resource currently in view.
 *
 * This object never implies access, ownership, verification or capability.
 */
final class ResourceContext
{
    public function __construct(
        private string $type,
        private int $id,
        private string $source
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }
    public function id(): int
    {
        return $this->id;
    }
    public function source(): string
    {
        return $this->source;
    }

    public function toArray(): array
    {
        return [
            'contract' => 'detection_only',
            'type' => $this->type,
            'id' => $this->id,
            'source' => $this->source,
        ];
    }
}
