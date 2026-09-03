<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Health;

/**
 * AUD-008/AUD-011: a safe operational snapshot of the v2 durable event
 * queue for the admin Support Gateway health section — counts and
 * already-defined internal error-code labels only, built from
 * SupportEventQueueRepositoryInterface::queueHealthSnapshot(). Never a
 * row's `attributes`/payload content, and never a raw exception message
 * (`deadLetterErrorCodes()` keys are the same small set of internal
 * codes `markFailed()` already writes — `delivery_failed`/
 * `internal_error`/etc — never free text).
 */
final class EventQueueHealthReport
{
    /** @param array<string,int> $deadLetterErrorCodes */
    public function __construct(
        private int $pendingCount,
        private int $retryingCount,
        private int $deadLetterCount,
        private ?int $oldestPendingAgeSeconds,
        private array $deadLetterErrorCodes
    ) {
    }

    /** @param array{pendingCount:int,retryingCount:int,deadLetterCount:int,oldestPendingAgeSeconds:?int,deadLetterErrorCodes:array<string,int>} $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self(
            (int) $snapshot['pendingCount'],
            (int) $snapshot['retryingCount'],
            (int) $snapshot['deadLetterCount'],
            $snapshot['oldestPendingAgeSeconds'] !== null ? (int) $snapshot['oldestPendingAgeSeconds'] : null,
            $snapshot['deadLetterErrorCodes']
        );
    }

    public function pendingCount(): int
    {
        return $this->pendingCount;
    }

    public function retryingCount(): int
    {
        return $this->retryingCount;
    }

    public function deadLetterCount(): int
    {
        return $this->deadLetterCount;
    }

    public function oldestPendingAgeSeconds(): ?int
    {
        return $this->oldestPendingAgeSeconds;
    }

    /** @return array<string,int> */
    public function deadLetterErrorCodes(): array
    {
        return $this->deadLetterErrorCodes;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'pendingCount' => $this->pendingCount,
            'retryingCount' => $this->retryingCount,
            'deadLetterCount' => $this->deadLetterCount,
            'oldestPendingAgeSeconds' => $this->oldestPendingAgeSeconds,
            'deadLetterErrorCodes' => $this->deadLetterErrorCodes,
        ];
    }
}
