<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\controllers\page\PageHandler;

/**
 * The stateless Streamable HTTP MCP endpoint (ADR-023). A single POST
 * route — MCP method routing happens inside the request body, not the URL
 * path, unlike the REST Support API's per-operation pages.
 */
final class McpGatewayPageHandler extends PageHandler
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
