<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\handler\Handler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;

/**
 * The stateless Streamable HTTP MCP endpoint (ADR-023). A single POST
 * route — MCP method routing happens inside the request body, not the URL
 * path, unlike the REST Support API's per-operation pages.
 *
 * Extends the plain app `Handler`, not `PKP\controllers\page\PageHandler`:
 * that class's own `authorize()` only grants anonymous access to its two
 * built-in ops (`tasks`/`css`), so every other op — including every real
 * op this plugin defines — fell through to a login redirect (confirmed
 * live on ojs-demo.airixmedia.com; see TST-014). This endpoint enforces
 * its own auth (Bearer service token / MCP protocol) inside each method,
 * the same real pattern already proven on this box by
 * `contributorUserSync`'s `ContributorApprovalHandler`.
 */
final class McpGatewayPageHandler extends Handler
{
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function index($args, $request): void
    {
        $this->plugin->mcpRequest($request);
    }
}
