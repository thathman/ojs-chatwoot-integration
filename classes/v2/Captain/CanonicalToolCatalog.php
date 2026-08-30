<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * The fixed, deliberately small canonical Captain Custom Tool set — one
 * tool per Support API endpoint that makes sense as an LLM-callable tool,
 * never one tool per provider or per journal fact (journal facts belong
 * in Captain Documents via the Knowledge Compiler, not tools). Kept at or
 * under Chatwoot's own account-wide cap of 15 custom tools
 * (`Captain::CustomTool::MAX_PER_ACCOUNT`, verified against
 * `chatwoot/chatwoot` `develop`
 * `enterprise/app/models/captain/custom_tool.rb`) with headroom for
 * administrator-created tools on the same account.
 *
 * Every param is `required: true` (see CanonicalToolDefinition) because
 * Chatwoot's own Liquid template rendering runs with `strict_variables:
 * true` (verified against `enterprise/app/models/concerns/toolable.rb`)
 * — a param the LLM did not supply is genuinely undefined in the request
 * template's Liquid context, not merely blank, and referencing an
 * undefined Liquid variable raises. Requiring every field sidesteps that
 * entirely; the field description tells the LLM to pass an empty string
 * for anything that does not apply.
 *
 * The conversation tuple (`chatwootAccountId`/`chatwootContactId`/
 * `chatwootConversationId`) is supplied by the LLM as an ordinary tool
 * parameter, not read from Chatwoot's own trustworthy
 * `X-Chatwoot-Conversation-Id` metadata headers — and that is
 * deliberate, not an oversight: this codebase's security model never
 * trusts a claimed identifier as authorization in the first place (see
 * SECURITY_PRIVACY.md and the standing "a submission ID is never
 * authorization" rule). The tuple is only ever meaningful if it matches
 * an already server-side-bound SupportSession; a wrong or fabricated
 * tuple simply fails to resolve one, exactly like a wrong tuple supplied
 * through any other channel.
 */
final class CanonicalToolCatalog
{
    private const TUPLE_PARAMS = [
        ['name' => 'chatwootAccountId', 'type' => 'string', 'description' => 'The current Chatwoot account ID for this conversation.'],
        ['name' => 'chatwootContactId', 'type' => 'string', 'description' => 'The current Chatwoot contact ID for this conversation.'],
        ['name' => 'chatwootConversationId', 'type' => 'string', 'description' => 'The current Chatwoot conversation ID.'],
    ];

    /** @return CanonicalToolDefinition[] */
    public static function all(): array
    {
        return [
            new CanonicalToolDefinition(
                'ojs_request_verification',
                'Request OJS Verification',
                'Starts identity verification (PIN or secure link) for the email the visitor claims is their OJS account. Always returns a generic "verification requested" response regardless of whether the email exists — never report account existence back to the visitor.',
                'verificationRequest',
                [...self::TUPLE_PARAMS,
                    ['name' => 'email', 'type' => 'string', 'description' => 'The email address the visitor claims.'],
                    ['name' => 'purpose', 'type' => 'string', 'description' => '"account_support" or "submission_support".'],
                    ['name' => 'method', 'type' => 'string', 'description' => '"pin" or "link". Pass "pin" if unsure.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_confirm_verification',
                'Confirm OJS Verification',
                'Confirms a PIN the visitor received by email against a previously requested verification challenge.',
                'verificationConfirm',
                [...self::TUPLE_PARAMS,
                    ['name' => 'challenge', 'type' => 'string', 'description' => 'The opaque challenge reference from the verification request response.'],
                    ['name' => 'purpose', 'type' => 'string', 'description' => 'The same purpose used in the verification request.'],
                    ['name' => 'pin', 'type' => 'string', 'description' => 'The PIN the visitor received by email.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_support_identity',
                'Get OJS Support Identity',
                "Returns the caller's current verification/assurance status for this conversation. Call this first before answering any account- or submission-specific question.",
                'identity',
                self::TUPLE_PARAMS
            ),
            new CanonicalToolDefinition(
                'ojs_list_my_submissions',
                'List My OJS Submissions',
                "Lists the verified caller's own submissions (author or reviewer relationship only).",
                'submissions',
                [...self::TUPLE_PARAMS,
                    ['name' => 'limit', 'type' => 'number', 'description' => 'Maximum results (1-50). Pass 20 if unsure.'],
                    ['name' => 'offset', 'type' => 'number', 'description' => 'Pagination offset. Pass 0 if unsure.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_submission_support',
                'Get OJS Submission Support Status',
                'Returns normalized workflow status, title, and available actions for one submission the verified caller is related to.',
                'submissionSupport',
                [...self::TUPLE_PARAMS,
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The OJS submission ID.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_required_actions',
                'Get OJS Required Actions',
                'Returns what, if anything, the verified caller still needs to do for one submission.',
                'requiredActions',
                [...self::TUPLE_PARAMS,
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The OJS submission ID.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_payment_status',
                'Get OJS Payment Status',
                'Returns public fee facts and, when the caller is verified as the author, whether the fee has been paid.',
                'paymentStatus',
                [...self::TUPLE_PARAMS,
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The OJS submission ID.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_publication_status',
                'Get OJS Publication Status',
                'Returns publication/DOI/issue status for one submission the verified caller is related to.',
                'publicationStatus',
                [...self::TUPLE_PARAMS,
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The OJS submission ID.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_diagnose_account',
                'Diagnose OJS Account Issue',
                "Runs a deterministic diagnostic on the verified caller's own account (never another account).",
                'accountDiagnostics',
                [...self::TUPLE_PARAMS,
                    ['name' => 'scope', 'type' => 'string', 'description' => 'One of: account_access, login, password_reset, profile.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_diagnose_submission',
                'Diagnose OJS Submission Issue',
                'Runs a deterministic diagnostic on one submission the verified caller is related to.',
                'submissionDiagnostics',
                [...self::TUPLE_PARAMS,
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The OJS submission ID.'],
                    ['name' => 'scope', 'type' => 'string', 'description' => 'One of: submission_access, submission_progress, required_action, review_access, publication, payment.'],
                ]
            ),
            new CanonicalToolDefinition(
                'ojs_get_available_actions',
                'Get OJS Available Actions',
                "Returns the safe actions the verified caller's current assurance level allows right now.",
                'actions',
                self::TUPLE_PARAMS
            ),
            new CanonicalToolDefinition(
                'ojs_escalate_support',
                'Escalate to Human Support',
                'Hands the conversation off to a human by recording a structured summary on the OJS side. Use when the caller needs something no other tool can resolve.',
                'escalate',
                [...self::TUPLE_PARAMS,
                    ['name' => 'reason', 'type' => 'string', 'description' => 'A short, specific summary of why human help is needed (max 1000 characters).'],
                    ['name' => 'submissionId', 'type' => 'number', 'description' => 'The related OJS submission ID, or 0 if none.'],
                    ['name' => 'idempotencyKey', 'type' => 'string', 'description' => 'A stable key so retrying this call never creates a duplicate escalation.'],
                ]
            ),
        ];
    }
}
