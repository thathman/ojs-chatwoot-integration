<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS editorial decision into a normalized
 * `submission.decision_recorded` SupportEvent (docs/v2/TASKLIST.md
 * EVT-004), mirroring v1's `ChatwootIntegrationPlugin::handleEditorDecision()`.
 *
 * Excludes author identity — same delivery-vs-fact separation as
 * `SubmissionCreatedEventAdapter` (EVT-003).
 *
 * `naturalKey` is the decision's own id (a real OJS `Decision` — a
 * `PKP\core\DataObject` — has a stable, unique id independent of the
 * submission it belongs to). A submission can receive many decisions over
 * its lifetime; each is a genuinely distinct occurrence, so reusing the
 * submission id alone as the key (as `SubmissionCreatedEventAdapter` does,
 * correctly, since a submission is only ever "created" once) would
 * silently collapse every later decision into the first one's key.
 */
final class DecisionRecordedEventAdapter
{
    public static function fromDecision($decision, $submission, int $contextId, string $title): ?SupportEvent
    {
        if (!is_object($decision) || !method_exists($decision, 'getId') || !method_exists($decision, 'getData')) {
            return null;
        }
        if (!is_object($submission) || !method_exists($submission, 'getId')) {
            return null;
        }

        $decisionId = (int) $decision->getId();
        $submissionId = (int) $submission->getId();
        if ($decisionId <= 0 || $submissionId <= 0 || $contextId <= 0) {
            return null;
        }

        $decisionCode = (int) $decision->getData('decision');
        $stageId = (int) ($decision->getData('stageId') ?? (method_exists($submission, 'getData') ? $submission->getData('stageId') : 0));

        return SupportEvent::create(
            SupportEventType::SUBMISSION_DECISION_RECORDED,
            $contextId,
            'submission',
            $submissionId,
            (string) $decisionId,
            [
                'title' => $title,
                'decisionCode' => $decisionCode,
                'stageId' => $stageId,
            ]
        );
    }
}
