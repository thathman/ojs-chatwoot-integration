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
 * $globalMode preserves v1's real `eventSyncMode` setting values
 * (`'note'`/`'open_update'`, defaulting to `'note'` when blank/unset —
 * see `ChatwootIntegrationPlugin::sendChatwootEvent()`'s own `'note'`
 * default) rather than inventing new setting values, per the EVT
 * migration requirement to preserve configured event choices
 * (docs/v2/V1_INVENTORY.md §"Current event delivery behavior").
 *
 * $perEventOverrides is the "per event" half of EVT-010 v1 never had —
 * finer-grained than v1's single journal-wide switch. No admin settings
 * UI exists to populate it yet (same deliberate scope boundary as
 * KNO-020/CWO-013); it degrades safely to an empty array (global mode
 * only) until one does.
 */
final class EventDeliveryPolicy
{
    /** @param array<string,string> $perEventOverrides Event type => EventDeliveryMode. */
    public static function resolve(string $eventType, string $globalMode, array $perEventOverrides = []): string
    {
        $mode = $perEventOverrides[$eventType] ?? self::mapLegacyMode($globalMode);

        return in_array($mode, EventDeliveryMode::all(), true) ? $mode : EventDeliveryMode::PRIVATE_NOTE;
    }

    private static function mapLegacyMode(string $globalMode): string
    {
        return match ($globalMode) {
            'open_update' => EventDeliveryMode::OPEN_UPDATE_CONVERSATION,
            default => EventDeliveryMode::PRIVATE_NOTE,
        };
    }
}
