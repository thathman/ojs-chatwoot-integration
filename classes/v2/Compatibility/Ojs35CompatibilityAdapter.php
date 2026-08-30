<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

final class Ojs35CompatibilityAdapter implements OjsCompatibilityAdapterInterface
{
    public function versionFamily(): string
    {
        return '3.5';
    }

    public function supportsVersion(string $version): bool
    {
        return preg_match('/^3\.5\./', trim($version)) === 1;
    }

    public function getContext($request)
    {
        return is_object($request) && method_exists($request, 'getContext')
            ? $request->getContext()
            : null;
    }

    public function getUser($request)
    {
        return is_object($request) && method_exists($request, 'getUser')
            ? $request->getUser()
            : null;
    }

    public function getRoleIds($user, int $contextId): array
    {
        if (!is_object($user) || !method_exists($user, 'getRoles')) {
            return [];
        }

        $ids = [];
        foreach ($user->getRoles($contextId) as $role) {
            if (is_object($role) && method_exists($role, 'getId')) {
                $ids[] = (int) $role->getId();
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Re-derives a live user by ID, independent of any request/session.
     *
     * Used to refresh a support session's roles at call time instead of
     * trusting whatever roles a user held at bind time.
     */
    public function getUserById(int $userId)
    {
        if ($userId <= 0 || !class_exists('\PKP\user\Repo')) {
            return null;
        }

        return \PKP\user\Repo::user()->get($userId);
    }

    /**
     * Loads a submission by ID only — never trusts a journal/context claim
     * that came with the ID. Callers must independently confirm the
     * submission's own contextId matches the caller's journal (see
     * SubmissionRelationshipResolver::resolve()).
     */
    public function getSubmissionById(int $submissionId)
    {
        if ($submissionId <= 0 || !class_exists('\APP\facades\Repo')) {
            return null;
        }

        return \APP\facades\Repo::submission()->get($submissionId);
    }

    /**
     * Candidate discovery only — mirrors the same collector call PKP core
     * itself uses for "my assignments" (PKPBackendSubmissionsController::
     * assigned() / reviews() / bulkDeleteIncompleteSubmissions(), pkp-lib
     * stable-3_5_0): assignedTo() with no role restriction matches any
     * stage assignment (author, editorial, ...) or non-declined/cancelled
     * review assignment for that user. This is deliberately broad; the
     * caller's relationship resolver is what actually decides author vs.
     * reviewer vs. "not really theirs".
     */
    public function listCandidateSubmissions(int $contextId, int $userId, int $candidateCap): array
    {
        if ($contextId <= 0 || $userId <= 0 || $candidateCap <= 0 || !class_exists('\APP\facades\Repo')) {
            return [];
        }

        try {
            $collector = \APP\facades\Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->assignedTo([$userId])
                ->limit($candidateCap)
                ->offset(0);

            // ->all(), not ->toArray(): the latter would recursively convert
            // each Submission object into a plain array via Arrayable.
            return $collector->getMany()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Verified against pkp-lib stable-3_5_0: title lives on the current Publication, not Submission. */
    public function getSubmissionTitle($submission): string
    {
        if (!is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
            return '';
        }

        try {
            $publication = $submission->getCurrentPublication();
            if (!is_object($publication) || !method_exists($publication, 'getLocalizedTitle')) {
                return '';
            }
            return trim((string) $publication->getLocalizedTitle());
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 classes/publication/PKPPublication.php
     * (getDoi()) and ojs classes/publication/Publication.php (getIssueId()).
     *
     * @return array{doi:?string,issueId:?int}
     */
    public function getPublicationFields($submission): array
    {
        if (!is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
            return ['doi' => null, 'issueId' => null];
        }

        try {
            $publication = $submission->getCurrentPublication();
            if (!is_object($publication)) {
                return ['doi' => null, 'issueId' => null];
            }
            $doi = method_exists($publication, 'getDoi') ? $publication->getDoi() : null;
            $issueId = method_exists($publication, 'getIssueId') ? $publication->getIssueId() : null;
            return [
                'doi' => is_string($doi) && $doi !== '' ? $doi : null,
                'issueId' => is_numeric($issueId) ? (int) $issueId : null,
            ];
        } catch (\Throwable $e) {
            return ['doi' => null, 'issueId' => null];
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 ojs classes/issue/Issue.php
     * (getVolume/getNumber/getYear/getPublished) and Repository.php (get()).
     *
     * @return array{volume:?int,number:?int,year:?int,published:bool}|null
     */
    public function getIssueInfo(int $issueId): ?array
    {
        if ($issueId <= 0 || !class_exists('\APP\facades\Repo')) {
            return null;
        }

        try {
            $issue = \APP\facades\Repo::issue()->get($issueId);
            if (!is_object($issue)) {
                return null;
            }
            $volume = method_exists($issue, 'getVolume') ? $issue->getVolume() : null;
            $number = method_exists($issue, 'getNumber') ? $issue->getNumber() : null;
            $year = method_exists($issue, 'getYear') ? $issue->getYear() : null;
            return [
                'volume' => is_numeric($volume) ? (int) $volume : null,
                'number' => is_numeric($number) ? (int) $number : null,
                'year' => is_numeric($year) ? (int) $year : null,
                'published' => method_exists($issue, 'getPublished') ? (bool) $issue->getPublished() : false,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 ojs pages/article/ArticleHandler.php,
     * which builds the public article URL the same way:
     * $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'article', 'view', [$submission->getBestId()]).
     * Never called unless the caller has already confirmed the submission is
     * actually published (see supportPublicationStatusRequest()) — this
     * method itself does not check status, since it has no state fields
     * to check without an extra query the caller already has cheaper access to.
     */
    public function getPublicSubmissionUrl($request, $submission): ?string
    {
        if (!is_object($request) || !is_object($submission) || !method_exists($submission, 'getBestId')) {
            return null;
        }

        try {
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'url')) {
                return null;
            }
            $url = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$submission->getBestId()]);
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Verified against pkp-lib stable-3_5_0 classes/core/PKPApplication.php
    private const WORKFLOW_STAGE_ID_INTERNAL_REVIEW = 2;
    private const WORKFLOW_STAGE_ID_EXTERNAL_REVIEW = 3;

    public function getSubmissionStateFields($submission): array
    {
        if (!is_object($submission) || !method_exists($submission, 'getData')) {
            return ['status' => null, 'stageId' => null, 'reviewRoundStatus' => null, 'submissionProgress' => null];
        }

        try {
            $status = $submission->getData('status');
            $stageId = $submission->getData('stageId');
            $stageId = is_numeric($stageId) ? (int) $stageId : null;
            $submissionProgress = $submission->getData('submissionProgress');

            return [
                'status' => is_numeric($status) ? (int) $status : null,
                'stageId' => $stageId,
                'reviewRoundStatus' => $this->getCurrentReviewRoundStatus($submission, $stageId),
                'submissionProgress' => is_string($submissionProgress) ? $submissionProgress : null,
            ];
        } catch (\Throwable $e) {
            return ['status' => null, 'stageId' => null, 'reviewRoundStatus' => null, 'submissionProgress' => null];
        }
    }

    /**
     * Only meaningful inside an active review stage; the round's `status`
     * column is maintained live by ReviewRoundDAO on every relevant event
     * (decisions, revision uploads, assignment changes) — read-only here,
     * this never recomputes it independently.
     */
    private function getCurrentReviewRoundStatus($submission, ?int $stageId): ?int
    {
        if ($stageId !== self::WORKFLOW_STAGE_ID_INTERNAL_REVIEW && $stageId !== self::WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            return null;
        }
        if (!method_exists($submission, 'getId') || !class_exists('\PKP\db\DAORegistry')) {
            return null;
        }

        try {
            $reviewRoundDao = \PKP\db\DAORegistry::getDAO('ReviewRoundDAO');
            $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId((int) $submission->getId(), $stageId);
            if (!is_object($reviewRound) || !method_exists($reviewRound, 'getStatus')) {
                return null;
            }
            $status = $reviewRound->getStatus();
            return is_numeric($status) ? (int) $status : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Every ReviewAssignment this user holds for this submission, across all
     * rounds — status only, via PKP's own computed getStatus() (never
     * re-derives its overdue-date/decline/resend logic independently, see
     * classes/v2/State/RequiredActionMapper.php for how these are used).
     *
     * @return int[]
     */
    public function getReviewAssignmentStatuses(int $submissionId, int $userId): array
    {
        if ($submissionId <= 0 || $userId <= 0 || !class_exists('\APP\facades\Repo')) {
            return [];
        }

        try {
            $assignments = \APP\facades\Repo::reviewAssignment()
                ->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->filterByReviewerIds([$userId])
                ->getMany();

            $statuses = [];
            foreach ($assignments as $assignment) {
                if (!is_object($assignment) || !method_exists($assignment, 'getStatus')) {
                    continue;
                }
                $status = $assignment->getStatus();
                if (is_numeric($status)) {
                    $statuses[] = (int) $status;
                }
            }
            return $statuses;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getRequestedPage($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedPage')
            ? trim((string) $request->getRequestedPage())
            : '';
    }

    public function getRequestedOperation($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedOp')
            ? trim((string) $request->getRequestedOp())
            : '';
    }
}
