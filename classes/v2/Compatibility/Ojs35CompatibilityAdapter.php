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
