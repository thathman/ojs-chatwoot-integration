<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Event;

/**
 * Converts a real OJS editorial decision into a normalized SupportEvent
 * (docs/v2/TASKLIST.md EVT-004/EVT-006), mirroring v1's
 * `ChatwootIntegrationPlugin::handleEditorDecision()` and its
 * `mapDecisionEventKey()` helper — the same real hook is v1's only source
 * for revision-requested/accepted/rejected detection (there is no separate
 * v1 revision hook; EVT-006 "where stable" is satisfied by this decision
 * mapping, not a new hook).
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
    private const PENDING_REVISIONS = 4;
    private const RESUBMIT = 5;
    private const RECOMMEND_PENDING_REVISIONS = 10;
    private const RECOMMEND_RESUBMIT = 11;
    private const ACCEPT = 2;
    private const RECOMMEND_ACCEPT = 9;
    private const DECLINE = 6;
    private const INITIAL_DECLINE = 8;
    private const RECOMMEND_DECLINE = 12;

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
            self::mapDecisionEventType($decisionCode),
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

    private static function mapDecisionEventType(int $decisionCode): string
    {
        return match (true) {
            in_array($decisionCode, [self::PENDING_REVISIONS, self::RESUBMIT, self::RECOMMEND_PENDING_REVISIONS, self::RECOMMEND_RESUBMIT], true) => SupportEventType::SUBMISSION_REVISION_REQUESTED,
            in_array($decisionCode, [self::ACCEPT, self::RECOMMEND_ACCEPT], true) => SupportEventType::SUBMISSION_ACCEPTED,
            in_array($decisionCode, [self::DECLINE, self::INITIAL_DECLINE, self::RECOMMEND_DECLINE], true) => SupportEventType::SUBMISSION_REJECTED,
            default => SupportEventType::SUBMISSION_DECISION_RECORDED,
        };
    }
}
