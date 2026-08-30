<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\controllers\page\PageHandler;

/**
 * Public, unauthenticated GET routes for generated journal knowledge
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §4). Deliberately a separate page from
 * `ojsSupportGateway`: that page is Bearer-authenticated/CSRF-protected
 * service plumbing for Chatwoot Captain and the browser bootstrap; this
 * one serves plain HTML documents to anonymous visitors/crawlers/Captain
 * Documents and must never require a SupportSession or any capability
 * check to render.
 */
final class SupportKnowledgePageHandler extends PageHandler
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

    public function policies($args, $request): void
    {
        $this->plugin->supportKnowledgeCategoryRequest($request, 'policies');
    }
}
