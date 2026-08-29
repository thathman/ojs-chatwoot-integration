<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

final class CorrelationId
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Reuses a caller-supplied correlation ID (e.g. from Captain) when it looks safe to log/echo back. */
    public static function fromRequestOrGenerate(): string
    {
        $provided = trim((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''));
        if ($provided !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $provided) === 1) {
            return $provided;
        }

        return self::generate();
    }
}
