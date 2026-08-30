<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics;

/**
 * Shared diagnostic contract for the whole diagnostics surface
 * (ojs_diagnose_account, ojs_diagnose_submission, and any future
 * diagnostic scope) — one shape, so every diagnostic engine speaks the
 * same language rather than each endpoint inventing its own.
 *
 * `status` hierarchy (be conservative — most rules should land on
 * CONFIRMED, UNKNOWN, or NEEDS_HUMAN; LIKELY is reserved for genuinely
 * strong circumstantial evidence, not a hedge for laziness):
 * - CONFIRMED: direct OJS evidence proves the cause.
 * - LIKELY: several deterministic facts strongly support the cause, but
 *   OJS does not directly encode it.
 * - UNKNOWN: evidence is insufficient — never fabricate a specific cause
 *   just because a scope was asked for.
 * - NEEDS_HUMAN: correct resolution requires editorial/admin judgment
 *   this codebase has no way to make (e.g. "my editor hasn't replied").
 *
 * `evidenceCodes` are machine-readable and privacy-safe by construction —
 * they name *which check* produced the result (e.g.
 * "SUPPORT_STATE_REVISION_REQUESTED"), never a raw DAO row, assignment,
 * user object, review record, exception message, or internal
 * configuration value. If a caller finds itself wanting to put a raw
 * value in an evidence code, that value belongs in a new named check
 * instead, not in the string.
 */
final class DiagnosticResult
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_LIKELY = 'likely';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_NEEDS_HUMAN = 'needs_human';

    private const VALID_STATUSES = [
        self::STATUS_CONFIRMED,
        self::STATUS_LIKELY,
        self::STATUS_UNKNOWN,
        self::STATUS_NEEDS_HUMAN,
    ];

    /**
     * @param string[] $evidenceCodes
     * @param string[] $nextActions
     */
    public function __construct(
        private string $status,
        private string $code,
        private string $summary,
        private array $evidenceCodes = [],
        private array $nextActions = [],
        private bool $retryable = false
    ) {
        if (!in_array($this->status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown DiagnosticResult status: {$status}");
        }
    }

    public static function unknown(string $code, string $summary, array $evidenceCodes = []): self
    {
        return new self(self::STATUS_UNKNOWN, $code, $summary, $evidenceCodes);
    }

    public static function needsHuman(string $code, string $summary, array $evidenceCodes = [], array $nextActions = []): self
    {
        return new self(self::STATUS_NEEDS_HUMAN, $code, $summary, $evidenceCodes, $nextActions);
    }

    public function status(): string { return $this->status; }
    public function code(): string { return $this->code; }
    public function summary(): string { return $this->summary; }
    /** @return string[] */
    public function evidenceCodes(): array { return $this->evidenceCodes; }
    /** @return string[] */
    public function nextActions(): array { return $this->nextActions; }
    public function retryable(): bool { return $this->retryable; }
}
