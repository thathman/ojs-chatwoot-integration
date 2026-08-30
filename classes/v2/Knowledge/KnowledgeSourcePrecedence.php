<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Deterministic precedence when two facts collide on the same (locale, key)
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md, the conflict-handling freeze
 * directive):
 *
 *   1. structured live OJS configuration
 *   2. explicitly verified structured third-party provider
 *   3. official OJS-managed public page
 *   4. approved FAQ/support content
 *
 * A source not in this table ranks last (worst), never silently wins —
 * an unrecognized source string is a bug to fix, not a reason to let
 * unverified content override structured configuration.
 */
final class KnowledgeSourcePrecedence
{
    public const SOURCE_OFFICIAL_PAGE = 'ojs.static_page';
    public const SOURCE_FAQ = 'faq';

    private const RANK = [
        // Tier 1: structured live OJS configuration.
        'ojs.context' => 0,
        'ojs.payment_manager' => 0,
        'ojs.dispatcher' => 0,
        'ojs.section_repository' => 0,
        // Tier 2: explicitly verified structured third-party provider.
        'airix.submission_fee_policy' => 1,
        // Tier 3: official OJS-managed public page.
        self::SOURCE_OFFICIAL_PAGE => 2,
        // Tier 4: approved FAQ/support content.
        self::SOURCE_FAQ => 3,
    ];

    private const UNKNOWN_RANK = 99;

    public static function rank(string $source): int
    {
        return self::RANK[$source] ?? self::UNKNOWN_RANK;
    }

    private function __construct()
    {
    }
}
