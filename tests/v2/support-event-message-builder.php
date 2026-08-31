<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEvent;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventMessageBuilder;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;

function supportEventMessageBuilderCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// __() (PKP's global translation function) is unavailable in this
// plain-PHP test environment, so every branch below exercises the plain
// English fallback path — proving each event shape maps to a distinct,
// real, informative fallback message, not that __() itself works
// (verified separately by function_exists('__') being false here).
supportEventMessageBuilderCheck(!function_exists('__'), 'sanity: this test environment must not have PKP\'s __() available, or the fallback branch below is not what\'s actually being tested');

$created = SupportEvent::create(SupportEventType::SUBMISSION_CREATED, 7, 'submission', 900, '', ['title' => 'My Paper']);
supportEventMessageBuilderCheck(str_contains(SupportEventMessageBuilder::build($created), '900') && str_contains(SupportEventMessageBuilder::build($created), 'My Paper'), 'submission.created message must reference the submission id and title');

$decision = SupportEvent::create(SupportEventType::SUBMISSION_ACCEPTED, 7, 'submission', 900, 'decision-1', ['title' => 'My Paper', 'decisionCode' => 2]);
$decisionMessage = SupportEventMessageBuilder::build($decision);
supportEventMessageBuilderCheck(str_contains($decisionMessage, 'decision'), 'a decision-derived submission.accepted event (carries decisionCode) must use the editorDecision message shape, not statusChanged');

$statusChanged = SupportEvent::create(SupportEventType::SUBMISSION_ACCEPTED, 7, 'submission', 900, '3:5', ['title' => 'My Paper', 'oldStatus' => 3, 'newStatus' => 5]);
$statusMessage = SupportEventMessageBuilder::build($statusChanged);
supportEventMessageBuilderCheck(
    $statusMessage !== $decisionMessage && str_contains($statusMessage, 'status'),
    'a status-derived submission.accepted event (carries newStatus) must use the statusChanged message shape, distinct from the decision one, even though the event type is identical'
);

$publication = SupportEvent::create(SupportEventType::PUBLICATION_PUBLISHED, 7, 'submission', 900, '501', ['title' => 'My Paper', 'publicationStatus' => 3]);
supportEventMessageBuilderCheck(str_contains(SupportEventMessageBuilder::build($publication), '900'), 'publication.published message must reference the submission id');

$review = SupportEvent::create(SupportEventType::SUBMISSION_REVIEW_SUBMITTED, 7, 'submission', 900, '301', ['title' => 'My Paper']);
supportEventMessageBuilderCheck(str_contains(SupportEventMessageBuilder::build($review), 'review'), 'submission.review_submitted must produce a review-specific message');

// No message for any real event type may ever contain a reviewer/author
// identity field — messages only ever come from the event's own
// already-safe attributes (title/decisionCode/status), which structurally
// never carry PII (SupportEvent's own contract).
foreach ([$created, $decision, $statusChanged, $publication, $review] as $event) {
    $message = SupportEventMessageBuilder::build($event);
    foreach (['email', 'orcid', '@'] as $forbidden) {
        supportEventMessageBuilderCheck(!str_contains($message, $forbidden), "no built message may ever contain '{$forbidden}' — messages are built only from the event's own safe attributes");
    }
}

fwrite(STDOUT, "Support event message builder tests passed\n");
