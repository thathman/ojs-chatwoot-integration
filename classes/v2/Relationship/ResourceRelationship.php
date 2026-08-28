<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Relationship;

/**
 * Immutable relationship result for one authenticated OJS identity and one
 * resource. It describes evidence only; capability policy is a later layer.
 */
final class ResourceRelationship
{
    /** @var string[] */
    private array $types;

    /** @var array<string,bool> */
    private array $evidence;

    /**
     * @param string[] $types
     * @param array<string,bool> $evidence
     */
    public function __construct(
        private string $resourceType,
        private int $resourceId,
        array $types,
        array $evidence
    ) {
        $types = array_values(array_unique(array_filter(array_map('strval', $types))));
        sort($types);
        $this->types = $types;
        ksort($evidence);
        $this->evidence = $evidence;
    }

    public function resourceType(): string { return $this->resourceType; }
    public function resourceId(): int { return $this->resourceId; }
    public function types(): array { return $this->types; }
    public function evidence(): array { return $this->evidence; }
    public function has(string $type): bool { return in_array($type, $this->types, true); }
    public function isEmpty(): bool { return $this->types === []; }

    public function toArray(): array
    {
        return [
            'resource' => ['type' => $this->resourceType, 'id' => $this->resourceId],
            'types' => $this->types,
            'evidence' => $this->evidence,
        ];
    }
}
