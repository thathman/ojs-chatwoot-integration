<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Handoff;

/**
 * Best-effort idempotency for ojs_escalate_support (HOF-007).
 *
 * ponytail: APCu-backed, same fail-open/per-worker character as RateLimiter
 * — not a durable, cross-worker idempotency store. Good enough to absorb a
 * client's naive retry-on-timeout within one worker/window; a genuinely
 * durable idempotency ledger (a DB table keyed on conversation+key) is the
 * upgrade path if duplicate private notes from cross-worker retries turn
 * out to matter in practice.
 */
final class EscalationIdempotencyGuard
{
    public function __construct(private int $ttlSeconds = 3600)
    {
    }

    /**
     * Returns true the first time this key is claimed (caller should
     * proceed and create the note); returns false if already claimed
     * within the TTL (caller should skip creating a duplicate note).
     */
    public function claim(string $key): bool
    {
        if (!function_exists('apcu_add')) {
            return true;
        }

        $bucket = 'chatwoot_support_escalate_idem_' . hash('sha256', $key);
        return apcu_add($bucket, true, $this->ttlSeconds);
    }
}
