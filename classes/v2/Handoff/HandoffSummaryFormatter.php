<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Handoff;

use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

/**
 * ojs_escalate_support (docs/v2/API_MCP_SPEC.md §7.12). Builds the single
 * safe structured handoff summary — one source of truth for both the JSON
 * response and the Chatwoot private-note text, so the two representations
 * can never drift apart on what is/isn't safe to include.
 *
 * Deliberately excludes (HOF-006): reviewer identities, review
 * recommendations/comments, internal editorial discussion, any raw
 * DAO/OJS object, and anything not already proven safe by an existing
 * dedicated endpoint's own allowlist logic — this class does not invent
 * new facts, it only arranges facts the rest of v2 already computed.
 */
final class HandoffSummaryFormatter
{
    private const MAX_REASON_LENGTH = 1000;

    /**
     * @param array<string,mixed> $identitySummary Exactly what SupportIdentitySerializer::serialize() returns (HOF-002: verification method/expiry already included there).
     * @param array{status:string,doi:?string}|null $publicationFacts
     * @param array{feeEnabled:bool,status:?string}|null $paymentFacts
     * @param DiagnosticResult|null $diagnostic HOF-005: internally re-derived from the same already-verified $requiredActions, never a caller-supplied diagnostic — Captain could claim any code, so it is never trusted as input here.
     *
     * @return array<string,mixed>
     */
    public static function build(
        array $identitySummary,
        ?ResourceRelationship $relationship,
        ?string $supportState,
        array $requiredActions,
        ?array $publicationFacts,
        ?array $paymentFacts,
        string $reason,
        ?DiagnosticResult $diagnostic = null
    ): array {
        $summary = [
            'identity' => $identitySummary,
            'reason' => self::sanitizeReason($reason),
        ];

        if ($relationship && !$relationship->isEmpty()) {
            $summary['resource'] = [
                'type' => $relationship->resourceType(),
                'id' => $relationship->resourceId(),
                'relationships' => $relationship->types(),
            ];
        }

        if ($supportState !== null) {
            $summary['supportState'] = $supportState;
        }

        if ($requiredActions !== []) {
            $summary['requiredActions'] = $requiredActions;
        }

        if ($publicationFacts !== null) {
            $summary['publication'] = $publicationFacts;
        }

        if ($paymentFacts !== null) {
            $summary['payment'] = $paymentFacts;
        }

        if ($diagnostic !== null) {
            $summary['diagnostic'] = [
                'status' => $diagnostic->status(),
                'code' => $diagnostic->code(),
                'summary' => $diagnostic->summary(),
            ];
        }

        return $summary;
    }

    /**
     * Renders the exact same summary into plain text for the Chatwoot
     * private note. Chatwoot notes are markdown-rendered and staff-only —
     * the reason text is still capped/stripped of control characters (see
     * sanitizeReason()) as a baseline hygiene measure, not because staff
     * visibility is treated as untrusted.
     */
    public static function renderNoteText(array $summary): string
    {
        $lines = ['**Support Gateway Handoff**'];

        $identity = $summary['identity'] ?? [];
        $verified = $identity['verified'] ?? false;
        $assurance = $identity['assurance'] ?? 'v0';
        $lines[] = '- Verified: ' . ($verified ? 'yes' : 'no') . " (assurance: {$assurance})";

        $roles = $identity['identity']['roles'] ?? [];
        if (is_array($roles) && $roles !== []) {
            $lines[] = '- Roles: ' . implode(', ', $roles);
        }

        if (isset($summary['resource'])) {
            $resource = $summary['resource'];
            $relationships = is_array($resource['relationships'] ?? null) ? implode(', ', $resource['relationships']) : '';
            $lines[] = "- Resource: {$resource['type']} #{$resource['id']} ({$relationships})";
        }

        if (isset($summary['supportState'])) {
            $lines[] = "- Support state: {$summary['supportState']}";
        }

        if (isset($summary['requiredActions']) && is_array($summary['requiredActions']) && $summary['requiredActions'] !== []) {
            $lines[] = '- Required actions: ' . implode(', ', $summary['requiredActions']);
        }

        if (isset($summary['publication'])) {
            $lines[] = "- Publication status: {$summary['publication']['status']}";
        }

        if (isset($summary['payment'])) {
            $paymentStatus = $summary['payment']['status'] ?? 'unavailable';
            $lines[] = "- Payment status: {$paymentStatus}";
        }

        if (isset($summary['diagnostic']['summary'])) {
            $lines[] = "- Diagnostic: {$summary['diagnostic']['summary']}";
        }

        $lines[] = '';
        $lines[] = '**Reason:**';
        $lines[] = (string) ($summary['reason'] ?? '');

        return implode("\n", $lines);
    }

    /**
     * Caller-supplied free text describing why a human is needed — the one
     * field in this DTO that isn't server-derived, and the one place an
     * attacker (a malicious author, or a prompt-injected Captain simply
     * relaying what one told it) has any influence over this note's text
     * (SEC-006). Capped, stripped of control characters, and — critically —
     * collapsed to a single line: without this, an embedded newline could
     * inject a fake `- Field: value` line or even a fake second
     * `**Support Gateway Handoff**` header into the rendered note,
     * spoofing structured facts that were never actually verified, to
     * either the human agent reading it or an LLM later asked to summarize
     * it. Collapsing newlines removes that entire injection class while
     * keeping the reason's actual content fully readable.
     */
    private static function sanitizeReason(string $reason): string
    {
        $reason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $reason) ?? '';
        $reason = preg_replace('/[\r\n\t]+/', ' ', $reason) ?? '';
        $reason = trim($reason);
        if (function_exists('mb_substr')) {
            return mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        }
        return substr($reason, 0, self::MAX_REASON_LENGTH);
    }
}
