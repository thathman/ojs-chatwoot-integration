<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool;

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompilation;

/**
 * MCP-003's first safe tool: `journal.get_profile`. Reuses the exact same
 * already-vetted Knowledge Compiler output the public `/support-knowledge/`
 * REST pages serve — never a second, parallel path to journal facts.
 * Requires no identity/relationship/capability beyond the base MCP public
 * consumer floor (McpPublicConsumer), since every fact here is already
 * `KnowledgeClassification::PUBLIC` by construction.
 *
 * Returns a flat key => value map only — never a KnowledgeFact object,
 * provenance metadata, or any other internal shape (MCP responses must
 * never carry a raw OJS/internal object).
 */
final class JournalProfileTool
{
    public const NAME = 'journal.get_profile';
    public const DESCRIPTION = "This journal's public profile: name, description, contact, ISSN, languages, review model, and related public facts.";

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

        $profile = [];
        foreach ($compilation->factsWithKeyPrefix('journal.') as $fact) {
            $profile[$fact->key()] = $fact->value();
        }
        return $profile;
    }
}
