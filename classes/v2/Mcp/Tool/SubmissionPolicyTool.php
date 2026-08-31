<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;

/**
 * MCP-003: `journal.get_submission_policy`. Same reuse pattern as
 * JournalProfileTool — the real Knowledge Compiler `submission.*` facts
 * (author guidelines, submission checklist, required file genres,
 * sections), never a second parallel path.
 */
final class SubmissionPolicyTool
{
    public const NAME = 'journal.get_submission_policy';
    public const DESCRIPTION = "This journal's public submission policy: author guidelines, submission checklist, sections, and required file guidance.";

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
        foreach ($compilation->factsWithKeyPrefix('submission.') as $fact) {
            $policy[$fact->key()] = $fact->value();
        }
        return $policy;
    }
}
