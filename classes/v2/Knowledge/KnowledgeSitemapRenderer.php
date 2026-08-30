<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Renders `/support-knowledge/sitemap.xml`. Only ever fed URLs drawn from
 * `KnowledgeRouteCatalog` plus the root — never a Support API route,
 * verification link, admin route, or submission URL, since those simply
 * never pass through this class.
 */
final class KnowledgeSitemapRenderer
{
    /** @param string[] $urls absolute URLs, root first, in stable order */
    public static function render(array $urls): string
    {
        $items = '';
        foreach ($urls as $url) {
            $escaped = htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $items .= "  <url><loc>{$escaped}</loc></url>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            . $items
            . "</urlset>\n";
    }
}
