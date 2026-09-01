<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Decides which `EventDeliveryMode` applies to a given event type for a
 * journal (docs/v2/TASKLIST.md EVT-010) — the "policy/filter" stage of
 * `OJS Hook -> SupportEvent -> policy/filter -> queued delivery ->
 * Chatwoot` (docs/v2/ARCHITECTURE.md §3.9). A pure decision function: it
 * never touches the queue, the Chatwoot API, or any real delivery — same
 * detection-vs-decision separation as `ResourceContextResolver` vs.
 * capability policy.
 *
 * $globalMode accepts either v1's real legacy `eventSyncMode` setting
 * values (`'note'`/`'open_update'`, defaulting to `'note'`-equivalent
 * when blank/unset/unrecognized — see
 * `ChatwootIntegrationPlugin::sendChatwootEvent()`'s own `'note'`
 * default) or, since ADM-004, a real `EventDeliveryMode::*` constant
 * directly (what the admin settings form's global delivery-mode field
 * now stores) — preserving both v1's configured event choices
 * (docs/v2/V1_INVENTORY.md §"Current event delivery behavior") and the
 * newer per-journal ability to pick any real v2 mode as the default.
 *
 * $perEventOverrides is the "per event" half of EVT-010 v1 never had —
 * finer-grained than v1's single journal-wide switch. Populated by the
 * admin settings form's per-event overrides field since ADM-004; the
 * `OPT_IN_CUSTOMER_MESSAGE` mode is only ever accepted here if the
 * caller already applied the real consent gate
 * (`EventDeliverySettingsResolver`) — this class itself has no opinion
 * on consent, it only ever rejects an unrecognized mode string.
 */
final class EventDeliveryPolicy
{
    /** @param array<string,string> $perEventOverrides Event type => EventDeliveryMode. */
    public static function resolve(string $eventType, string $globalMode, array $perEventOverrides = []): string
    {
        $mode = $perEventOverrides[$eventType] ?? self::normalizeGlobalMode($globalMode);

        return in_array($mode, EventDeliveryMode::all(), true) ? $mode : EventDeliveryMode::PRIVATE_NOTE;
    }

    private static function normalizeGlobalMode(string $globalMode): string
    {
        if (in_array($globalMode, EventDeliveryMode::all(), true)) {
            return $globalMode;
        }

        return match ($globalMode) {
            'open_update' => EventDeliveryMode::OPEN_UPDATE_CONVERSATION,
            default => EventDeliveryMode::PRIVATE_NOTE,
        };
    }
}
