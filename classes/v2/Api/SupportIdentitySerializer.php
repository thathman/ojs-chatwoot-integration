<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use PKP\security\Role;

/**
 * Allowlist serializer for /ojsSupportGateway/identity.
 *
 * Deliberately builds an explicit field list rather than dumping any part
 * of SupportContext/SupportSession/OJS User — no email, no raw relationship
 * evidence, no arbitrary OJS object ever reaches this response.
 */
final class SupportIdentitySerializer
{
    /**
     * Deferred to a method (not a class const) so merely loading this class
     * never forces autoload of PKP\security\Role outside a full OJS runtime.
     *
     * @return array<int,string>
     */
    private static function roleLabelMap(): array
    {
        return [
            Role::ROLE_ID_SITE_ADMIN => 'site_admin',
            Role::ROLE_ID_MANAGER => 'manager',
            Role::ROLE_ID_SUB_EDITOR => 'editor',
            Role::ROLE_ID_ASSISTANT => 'assistant',
            Role::ROLE_ID_AUTHOR => 'author',
            Role::ROLE_ID_REVIEWER => 'reviewer',
            Role::ROLE_ID_READER => 'reader',
        ];
    }

    /** @return array<string,mixed> */
    public static function serialize(SupportApiRequestContext $context): array
    {
        $data = [
            'verified' => $context->verified(),
            'assurance' => $context->assurance(),
            'identity' => [
                'authenticated' => $context->identity()->isAuthenticated(),
                'roles' => self::roleLabels($context->identity()->roleIds()),
            ],
        ];

        $session = $context->session();
        if ($context->verified() && $session) {
            $data['journal'] = [
                'id' => $context->identity()->contextId(),
                'path' => $context->identity()->contextPath(),
            ];
            $data['session'] = [
                'method' => $session->verificationMethod(),
                'expiresAt' => gmdate('c', $session->idleExpiresAt()),
            ];
        }

        return $data;
    }

    /**
     * @param int[] $roleIds
     * @return string[]
     */
    private static function roleLabels(array $roleIds): array
    {
        $roleLabelMap = self::roleLabelMap();
        $labels = [];
        foreach ($roleIds as $roleId) {
            if (isset($roleLabelMap[$roleId])) {
                $labels[] = $roleLabelMap[$roleId];
            }
        }

        $labels = array_values(array_unique($labels));
        sort($labels);
        return $labels;
    }
}
