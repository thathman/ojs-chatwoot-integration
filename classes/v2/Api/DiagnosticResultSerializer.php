<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Api;

use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;

/**
 * Shared allowlist serializer for the whole diagnostics surface
 * (ojs_diagnose_account, ojs_diagnose_submission). Only the explicit
 * DiagnosticResult fields ever reach the response — never a raw DAO row,
 * User/Submission object, review record, exception message, or internal
 * configuration value. If a diagnostic engine's evidenceCodes/nextActions
 * ever contain something that looks like raw data rather than a
 * machine-readable code name, that is a bug in the engine, not something
 * this serializer should try to catch by filtering strings.
 */
final class DiagnosticResultSerializer
{
    /** @return array<string,mixed> */
    public static function verified(DiagnosticResult $result, array $availableActions): array
    {
        return [
            'verified' => true,
            'diagnosed' => true,
            'status' => $result->status(),
            'code' => $result->code(),
            'summary' => $result->summary(),
            'evidenceCodes' => $result->evidenceCodes(),
            'nextActions' => $result->nextActions(),
            'retryable' => $result->retryable(),
            'availableActions' => $availableActions,
        ];
    }

    /**
     * The single generic shape for every reason a diagnostic could not run
     * — unauthenticated, denied capability, invalid scope on an
     * unauthenticated request, or (for submission diagnostics) no
     * relationship to the resource. Anti-enumeration: never distinguishable
     * from each other.
     *
     * @return array<string,mixed>
     */
    public static function unverified(SupportApiRequestContext $context, array $availableActions): array
    {
        return [
            'verified' => $context->verified(),
            'diagnosed' => false,
            'assurance' => $context->assurance(),
            'availableActions' => $availableActions,
        ];
    }
}
