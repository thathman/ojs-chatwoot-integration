<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliverySettingsResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function eventDeliverySettingsResolverCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * ADM-004 (Event Bridge policy configuration UI, first slice): the one
 * genuinely new piece of logic — turning the admin settings form's raw
 * fields into what EventDeliveryPolicy::resolve() actually accepts, with
 * the "customer-visible messages require explicit opt-in, never
 * silently enabled" rule enforced as a real, testable gate.
 */

// ================================================================
// resolveGlobalMode(): legacy fallback, real-mode passthrough, and the
// consent gate.
// ================================================================
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::resolveGlobalMode('', 'note', false) === 'note',
    'an unconfigured global mode must fall back to the real legacy eventSyncMode value'
);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::resolveGlobalMode(EventDeliveryMode::AUDIT_ONLY, 'note', false) === EventDeliveryMode::AUDIT_ONLY,
    'a configured real EventDeliveryMode must take precedence over the legacy setting'
);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::resolveGlobalMode(EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE, 'note', false) === EventDeliveryMode::PRIVATE_NOTE,
    'selecting the customer-visible mode without consent must never be honored — it must fall back to a private note, never silently send a customer-visible message'
);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::resolveGlobalMode(EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE, 'note', true) === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE,
    'selecting the customer-visible mode WITH explicit consent must be honored'
);

// ================================================================
// parsePerEventOverrides(): fails closed on anything malformed, the
// same consent gate applies per-event, and a valid mapping passes
// through untouched.
// ================================================================
eventDeliverySettingsResolverCheck(EventDeliverySettingsResolver::parsePerEventOverrides('', false) === [], 'a blank overrides field must resolve to no overrides, never error');
eventDeliverySettingsResolverCheck(EventDeliverySettingsResolver::parsePerEventOverrides('not json at all', false) === [], 'malformed JSON must fail closed to no overrides, never crash delivery');
eventDeliverySettingsResolverCheck(EventDeliverySettingsResolver::parsePerEventOverrides('["not", "an", "object"]', false) === [], 'a JSON array (not an object) must be treated as invalid, never guessed into overrides');
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::parsePerEventOverrides(json_encode(['not.a.real.type' => EventDeliveryMode::PRIVATE_NOTE]), false) === [],
    'an unrecognized event type must be silently dropped, never propagated as a real override'
);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::parsePerEventOverrides(json_encode([SupportEventType::PUBLICATION_PUBLISHED => 'not-a-real-mode']), false) === [],
    'an unrecognized mode value must be silently dropped'
);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::parsePerEventOverrides(json_encode([SupportEventType::PUBLICATION_PUBLISHED => EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE]), false) === [],
    'a per-event customer-visible override without consent must be silently dropped, never applied'
);
$validOverrides = json_encode([
    SupportEventType::PUBLICATION_PUBLISHED => EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE,
    SupportEventType::SUBMISSION_CREATED => EventDeliveryMode::AUDIT_ONLY,
]);
eventDeliverySettingsResolverCheck(
    EventDeliverySettingsResolver::parsePerEventOverrides($validOverrides, true) === [
        SupportEventType::PUBLICATION_PUBLISHED => EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE,
        SupportEventType::SUBMISSION_CREATED => EventDeliveryMode::AUDIT_ONLY,
    ],
    'a valid overrides mapping with real consent must pass through exactly, including the consented customer-visible mode'
);

// ================================================================
// End-to-end through the real EventDeliveryPolicy: a resolved global
// mode/overrides pair must produce the exact real delivery mode for
// each event type, never leaking one event's override onto another.
// ================================================================
$globalMode = EventDeliverySettingsResolver::resolveGlobalMode('', 'note', false);
$overrides = EventDeliverySettingsResolver::parsePerEventOverrides($validOverrides, true);
eventDeliverySettingsResolverCheck(EventDeliveryPolicy::resolve(SupportEventType::PUBLICATION_PUBLISHED, $globalMode, $overrides) === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE, 'the real policy must honor the resolved per-event override');
eventDeliverySettingsResolverCheck(EventDeliveryPolicy::resolve(SupportEventType::SUBMISSION_ACCEPTED, $globalMode, $overrides) === EventDeliveryMode::PRIVATE_NOTE, 'an event type with no override must fall back to the real resolved global mode, never another event\'s override');

// ================================================================
// Wiring: the real enqueue path must resolve through this exact
// resolver, must read the real three new settings, and must never
// bypass the consent gate by reading eventDeliveryGlobalMode/overrides
// directly into EventDeliveryPolicy without going through the resolver.
// ================================================================
$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
$methodStart = strpos($pluginSource, 'function v2EnqueueEvent(');
eventDeliverySettingsResolverCheck($methodStart !== false, 'the plugin must implement a real v2EnqueueEvent() method');
$methodBody = substr($pluginSource, $methodStart, (int) strpos($pluginSource, "\n    }\n", $methodStart) - $methodStart);
eventDeliverySettingsResolverCheck(str_contains($methodBody, "'eventDeliveryGlobalMode'") && str_contains($methodBody, "'eventDeliveryCustomerMessageConsent'") && str_contains($methodBody, "'eventDeliveryPerEventOverridesJson'"), 'v2EnqueueEvent() must read all three real new settings');
eventDeliverySettingsResolverCheck(str_contains($methodBody, 'EventDeliverySettingsResolver::resolveGlobalMode(') && str_contains($methodBody, 'EventDeliverySettingsResolver::parsePerEventOverrides('), 'v2EnqueueEvent() must resolve both the global mode and the per-event overrides through the real resolver, never bypass the consent gate');

// UX-024: ChatwootSettingsForm no longer hardcodes any key list — it
// iterates SettingsRegistry::keys() directly, so "the form reads/saves
// this key" is now proven by the key being a real registry entry.
$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
eventDeliverySettingsResolverCheck(substr_count($formSource, 'SettingsRegistry::keys()') >= 3, 'the settings form must iterate SettingsRegistry::keys() in initData()/readInputData()/execute()');
foreach (['eventDeliveryGlobalMode', 'eventDeliveryCustomerMessageConsent', 'eventDeliveryPerEventOverridesJson'] as $key) {
    eventDeliverySettingsResolverCheck(in_array($key, SettingsRegistry::keys(), true), "SettingsRegistry must declare the real new setting '{$key}' — otherwise the settings form has no way to read/save it");
}

$templateSource = (string) file_get_contents($root . '/templates/settingsForm.tpl');
// Owner directive 2026-09-04, item E: eventDeliveryPerEventOverridesJson
// is no longer rendered as a raw named textarea — the Automation tab's
// event/action matrix (one per-row Action select, see
// ChatwootSettingsForm::EVENT_MATRIX_ROWS) is now its only real UI, and
// ChatwootSettingsForm::execute() computes+writes the setting from those
// selects instead of reading a field with this literal name. Global mode
// and consent are still real, directly-named fields.
eventDeliverySettingsResolverCheck(str_contains($templateSource, 'id="eventDeliveryGlobalMode"') && str_contains($templateSource, 'id="eventDeliveryCustomerMessageConsent"'), 'the template must render real form fields for the global mode and consent settings');
eventDeliverySettingsResolverCheck(!str_contains($templateSource, 'id="eventDeliveryPerEventOverridesJson"'), 'eventDeliveryPerEventOverridesJson must not be rendered as a raw named field — it is computed from the event/action matrix selects');
eventDeliverySettingsResolverCheck(str_contains($formSource, "setData('eventDeliveryPerEventOverridesJson'"), 'ChatwootSettingsForm::execute() must still compute and persist eventDeliveryPerEventOverridesJson, just not from a raw posted field of that name');

fwrite(STDOUT, "Event delivery settings resolver tests passed\n");
