<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Dedicated HTML sanitizer for journal-authored settings/pages flowing into
 * generated public knowledge (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §4). OJS
 * settings such as `authorGuidelines`/`reviewGuidelines`/`copyrightNotice`
 * are rich-text HTML editable by journal managers — this is the one and
 * only path that content is allowed to take into anything a support
 * channel or Captain document could surface, so it gets real dedicated
 * code rather than an ad-hoc `strip_tags()` at each call site.
 *
 * Deliberately regex-based rather than a DOMDocument/HTML Purifier
 * dependency: the input here is journal-configured prose from a small,
 * known set of OJS settings, not arbitrary hostile HTML at scale, and this
 * codebase's standing rule (PR #19) is not to introduce a mandatory
 * extension/library dependency casually. If a future category needs to
 * sanitize genuinely adversarial/large-scale HTML, revisit this decision
 * then.
 */
final class KnowledgeSanitizer
{
    private const DANGEROUS_ELEMENTS = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'applet', 'link', 'meta', 'base', 'noscript'];

    /** The deliberately safe presentation subset permitted through. */
    private const ALLOWED_TAGS = '<p><br><strong><b><em><i><ul><ol><li><a><blockquote><h3><h4><h5><h6><code><pre><span>';

    public static function sanitize(string $html): string
    {
        $clean = $html;

        foreach (self::DANGEROUS_ELEMENTS as $tag) {
            // Paired form first (content included), then any stray/self-closed opening tag.
            $clean = preg_replace('#<' . $tag . '\b[^>]*>.*?</' . $tag . '\s*>#is', '', $clean) ?? $clean;
            $clean = preg_replace('#<' . $tag . '\b[^>]*/?>#is', '', $clean) ?? $clean;
        }

        $clean = strip_tags($clean, self::ALLOWED_TAGS);

        // Event-handler attributes (onclick=, onerror=, ...) on any surviving tag.
        $clean = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;

        // Neutralize javascript:/vbscript:/data: URLs in href/src attributes.
        $clean = preg_replace_callback(
            '/\b(href|src)(\s*=\s*)("[^"]*"|\'[^\']*\')/i',
            static function (array $m): string {
                $quote = $m[3][0];
                $value = trim($m[3], $quote);
                if (preg_match('/^\s*(javascript|vbscript|data)\s*:/i', $value)) {
                    return $m[1] . $m[2] . $quote . '#' . $quote;
                }
                return $m[0];
            },
            $clean
        ) ?? $clean;

        return trim($clean);
    }
}
