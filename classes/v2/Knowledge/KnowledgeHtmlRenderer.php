<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

/**
 * Renders a KnowledgeCompilation into a plain, crawlable public HTML
 * document (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §4). No Support API envelope:
 * these are documents for humans/crawlers/Captain Documents, not
 * Bearer-authenticated service responses.
 *
 * Every fact value is re-sanitized here (idempotent on already-sanitized
 * HTML facts, harmless on plain-text facts) — defense in depth, not a
 * substitute for KnowledgeSanitizer running inside the provider.
 */
final class KnowledgeHtmlRenderer
{
    /** @param array<string,string> $navLinks label => url */
    public static function renderIndex(string $journalName, array $navLinks): string
    {
        $links = self::renderNav($navLinks);
        $escapedName = self::esc($journalName);
        $title = $escapedName . ' Support Knowledge';

        return <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>{$title}</title></head>
<body>
<h1>{$title}</h1>
<p>Generated support knowledge for {$escapedName}.</p>
<nav><ul>
{$links}
</ul></nav>
</body></html>
HTML;
    }

    /**
     * @param KnowledgeFact[] $facts
     * @param array<string,string> $navLinks label => url
     */
    public static function renderCategory(string $journalName, string $categoryTitle, array $facts, array $navLinks, string $locale): string
    {
        $title = self::esc($journalName) . ' — ' . self::esc($categoryTitle);
        $body = '';
        foreach ($facts as $fact) {
            $label = self::humanizeKey($fact->key());
            $value = KnowledgeSanitizer::sanitize($fact->value());
            $body .= "<section><h2>{$label}</h2><div>{$value}</div></section>\n";
        }
        if ($body === '') {
            $body = '<p>No public information is currently available for this category.</p>';
        }
        $links = self::renderNav($navLinks);

        return <<<HTML
<!doctype html>
<html lang="{$locale}"><head><meta charset="utf-8"><title>{$title}</title></head>
<body>
<h1>{$title}</h1>
{$body}
<nav><ul>
{$links}
</ul></nav>
</body></html>
HTML;
    }

    /** @param array<string,string> $navLinks */
    private static function renderNav(array $navLinks): string
    {
        $items = [];
        foreach ($navLinks as $label => $url) {
            $items[] = '<li><a href="' . self::esc($url) . '">' . self::esc($label) . '</a></li>';
        }
        return implode("\n", $items);
    }

    private static function humanizeKey(string $key): string
    {
        $segment = str_contains($key, '.') ? substr($key, strrpos($key, '.') + 1) : $key;
        $spaced = trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $segment));
        return self::esc(ucfirst($spaced));
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
