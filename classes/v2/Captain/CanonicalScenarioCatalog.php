<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Captain;

/**
 * The five recommended Captain scenario families already specified in
 * docs/v2/KNOWLEDGE_DIAGNOSTICS.md §7 — narrows which tools/instructions
 * are available per conversation type, but a Scenario is never
 * authorization: every tool call still goes through the full
 * Identity -> Relationship -> Capability -> Serializer pipeline
 * server-side, regardless of which scenario Captain believes it is
 * running.
 *
 * "Journal Information" deliberately references zero tools — it answers
 * purely from Knowledge Compiler-grounded Documents/FAQ content, per the
 * existing spec ("Uses knowledge/FAQ lookup only when possible").
 */
final class CanonicalScenarioCatalog
{
    private const SECURITY_REMINDER = 'Never claim or guess a user\'s identity, submission, payment, or account state — only report what a tool call actually returns. If a tool reports unverified/unknown, say so plainly and offer verification or escalation; do not fill the gap with a plausible-sounding guess.';

    /** @return CanonicalScenarioDefinition[] */
    public static function all(): array
    {
        return [
            new CanonicalScenarioDefinition(
                'journal_information',
                'Journal Information',
                'Answers general questions about the journal (scope, submission guidelines, review policy, fees, licensing) using only the journal\'s own published Knowledge/Documents — never a live account or submission lookup.',
                "Answer questions about this journal (what it publishes, submission guidelines, review process, fees, licensing, policies) using only the journal's own Knowledge Documents. Do not attempt to look up any specific person's account, submission, or payment in this scenario — hand off to a more specific scenario or escalate if the visitor needs that. " . self::SECURITY_REMINDER,
                []
            ),
            new CanonicalScenarioDefinition(
                'account_support',
                'Account Support',
                'Helps a visitor verify their OJS account and diagnose account/login/password-reset problems.',
                'Help the visitor verify their OJS identity and resolve account-related issues (login trouble, password reset, registration). Start with {{tool:ojs_get_support_identity}} to check current verification status. If unverified, use {{tool:ojs_request_verification}} and {{tool:ojs_confirm_verification}} to verify them by email before discussing any account-specific detail. Once verified, use {{tool:ojs_diagnose_account}} for a deterministic diagnosis rather than guessing the cause. ' . self::SECURITY_REMINDER,
                ['ojs_get_support_identity', 'ojs_request_verification', 'ojs_confirm_verification', 'ojs_diagnose_account']
            ),
            new CanonicalScenarioDefinition(
                'submission_support',
                'Submission Support',
                'Helps a verified author or reviewer check the status, required actions, or diagnosis for one of their own submissions.',
                'Help the visitor with questions about their own submission(s) — status, required next steps, or why something is stuck. Start with {{tool:ojs_get_support_identity}}; if unverified, this scenario cannot proceed to submission-specific detail. Use {{tool:ojs_list_my_submissions}} to help them find the right submission, {{tool:ojs_get_submission_support}} for its current status, {{tool:ojs_get_required_actions}} for outstanding steps, {{tool:ojs_diagnose_submission}} for a deterministic diagnosis, and {{tool:ojs_get_available_actions}} to know what is safe to offer. ' . self::SECURITY_REMINDER,
                ['ojs_get_support_identity', 'ojs_list_my_submissions', 'ojs_get_submission_support', 'ojs_get_required_actions', 'ojs_diagnose_submission', 'ojs_get_available_actions']
            ),
            new CanonicalScenarioDefinition(
                'payment_publication_support',
                'Payment & Publication Support',
                'Helps a verified author check their submission fee/payment status and publication/DOI status.',
                'Help the visitor with questions about a submission\'s payment/fee status or its publication/DOI status. Start with {{tool:ojs_get_support_identity}}; payment and publication detail for a specific submission is only available once verified as the author. Use {{tool:ojs_get_payment_status}} for fee/payment questions and {{tool:ojs_get_publication_status}} for publication/DOI questions. ' . self::SECURITY_REMINDER,
                ['ojs_get_support_identity', 'ojs_get_payment_status', 'ojs_get_publication_status']
            ),
            new CanonicalScenarioDefinition(
                'human_escalation',
                'Human Escalation',
                'Hands the conversation to a human by recording a structured summary, for anything no other scenario/tool can resolve.',
                'When the visitor needs something no other scenario or tool can resolve (a judgment call, a dispute, or a request outside what this assistant can safely automate), use {{tool:ojs_escalate_support}} to record a clear, specific summary of what the visitor needs and why. Do not attempt to resolve the underlying issue yourself once escalating. ' . self::SECURITY_REMINDER,
                ['ojs_escalate_support']
            ),
        ];
    }
}
