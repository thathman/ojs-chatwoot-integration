<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

/**
 * IDN-015: pads a response's total elapsed time up to a fixed floor,
 * closing the timing side-channel `ojs_request_verification` would
 * otherwise have — the "user not found" branch returns almost immediately
 * (one DB lookup), while the "user found, mail sent" branch does real
 * hashing (HMAC pepper) and mail composition/send work, which is
 * measurably slower. Response *content* is already identical either way
 * (anti-enumeration); this closes the remaining timing gap.
 *
 * Only ever pads up to the floor — never truncates or speeds up a
 * naturally slow request, which would make an unusually slow real send
 * distinguishable instead.
 */
final class ResponseTimingNormalizer
{
    /**
     * @param float $startedAt microtime(true) captured at the start of the
     *                         work being normalized
     * @param float $floorSeconds the minimum total elapsed time every call
     *                            (regardless of which internal branch ran)
     *                            must appear to take
     * @param callable(float):void|null $sleeper injectable for tests —
     *                                            defaults to a real usleep()
     */
    public static function normalize(float $startedAt, float $floorSeconds, ?callable $sleeper = null): void
    {
        $elapsed = microtime(true) - $startedAt;
        $remaining = $floorSeconds - $elapsed;
        if ($remaining <= 0) {
            return;
        }

        $sleeper ??= static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $sleeper($remaining);
    }
}
