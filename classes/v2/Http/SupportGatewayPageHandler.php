<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Http;

use APP\handler\Handler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\CorrelationId;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiResponse;
use APP\plugins\generic\chatwootIntegration\classes\v2\Plugin\ChatwootIntegrationV2Plugin;
use PKP\core\JSONMessage;

/**
 * Same-origin OJS endpoint used only to bind the ephemeral browser bootstrap
 * to the server-verified Chatwoot conversation.
 *
 * Extends the plain app `Handler`, not `PKP\controllers\page\PageHandler`:
 * that class's own `authorize()` only grants anonymous access to its two
 * built-in ops (`tasks`/`css`), so every real op here — including the
 * deliberately-anonymous-capable ones — fell through to a login redirect
 * instead of running this endpoint's own real auth (Bearer service token,
 * CSRF, and the verified-conversation-tuple checks already inside each
 * method) (confirmed live on ojs-demo.airixmedia.com; see TST-014).
 * Matches the same real, proven-working pattern as `contributorUserSync`'s
 * `ContributorApprovalHandler` on this same box.
 */
final class SupportGatewayPageHandler extends Handler
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

    /**
     * Conceptually ojs_get_submission_support in docs/v2/API_MCP_SPEC.md
     * §7.5; implemented as a single operation segment (submissionSupport)
     * for the same reason submissionVerify is, above.
     */
    public function submissionSupport($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportSubmissionSupportRequest($request);
    }

    /**
     * Conceptually ojs_get_required_actions in docs/v2/API_MCP_SPEC.md
     * §7.6; implemented as a single operation segment (requiredActions) for
     * the same reason submissionVerify/submissionSupport are, above.
     */
    public function requiredActions($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportRequiredActionsRequest($request);
    }

    /**
     * Conceptually ojs_get_publication_status in docs/v2/API_MCP_SPEC.md
     * §7.8; implemented as a single operation segment (publicationStatus)
     * for the same reason submissionVerify/submissionSupport/requiredActions
     * are, above.
     */
    public function publicationStatus($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportPublicationStatusRequest($request);
    }

    /**
     * Conceptually ojs_get_payment_status in docs/v2/API_MCP_SPEC.md §7.7;
     * implemented as a single operation segment (paymentStatus) for the
     * same reason the other submission-scoped operations are, above.
     */
    public function paymentStatus($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportPaymentStatusRequest($request);
    }

    /**
     * Conceptually ojs_diagnose_account in docs/v2/API_MCP_SPEC.md §7.9;
     * implemented as a single operation segment (accountDiagnostics) for
     * the same reason the other Support API operations are, above.
     */
    public function accountDiagnostics($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportAccountDiagnosticsRequest($request);
    }

    /**
     * Conceptually ojs_diagnose_submission in docs/v2/API_MCP_SPEC.md
     * §7.10; implemented as a single operation segment
     * (submissionDiagnostics) for the same reason the other
     * submission-scoped operations are, above.
     */
    public function submissionDiagnostics($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportSubmissionDiagnosticsRequest($request);
    }

    /**
     * Conceptually ojs_escalate_support in docs/v2/API_MCP_SPEC.md §7.12;
     * implemented as a single operation segment (escalate) for the same
     * reason the other Support API operations are, above.
     */
    public function escalate($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportEscalateRequest($request);
    }

    /**
     * Conceptually ojs_request_verification in docs/v2/API_MCP_SPEC.md
     * §7.1; implemented as a single operation segment
     * (verificationRequest) for the same reason the other Support API
     * operations are, above.
     */
    public function verificationRequest($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportVerificationRequestRequest($request);
    }

    /**
     * Conceptually ojs_confirm_verification (PIN path) in
     * docs/v2/API_MCP_SPEC.md §7.2; implemented as a single operation
     * segment (verificationConfirm) for the same reason the other
     * Support API operations are, above.
     */
    public function verificationConfirm($args, $request): void
    {
        $this->requirePost();
        $this->plugin->supportVerificationConfirmRequest($request);
    }

    /**
     * Browser-facing GET counterpart of verificationConfirm for the
     * secure-link path — deliberately not POST-only and not part of the
     * service-authenticated pipeline; see
     * ChatwootIntegrationV2Plugin::verifyLinkRequest()'s own docblock.
     */
    public function verify($args, $request): void
    {
        $this->plugin->verifyLinkRequest($request);
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
