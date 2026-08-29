<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot;

/**
 * Mirrors the current v1 widget identifier only for handshake verification.
 *
 * This is deliberately isolated because v1's global reviewer masking will be
 * replaced by resource-specific privacy policy in a later migration slice.
 */
final class LegacyWidgetIdentifierResolver
{
    public function resolve(int $userId, int $contextId, bool $maskedReviewer): string
    {
        if ($userId <= 0 || $contextId <= 0) {
            return '';
        }

        if ($maskedReviewer) {
            return 'reviewer_' . hash('sha256', $userId . $contextId);
        }

        return (string) $userId;
    }
}
