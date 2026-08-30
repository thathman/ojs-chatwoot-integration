<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

/**
 * Official, journal-manager-authored public pages — the one OJS-managed
 * "explicitly made public" page surface this codebase reads
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md, the official-page-provider freeze
 * directive). Deliberately narrow: this is OJS core's own Static Pages
 * plugin, never a crawl of arbitrary URLs on the journal's domain — a
 * page a journal manager did not create through this plugin never
 * becomes a KnowledgeFact.
 *
 * Ranked lowest among structured sources in `KnowledgeSourcePrecedence`:
 * a stale static page that disagrees with current structured OJS/provider
 * configuration loses the conflict, recorded but never rendered.
 */
final class OfficialPageKnowledgeProvider implements KnowledgeProviderInterface
{
    public function __construct(private OjsCompatibilityAdapterInterface $adapter)
    {
    }

    public function providerId(): string
    {
        return 'core.official_pages';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context)) {
            return [];
        }

        try {
            $pages = $this->adapter->getOfficialPublicPages($context, $locale);
        } catch (\Throwable $e) {
            return [];
        }

        $facts = [];
        foreach ($pages as $page) {
            $path = trim((string) ($page['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $title = KnowledgeSanitizer::sanitize((string) ($page['title'] ?? ''));
            $content = KnowledgeSanitizer::sanitize((string) ($page['content'] ?? ''));
            $body = trim($title !== '' ? "<h3>{$title}</h3>{$content}" : $content);
            if ($body === '') {
                continue;
            }

            $facts[] = new KnowledgeFact(
                'officialPage.' . $path,
                $body,
                KnowledgeClassification::PUBLIC,
                KnowledgeSourcePrecedence::SOURCE_OFFICIAL_PAGE,
                $locale,
                $this->providerId(),
                $path
            );
        }

        return $facts;
    }
}
