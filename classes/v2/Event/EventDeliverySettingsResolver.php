<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * ADM-004: turns the admin settings form's raw Event Bridge fields
 * (the global mode select, the per-event overrides JSON field, and the
 * customer-visible-message consent checkbox) into the inputs
 * `EventDeliveryPolicy::resolve()` actually accepts — the one place the
 * "customer-visible messages require explicit opt-in, never silently
 * enabled" rule is enforced. Pure: never reads a setting itself, never
 * touches the queue or Chatwoot.
 */
final class EventDeliverySettingsResolver
{
    /**
     * @param string $configuredGlobalMode The new eventDeliveryGlobalMode
     *   setting — empty means "not yet configured, fall back to the
     *   legacy eventSyncMode value".
     * @param string $legacyEventSyncMode v1's real eventSyncMode setting
     *   ('note'/'open_update'/blank).
     */
    public static function resolveGlobalMode(string $configuredGlobalMode, string $legacyEventSyncMode, bool $customerMessageConsentGiven): string
    {
        $mode = $configuredGlobalMode !== '' ? $configuredGlobalMode : $legacyEventSyncMode;

        if ($mode === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE && !$customerMessageConsentGiven) {
            return EventDeliveryMode::PRIVATE_NOTE;
        }

        return $mode;
    }

    /**
     * Parses the per-event overrides JSON field into the shape
     * `EventDeliveryPolicy::resolve()` accepts. Fails closed: anything
     * that is not a valid `{"eventType": "mode"}` JSON object, or any
     * individual entry naming an unrecognized event type or mode, is
     * silently dropped rather than propagated — a malformed field must
     * never crash delivery, and must never be guessed into a mode.
     *
     * @return array<string,string> event type => EventDeliveryMode
     */
    public static function parsePerEventOverrides(string $json, bool $customerMessageConsentGiven): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $overrides = [];
        foreach ($decoded as $eventType => $mode) {
            if (!is_string($eventType) || !in_array($eventType, SupportEventType::all(), true)) {
                continue;
            }
            if (!is_string($mode) || !in_array($mode, EventDeliveryMode::all(), true)) {
                continue;
            }
            if ($mode === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE && !$customerMessageConsentGiven) {
                continue;
            }
            $overrides[$eventType] = $mode;
        }

        return $overrides;
    }
}
