<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

function har012LabelsCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * HAR-012: retryQueueEnabled/maxRetryAttempts only ever tune the
 * legacy v1 apiQueue (Send Test Message / Sync Email Templates'
 * canned-response sync) — v2's own durable event queue hardcodes its
 * own attempt ceiling and is entirely unaffected by these settings.
 * The audit's own words: "an administrator can reasonably believe
 * those fields tune the current queue when they mostly tune the
 * legacy path." Proves the settings labels now say so explicitly,
 * rather than leaving that ambiguity undocumented in the UI itself.
 */
$locale = (string) file_get_contents("{$root}/locale/en/locale.po");

$retryLabelStart = strpos($locale, 'msgid "plugins.generic.chatwootIntegration.settings.retryQueueEnabled"');
har012LabelsCheck($retryLabelStart !== false, 'the retryQueueEnabled label must exist');
$retryLabelEnd = strpos($locale, "\n\n", $retryLabelStart);
$retryLabel = substr($locale, $retryLabelStart, $retryLabelEnd - $retryLabelStart);
har012LabelsCheck(str_contains($retryLabel, 'legacy'), 'retryQueueEnabled\'s label must clarify it only tunes the legacy queue, not real-time v2 event delivery');

$maxAttemptsLabelStart = strpos($locale, 'msgid "plugins.generic.chatwootIntegration.settings.maxRetryAttempts"');
har012LabelsCheck($maxAttemptsLabelStart !== false, 'the maxRetryAttempts label must exist');
$maxAttemptsLabelEnd = strpos($locale, "\n\n", $maxAttemptsLabelStart);
$maxAttemptsLabel = substr($locale, $maxAttemptsLabelStart, $maxAttemptsLabelEnd - $maxAttemptsLabelStart);
har012LabelsCheck(str_contains($maxAttemptsLabel, 'legacy'), 'maxRetryAttempts\' label must clarify it only tunes the legacy queue, not real-time v2 event delivery');

// Confirm the real code-level fact these labels describe: v2's own
// event-delivery attempt ceiling is a separate constant, never reads
// these settings.
$v2PluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");
har012LabelsCheck(!str_contains($v2PluginSource, "getEffectiveSetting(\$contextId, 'maxRetryAttempts'"), 'v2 event delivery must never read the legacy maxRetryAttempts setting — confirming the label\'s claim is actually true');
har012LabelsCheck(!str_contains($v2PluginSource, "getEffectiveSetting(\$contextId, 'retryQueueEnabled'"), 'v2 event delivery must never read the legacy retryQueueEnabled setting — confirming the label\'s claim is actually true');

fwrite(STDOUT, "HAR-012 legacy-queue-settings-labels tests passed\n");
