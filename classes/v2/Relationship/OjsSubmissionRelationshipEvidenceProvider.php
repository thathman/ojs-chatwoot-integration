<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Relationship;

use APP\facades\Repo;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SubmissionRelationshipEvidenceProviderInterface;
use PKP\security\Role;

/**
 * OJS-backed relationship evidence using the same assignment concepts as OJS
 * authorization: workflow-stage roles for author/editorial access and an
 * actual review assignment for reviewer relationship.
 */
final class OjsSubmissionRelationshipEvidenceProvider implements SubmissionRelationshipEvidenceProviderInterface
{
    public function evidence(SupportContext $context, $submission): array
    {
        $result = [
            'author' => false,
            'reviewer' => false,
            'editorial' => false,
            'manager' => false,
            'site_admin' => false,
        ];

        $userId = (int) ($context->userId() ?? 0);
        if ($userId <= 0 || !is_object($submission) || !method_exists($submission, 'getId')) {
            return $result;
        }

        $roleIds = $context->roleIds();
        $result['site_admin'] = in_array(Role::ROLE_ID_SITE_ADMIN, $roleIds, true);

        try {
            $stages = Repo::user()->getAccessibleWorkflowStages(
                $userId,
                $context->contextId(),
                $submission,
                $roleIds
            );

            $workflowRoleIds = [];
            foreach ($stages as $stageRoleIds) {
                if (!is_array($stageRoleIds)) {
                    continue;
                }
                foreach ($stageRoleIds as $roleId) {
                    $workflowRoleIds[] = (int) $roleId;
                }
            }
            $workflowRoleIds = array_values(array_unique($workflowRoleIds));

            $result['author'] = in_array(Role::ROLE_ID_AUTHOR, $workflowRoleIds, true);
            $result['manager'] = in_array(Role::ROLE_ID_MANAGER, $workflowRoleIds, true)
                || in_array(Role::ROLE_ID_MANAGER, $roleIds, true);
            $result['editorial'] = $result['manager']
                || $result['site_admin']
                || in_array(Role::ROLE_ID_SUB_EDITOR, $workflowRoleIds, true)
                || in_array(Role::ROLE_ID_ASSISTANT, $workflowRoleIds, true);
        } catch (\Throwable $e) {
            // Fail closed. Reviewer evidence is evaluated independently below.
        }

        try {
            $assignments = Repo::reviewAssignment()
                ->getCollector()
                ->filterBySubmissionIds([(int) $submission->getId()])
                ->filterByReviewerIds([$userId])
                ->getMany();

            foreach ($assignments as $assignment) {
                if (is_object($assignment)) {
                    $result['reviewer'] = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Missing/failed reviewer evidence is not interpreted as permission.
        }

        return $result;
    }
}
