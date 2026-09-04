<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;

function automationEventMatrixCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Owner directive 2026-09-04, item E: real-browser evidence (the same
 * session that fixed HAR-006's always-on invariant) showed the
 * Automation tab was a fragmented mix of legacy retry fields, 7 raw
 * eventXxx checkboxes, a global-mode select, one detached customer-
 * message-consent checkbox, and a raw per-event-overrides JSON textarea.
 * Also found while building this: NONE of the real v2 event adapters
 * (SubmissionCreatedEventAdapter etc., dispatched only through
 * v2EnqueueEvent()) ever consulted the eventXxx settings at all — on an
 * install where v2 owns delivery (HAR-012: true for all 8 real event
 * types today), those checkboxes were a pure placebo. This is the real
 * enable-gate + single event/action matrix that replaces both.
 */
$eventTypeToEnabledRow = [
    SupportEventType::SUBMISSION_CREATED => 'eventSubmissionCreated',
    SupportEventType::SUBMISSION_DECISION_RECORDED => 'eventDecisionRecorded',
    SupportEventType::SUBMISSION_REVISION_REQUESTED => 'eventRevisionRequested',
    SupportEventType::SUBMISSION_ACCEPTED => 'eventAccepted',
    SupportEventType::SUBMISSION_REJECTED => 'eventRejected',
    SupportEventType::PUBLICATION_SCHEDULED => 'eventPublicationScheduled',
    SupportEventType::PUBLICATION_PUBLISHED => 'eventPublicationPublished',
    SupportEventType::SUBMISSION_REVIEW_SUBMITTED => 'eventReviewSubmitted',
];

// ================================================================
// Part 1: the real enable-gate. Every real SupportEventType must map to
// a real, exportable SettingsRegistry key, and v2EnqueueEvent() must
// actually consult it before enqueueing — defaulting to true (never
// silently starts dropping events for an existing install).
// ================================================================
foreach ($eventTypeToEnabledRow as $eventType => $settingKey) {
    automationEventMatrixCheck(SettingsRegistry::get($settingKey) !== null, "'{$settingKey}' (enable flag for '{$eventType}') must be a real SettingsRegistry key");
}
automationEventMatrixCheck(in_array(SupportEventType::SUBMISSION_REVIEW_SUBMITTED, SupportEventType::all(), true), 'sanity check: SUBMISSION_REVIEW_SUBMITTED must still be a real event type');

$pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
automationEventMatrixCheck(str_contains($pluginSource, 'EVENT_TYPE_ENABLED_SETTING'), 'ChatwootIntegrationV2Plugin must declare a real event-type-to-enabled-setting map');
foreach ($eventTypeToEnabledRow as $eventType => $settingKey) {
    automationEventMatrixCheck(
        (bool) preg_match('/SupportEventType::\w+\s*=>\s*\'' . preg_quote($settingKey, '/') . '\'/', $pluginSource),
        "EVENT_TYPE_ENABLED_SETTING must map some SupportEventType constant to '{$settingKey}'"
    );
}

$enqueueStart = strpos($pluginSource, 'private function v2EnqueueEvent(');
automationEventMatrixCheck($enqueueStart !== false, 'v2EnqueueEvent() must exist');
$enqueueBody = substr($pluginSource, $enqueueStart, (int) strpos($pluginSource, "\n    }\n", $enqueueStart) - $enqueueStart);
automationEventMatrixCheck(str_contains($enqueueBody, 'EVENT_TYPE_ENABLED_SETTING[$event->type()]'), 'v2EnqueueEvent() must look up the real enabled setting for the event actually being enqueued');
automationEventMatrixCheck((bool) preg_match('/getEffectiveSetting\([^)]*,\s*true\)/', $enqueueBody) || (bool) preg_match('/v2EffectiveSetting\([^)]*,\s*true\)/', $enqueueBody), 'the enable check must default to true (an unset setting must never silently start dropping events for an existing install)');
automationEventMatrixCheck(str_contains($enqueueBody, '!$this->v2Bool(') && str_contains($enqueueBody, 'enabledSettingKey'), 'v2EnqueueEvent() must check the resolved enabled setting via v2Bool() before enqueueing');
$enabledCheckPos = strpos($enqueueBody, 'enabledSettingKey');
$returnAfterCheckPos = strpos($enqueueBody, 'return;', $enabledCheckPos);
$enqueueCallPos = strpos($enqueueBody, '->enqueue(');
automationEventMatrixCheck($returnAfterCheckPos !== false && $enqueueCallPos !== false && $returnAfterCheckPos < $enqueueCallPos, 'v2EnqueueEvent() must return early (skip enqueueing) when the event type is disabled, before the real enqueue() call');

// ================================================================
// Part 2: the single-matrix UI. One real table, one row per real event
// type, no raw per-event JSON, no separate detached global consent
// checkbox, no v1/v2 terminology.
// ================================================================
$tpl = (string) file_get_contents($root . '/templates/settingsForm.tpl');
$automationPanelStart = strpos($tpl, 'id="cwPanel-automation"');
automationEventMatrixCheck($automationPanelStart !== false, 'cwPanel-automation must exist');
$automationPanelEnd = strpos($tpl, 'id="cwPanel-aiKnowledge"', $automationPanelStart);
$automationPanel = substr($tpl, $automationPanelStart, $automationPanelEnd - $automationPanelStart);

automationEventMatrixCheck(str_contains($automationPanel, 'cwEventMatrix'), 'the Automation tab must render a single real event/action matrix table');
$expectedCheckboxIds = ['eventSubmissionCreated', 'eventReviewSubmitted', 'eventRevisionRequested', 'eventAccepted', 'eventRejected', 'eventPublicationScheduled', 'eventPublicationPublished', 'eventDecisionRecorded'];
foreach ($expectedCheckboxIds as $checkboxId) {
    automationEventMatrixCheck(str_contains($automationPanel, "id=\"{$checkboxId}\""), "the matrix must render a real checkbox for '{$checkboxId}'");
}
automationEventMatrixCheck(str_contains($automationPanel, '{foreach from=$eventMatrixRows'), 'the matrix must render its rows from a real $eventMatrixRows loop, not 8 separately hand-written rows');
automationEventMatrixCheck(str_contains($automationPanel, 'cwEventActionSelect'), 'each looped row must render a real Action select');

$formSource = (string) file_get_contents($root . '/ChatwootSettingsForm.php');
automationEventMatrixCheck(str_contains($formSource, 'EVENT_MATRIX_ROWS'), 'ChatwootSettingsForm must declare the real 8-row event matrix mapping');
automationEventMatrixCheck(
    preg_match_all('/\[SupportEventType::\w+,\s*\'\w+\'\]/', $formSource) >= 8,
    'EVENT_MATRIX_ROWS must map all 8 real event types, not a subset'
);
automationEventMatrixCheck(!str_contains($automationPanel, '<textarea'), 'the Automation tab must never expose a raw JSON textarea in normal UI (moved off entirely — see event-delivery-settings-resolver.php)');
automationEventMatrixCheck(str_contains($automationPanel, 'cwCustomerVisibleWarning'), 'a customer-visible action must show an inline per-row warning, not rely on a detached global checkbox alone');
automationEventMatrixCheck(str_contains($automationPanel, 'cwEventConsentWrap'), 'the consent checkbox must be wrapped so it can be shown only when actually relevant to a selected row');

fwrite(STDOUT, "Automation event/action matrix tests passed\n");
