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

    public function getSubmissionStateFields($submission): array
    {
        if (!is_object($submission) || !method_exists($submission, 'getData')) {
            return ['status' => null, 'stageId' => null];
        }

        try {
            $status = $submission->getData('status');
            $stageId = $submission->getData('stageId');
            return [
                'status' => is_numeric($status) ? (int) $status : null,
                'stageId' => is_numeric($stageId) ? (int) $stageId : null,
            ];
        } catch (\Throwable $e) {
            return ['status' => null, 'stageId' => null];
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
