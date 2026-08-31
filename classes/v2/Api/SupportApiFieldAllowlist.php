<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

/**
 * API-006: rejects any GET/POST field a given Support API endpoint does not
 * itself read, closing off mass-assignment/parameter-pollution surface on a
 * service-to-service API that never expects a browser CSRF field (the CSRF
 * token this integration uses travels as an `X-CSRF-TOKEN` header, not a
 * body field — see the widget fetch() call in ChatwootIntegrationV2Plugin).
 *
 * The three chatwootAccountId/chatwootContactId/chatwootConversationId
 * fields are read by SupportApiRequestResolver itself before any endpoint
 * runs, so every endpoint allows them regardless of whether it reads them
 * again afterward.
 */
final class SupportApiFieldAllowlist
{
    private const COMMON = ['chatwootAccountId', 'chatwootContactId', 'chatwootConversationId'];

    /** @var array<string,string[]> */
    private const PER_ENDPOINT = [
        'status' => [],
        'identity' => [],
        'actions' => [],
        'accountDiagnostics' => ['scope'],
        'escalate' => ['idempotencyKey', 'reason', 'submissionId'],
        'verificationRequest' => ['email', 'method', 'purpose'],
        'verificationConfirm' => ['challenge', 'pin', 'purpose'],
        'submissionVerify' => ['submissionId'],
        'submissionSupport' => ['submissionId'],
        'requiredActions' => ['submissionId'],
        'publicationStatus' => ['submissionId'],
        'paymentStatus' => ['submissionId'],
        'submissionDiagnostics' => ['scope', 'submissionId'],
        'submissions' => ['limit', 'offset'],
    ];

    /**
     * @return string|null the first unexpected field name, or null if every
     *                      submitted field is allowed for this endpoint
     */
    public static function firstUnknownField($request, string $endpoint): ?string
    {
        if (!array_key_exists($endpoint, self::PER_ENDPOINT)) {
            return null;
        }
        if (!is_object($request) || !method_exists($request, 'getUserVars')) {
            return null;
        }

        try {
            $vars = $request->getUserVars();
        } catch (\Throwable $e) {
            return null;
        }

        $allowed = array_merge(self::COMMON, self::PER_ENDPOINT[$endpoint]);
        foreach (array_keys($vars) as $key) {
            if (!in_array($key, $allowed, true)) {
                return (string) $key;
            }
        }

        return null;
    }
}
