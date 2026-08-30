<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\plugins\generic\chatwootIntegration\classes\v2\Api\CorrelationId;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiResponse;
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
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->csrfValid($request)) {
            return new JSONMessage(false, ['error' => 'binding_failed']);
        }

        return $this->plugin->bindSupportSessionRequest($request);
    }

    /**
     * Server-to-server endpoints for Chatwoot Captain: cheap verification
     * probe, sanitized identity, and capability-derived actions. Not
     * same-origin, so they are never CSRF-checked; they are
     * service-authenticated instead, and emit the Support API JSON envelope
     * (see SupportApiResponse) rather than PKP's JSONMessage.
     */
    public function status($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportStatusRequest($request);
    }

    public function identity($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportIdentityRequest($request);
    }

    public function actions($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportActionsRequest($request);
    }

    /**
     * Conceptually /ojsSupportGateway/submission/verify in docs/v2/API_MCP_SPEC.md;
     * implemented as a single operation segment (submissionVerify) to match
     * every other Support API route's flat PageHandler-operation style
     * rather than introducing untested nested-args routing.
     */
    public function submissionVerify($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportSubmissionVerifyRequest($request);
    }

    public function submissions($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportSubmissionListRequest($request);
    }

    private function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'This endpoint only accepts POST.',
                CorrelationId::fromRequestOrGenerate(),
                405
            );
        }
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
