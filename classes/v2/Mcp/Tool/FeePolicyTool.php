<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;

/**
 * MCP-003: `journal.get_fee_policy`. Same reuse pattern as
 * JournalProfileTool — the real Knowledge Compiler `fee.*` facts
 * (`CorePaymentKnowledgeProvider`'s publication/submission fee
 * enabled/amount/currency), which are already journal-level public
 * policy, never a specific submission's paid/unpaid obligation (that
 * stays a protected, identity-scoped fact — see `payment.get_submission_status`,
 * a later slice).
 */
final class FeePolicyTool
{
    public const NAME = 'journal.get_fee_policy';
    public const DESCRIPTION = "This journal's public fee policy: whether submission/publication fees are enabled, and their amount/currency when so.";

    /** @return array<string,mixed> */
    public static function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    /** @return array<string,string> */
    public static function handle(?KnowledgeCompilation $compilation): array
    {
        if ($compilation === null) {
            return [];
        }

        $policy = [];
        foreach ($compilation->factsWithKeyPrefix('fee.') as $fact) {
            $policy[$fact->key()] = $fact->value();
        }
        return $policy;
    }
}
