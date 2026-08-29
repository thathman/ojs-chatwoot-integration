<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Audit;

/** Default audit sink until AUD-001 lands a real repository. */
final class ErrorLogSupportApiAuditLogger implements SupportApiAuditLoggerInterface
{
    public function record(array $event): void
    {
        $event['loggedAt'] = gmdate('c');
        error_log('[chatwoot-support-api-audit] ' . json_encode($event, JSON_UNESCAPED_SLASHES));
    }
}
