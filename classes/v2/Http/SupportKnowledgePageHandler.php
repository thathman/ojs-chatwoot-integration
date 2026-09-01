<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\handler\Handler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

/**
 * Public, unauthenticated GET routes for generated journal knowledge
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §4). Deliberately a separate page from
 * `ojsSupportGateway`: that page is Bearer-authenticated/CSRF-protected
 * service plumbing for Chatwoot Captain and the browser bootstrap; this
 * one serves plain HTML documents to anonymous visitors/crawlers/Captain
 * Documents and must never require a SupportSession or any capability
 * check to render.
 *
 * Extends the plain app `Handler`, not `PKP\controllers\page\PageHandler`:
 * that class's own `authorize()` only grants anonymous access to its two
 * built-in ops (`tasks`/`css`), so every real public knowledge op here
 * fell through to a login redirect instead of rendering — the opposite of
 * "must never require... any capability check to render" (confirmed live
 * on ojs-demo.airixmedia.com; see TST-014). Matches the same real,
 * proven-working pattern as `contributorUserSync`'s
 * `ContributorApprovalHandler` on this same box.
 */
final class SupportKnowledgePageHandler extends Handler
{
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function index($args, $request): void
    {
        $this->plugin->supportKnowledgeIndexRequest($request);
    }

    public function about($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'about');
    }

    public function submissions($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'submissions');
    }

    public function review($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'review');
    }

    public function fees($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'fees');
    }

    public function publication($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'publication');
    }

    public function pages($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'pages');
    }

    public function accounts($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'accounts');
    }

    /**
     * `/support-knowledge/sitemap` — deliberately not `sitemap.xml`: PKP's
     * page/operation routing dispatches the URL's operation segment
     * directly to a same-named PHP method, and `.` is not a legal PHP
     * method-name character, so a literal `sitemap.xml` URL segment
     * cannot map to any handler method. The `Content-Type: application/xml`
     * response header (see ChatwootIntegrationV2Plugin::supportKnowledgeSitemapRequest())
     * is what actually matters to a sitemap consumer — the URL itself is
     * not required to end in `.xml`.
     */
    public function sitemap($args, $request): void
    {
        $this->plugin->supportKnowledgeSitemapRequest($request);
    }

    public function policies($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'policies');
    }
}
