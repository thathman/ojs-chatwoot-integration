<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/** Outcome of one CaptainDocumentProvisioner::provision() call. */
final class CaptainSyncResult
{
    public const STATUS_NOOP = 'noop';
    public const STATUS_CREATED = 'created';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FAILED = 'failed';

    private function __construct(
        private string $status,
        private ?string $fingerprint,
        private ?string $reasonCode
    ) {
    }

    public static function noop(string $fingerprint): self
    {
        return new self(self::STATUS_NOOP, $fingerprint, null);
    }

    public static function created(string $fingerprint): self
    {
        return new self(self::STATUS_CREATED, $fingerprint, null);
    }

    public static function synced(string $fingerprint): self
    {
        return new self(self::STATUS_SYNCED, $fingerprint, null);
    }

    /** An unmanaged remote resource already exists — never adopted, never duplicated. */
    public static function conflict(string $reasonCode): self
    {
        return new self(self::STATUS_CONFLICT, null, $reasonCode);
    }

    public static function failed(string $reasonCode): self
    {
        return new self(self::STATUS_FAILED, null, $reasonCode);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function fingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function reasonCode(): ?string
    {
        return $this->reasonCode;
    }
}
