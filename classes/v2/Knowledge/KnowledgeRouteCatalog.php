<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * The single source of truth for generated knowledge category routes and
 * their corresponding KnowledgeFact key prefix. Both the root page's
 * navigation and the sitemap consume this list — never a second
 * independently-maintained list — so a new category can never appear in
 * one but not the other.
 */
final class KnowledgeRouteCatalog
{
    /** category slug (URL segment) => KnowledgeFact key prefix. */
    public const CATEGORIES = [
        'about' => 'journal.',
        'submissions' => 'submission.',
        'review' => 'review.',
        'fees' => 'fee.',
        'publication' => 'publication.',
        'pages' => 'officialPage.',
        'accounts' => 'accounts.',
        'policies' => 'policy.',
    ];

    /** @return string[] category slugs, in stable display/sitemap order */
    public static function categories(): array
    {
        return array_keys(self::CATEGORIES);
    }

    public static function keyPrefixFor(string $category): ?string
    {
        return self::CATEGORIES[$category] ?? null;
    }

    private function __construct()
    {
    }
}
