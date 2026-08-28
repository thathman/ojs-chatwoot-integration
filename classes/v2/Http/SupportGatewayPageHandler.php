<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\controllers\page\PageHandler;
use PKP\core\JSONMessage;

/**
 * Same-origin OJS endpoint used only to bind the ephemeral browser bootstrap
 * to the server-verified Chatwoot conversation.
 */
final class SupportGatewayPageHandler extends PageHandler
{
    public function __construct(private ChatwootIntegrationV2Plugin $plugin)
    {
        parent::__construct();
    }

    public function bind($args, $request): JSONMessage
    {
        if (!$this->csrfValid($request)) {
            return new JSONMessage(false, ['error' => 'binding_failed']);
        }

        return $this->plugin->bindSupportSessionRequest($request);
    }

    private function csrfValid($request): bool
    {
        if (!is_object($request) || !method_exists($request, 'getSession')) {
            return false;
        }

        try {
            $session = $request->getSession();
            $expected = is_object($session) && method_exists($session, 'token')
                ? (string) $session->token()
                : '';
        } catch (\Throwable $e) {
            return false;
        }

        $provided = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }
}
