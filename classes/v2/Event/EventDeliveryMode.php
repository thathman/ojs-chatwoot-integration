<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Event Bridge delivery modes (docs/v2/TASKLIST.md EVT-010,
 * docs/v2/ARCHITECTURE.md §3.9): what a queued `SupportEvent` actually
 * does once it reaches Chatwoot. Deciding *which* mode applies is
 * `EventDeliveryPolicy`'s job; this class only names the modes.
 *
 * v1 only ever had two ({@see EventDeliveryPolicy}'s `note`/`open_update`
 * mapping) — `UPDATE_CONTEXT`, `OPT_IN_CUSTOMER_MESSAGE` and `AUDIT_ONLY`
 * are new v2 modes with no v1 equivalent yet to wire up.
 */
final class EventDeliveryMode
{
    public const UPDATE_CONTEXT = 'update_context';
    public const PRIVATE_NOTE = 'private_note';
    public const OPEN_UPDATE_CONVERSATION = 'open_update_conversation';
    public const OPT_IN_CUSTOMER_MESSAGE = 'opt_in_customer_message';
    public const AUDIT_ONLY = 'audit_only';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::UPDATE_CONTEXT,
            self::PRIVATE_NOTE,
            self::OPEN_UPDATE_CONVERSATION,
            self::OPT_IN_CUSTOMER_MESSAGE,
            self::AUDIT_ONLY,
        ];
    }
}
