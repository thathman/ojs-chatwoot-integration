<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Normalized Event Bridge event (docs/v2/TASKLIST.md EVT-001/EVT-002,
 * docs/v2/ARCHITECTURE.md §3.9's `OJS Hook -> SupportEvent -> policy/filter
 * -> queued delivery -> Chatwoot` pipeline).
 *
 * An event never implies its own delivery — same separation of concerns
 * this codebase already uses for detection vs. authorization
 * (`ResourceContext`, `KnowledgeProviderInterface`). Delivery mode/policy
 * (EVT-010) is a later stage, not a field here.
 *
 * `attributes` is passed through as given — this class does not sanitize
 * or filter it. A caller building a `SupportEvent` from real OJS data is
 * responsible for only including fields already proven safe (the same
 * discipline every `classes/v2/Api/*Serializer.php` already follows), not
 * this DTO.
 */
final class SupportEvent
{
    private function __construct(
        private string $type,
        private int $contextId,
        private string $resourceType,
        private int $resourceId,
        private string $idempotencyKey,
        private int $occurredAt,
        private array $attributes
    ) {
    }

    /**
     * $naturalKey is the detail that makes this specific occurrence unique
     * within (type, contextId, resourceType, resourceId) — e.g. a real
     * OJS decision's own id for `submission.decision_recorded` (a
     * submission can receive many decisions; each is a distinct event),
     * or the empty string for a type that can only happen once per
     * resource (e.g. `submission.created`). Never a random value —
     * EVT-002's whole point is that the same real occurrence, replayed,
     * derives the same key.
     *
     * @param array<string,mixed> $attributes
     */
    public static function create(
        string $type,
        int $contextId,
        string $resourceType,
        int $resourceId,
        string $naturalKey,
        array $attributes = [],
        ?int $occurredAt = null
    ): self {
        return new self(
            $type,
            $contextId,
            $resourceType,
            $resourceId,
            self::deriveIdempotencyKey($type, $contextId, $resourceType, $resourceId, $naturalKey),
            $occurredAt ?? time(),
            $attributes
        );
    }

    private static function deriveIdempotencyKey(
        string $type,
        int $contextId,
        string $resourceType,
        int $resourceId,
        string $naturalKey
    ): string {
        return hash('sha256', implode('|', [$type, $contextId, $resourceType, $resourceId, $naturalKey]));
    }

    public function type(): string
    {
        return $this->type;
    }

    public function contextId(): int
    {
        return $this->contextId;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceId(): int
    {
        return $this->resourceId;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }

    /** @return array<string,mixed> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'contextId' => $this->contextId,
            'resource' => ['type' => $this->resourceType, 'id' => $this->resourceId],
            'idempotencyKey' => $this->idempotencyKey,
            'occurredAt' => $this->occurredAt,
            'attributes' => $this->attributes,
        ];
    }
}
