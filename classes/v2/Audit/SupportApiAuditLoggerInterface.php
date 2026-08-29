<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Audit;

/**
 * Seam for recording allow/deny decisions on the Support API.
 *
 * This is deliberately minimal (a placeholder ahead of the full AUD-001
 * audit migration/repository in docs/v2/TASKLIST.md). Callers must only
 * pass already-safe fields: no plaintext PIN, no support-session token, no
 * one-time binding ticket, no API secrets.
 */
interface SupportApiAuditLoggerInterface
{
    /** @param array<string,mixed> $event */
    public function record(array $event): void;
}
