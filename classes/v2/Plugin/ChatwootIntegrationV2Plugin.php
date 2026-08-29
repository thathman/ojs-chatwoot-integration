<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Plugin;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\ChatwootApiService;
use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationPlugin;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\CorrelationId;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiResponse;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionVerificationSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\ChatwootConversationVerifier;
use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\LegacyWidgetIdentifierResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ChatwootContextProjector;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\SupportGatewayPageHandler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
use PKP\core\JSONMessage;
use PKP\facades\Locale;
use PKP\plugins\Hook;
use PKP\security\Role;

/**
 * Transitional v2 runtime shell.
 *
 * It deliberately inherits the proven v1 behavior and overrides only seams
 * that have a tested v2 implementation. This keeps migration incremental.
 */
class ChatwootIntegrationV2Plugin extends ChatwootIntegrationPlugin
{
    private const SUPPORT_GATEWAY_PAGE = 'ojsSupportGateway';
    private const LEGACY_EXPORT_KEYS = [
        'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
        'chatwootSupportApiToken',
        'enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests',
        'hideForRole_1','hideForRole_16','hideForRole_17','hideForRole_4097','hideForRole_65536','hideForRole_4096','hideForRole_1048576',
        'enableGlobalDefaults','retryQueueEnabled','maxRetryAttempts','eventSyncMode','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected',
        'eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','lazyLoadTrigger','excludedPages','cspSafeMode','skipBackendPages'
    ];

    private ?RuntimeContextBridge $runtimeContextBridge = null;
    private ?ChatwootContextProjector $contextProjector = null;
    private ?SupportContext $lastSupportContext = null;
    private ?SupportSessionBootstrap $supportSessionBootstrap = null;
    private bool $supportSessionBootstrapAttempted = false;
    private bool $contextProjectionInjected = false;
    private bool $supportSessionHandshakeInjected = false;

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            Hook::add('LoadHandler', [$this, 'setSupportGatewayPageHandler']);
        }
        return $success;
    }

    public function setSupportGatewayPageHandler(string $hookName, array $args): bool
    {
        $page =& $args[0];
        $handler =& $args[3];
        if ($page !== self::SUPPORT_GATEWAY_PAGE) {
            return false;
        }

        $handler = new SupportGatewayPageHandler($this);
        return true;
    }

    /** PKP 3.5 plugin installation hook for the v2 Support Gateway tables. */
    public function getInstallMigration()
    {
        return new InstallSupportGatewayMigration();
    }

    public function addChatwootWidget($hookName, $args)
    {
        try {
            $request = Application::get()->getRequest();
            $this->lastSupportContext = $this->runtimeContextBridge()->resolve(
                $request,
                (string) Locale::getLocale()
            );
            $this->bootstrapAuthenticatedSupportSession();
            $this->injectProjectedContext($args);
            $this->injectSupportSessionHandshake($args, $request);
        } catch (\Throwable $e) {
            $this->lastSupportContext = null;
            $this->supportSessionBootstrap = null;
        }

        return parent::addChatwootWidget($hookName, $args);
    }

    /**
     * Same-origin binding endpoint. All failures are intentionally generic so
     * this route cannot be used to enumerate OJS or Chatwoot identity state.
     */
    public function bindSupportSessionRequest($request): JSONMessage
    {
        try {
            $context = $request->getContext();
            $user = $request->getUser();
            if (!$context || !$user) {
                return $this->bindingFailure();
            }

            $contextId = (int) $context->getId();
            $userId = (int) $user->getId();
            if ($contextId <= 0 || $userId <= 0) {
                return $this->bindingFailure();
            }

            if (!$this->getEnabled($contextId) && !$this->getEnabled()) {
                return $this->bindingFailure();
            }

            $bindingToken = trim((string) $request->getUserVar('bindingToken'));
            $conversationId = $this->v2PositiveInt($request->getUserVar('conversationId'));
            if (
                $conversationId === null
                || strlen($bindingToken) < 32
                || strlen($bindingToken) > 160
                || !preg_match('/^[A-Za-z0-9_-]+$/', $bindingToken)
            ) {
                return $this->bindingFailure();
            }

            $supportContext = $this->runtimeContextBridge()->resolve($request, (string) Locale::getLocale());
            if (
                !$supportContext
                || !$supportContext->isAuthenticated()
                || $supportContext->contextId() !== $contextId
                || $supportContext->userId() !== $userId
            ) {
                return $this->bindingFailure();
            }

            $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
            $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
            $inboxId = (int) $this->v2EffectiveSetting($contextId, 'chatwootInboxId', 0);
            if ($baseUrl === '' || $apiToken === '' || $inboxId <= 0) {
                return $this->bindingFailure();
            }

            $privacy = $this->v2Bool($this->v2EffectiveSetting($contextId, 'enablePrivacyMode', false));
            $maskedReviewer = $privacy && in_array(Role::ROLE_ID_REVIEWER, $supportContext->roleIds(), true);
            $expectedIdentifier = (new LegacyWidgetIdentifierResolver())->resolve($userId, $contextId, $maskedReviewer);
            if ($expectedIdentifier === '') {
                return $this->bindingFailure();
            }

            $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
            $verified = (new ChatwootConversationVerifier($chatwoot, $inboxId))->verify(
                $conversationId,
                $expectedIdentifier
            );
            if (!$verified) {
                return $this->bindingFailure();
            }

            $session = $this->runtimeContextBridge()->bindAuthenticatedSupportSession(
                $bindingToken,
                $contextId,
                $userId,
                (string) $verified->accountId(),
                (string) $verified->contactId(),
                (string) $verified->conversationId()
            );
            if (!$session) {
                return $this->bindingFailure();
            }

            return new JSONMessage(true, [
                'bound' => true,
                'assurance' => $session->assuranceLevel(),
                'expiresAt' => gmdate('c', $session->absoluteExpiresAt()),
            ]);
        } catch (\Throwable $e) {
            return $this->bindingFailure();
        }
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: given a service token
     * and the Chatwoot conversation tuple, returns support-safe verification
     * status and available actions for that conversation. This is the cheap
     * probe; identity()/actions() below return richer, still support-safe
     * detail once a caller already knows a conversation is verified.
     */
    public function supportStatusRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'status');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $bridge = $this->runtimeContextBridge();
        $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $result->assurance(),
            $result->identity()
        ));

        SupportApiResponse::success([
            'verified' => $result->verified(),
            'assurance' => $result->assurance(),
            'availableActions' => $decision ? $bridge->availableActions($decision) : [],
        ], $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: returns the verified
     * support identity in a deliberately sanitized allowlist form (see
     * SupportIdentitySerializer). Never includes email, raw relationship
     * evidence, or any part of the underlying OJS User object.
     */
    public function supportIdentityRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'identity');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        SupportApiResponse::success(SupportIdentitySerializer::serialize($result), $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: capability/action
     * discovery, separated from status so Captain can call this specifically
     * to decide what it may offer, rather than inferring permissions.
     */
    public function supportActionsRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'actions');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $bridge = $this->runtimeContextBridge();
        $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $result->assurance(),
            $result->identity()
        ));

        SupportApiResponse::success([
            'verified' => $result->verified(),
            'assurance' => $result->assurance(),
            'availableActions' => $decision ? $bridge->availableActions($decision) : [],
            'disabledActions' => $decision ? $bridge->disabledActions($decision) : [],
        ], $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: establishes
     * resource-scoped (V3) assurance for exactly one submission. This is
     * deliberately narrow — it verifies relationship and returns the
     * capability-derived actions that unlocks, not manuscript content.
     * `submissionId` is only a lookup hint the server independently
     * confirms; it is never proof of access on its own.
     *
     * V3 is a request-time-only decision, never persisted onto the
     * conversation's support session — verifying submission #456 must not
     * become a blanket claim for submission #982 or any other resource.
     * Every reason a resource fails to verify (nonexistent, wrong journal,
     * no relationship, a guessed ID, or the conversation never reaching V2)
     * collapses into the same generic resourceVerified:false shape.
     */
    public function supportSubmissionVerifyRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'submissionVerify');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $bridge = $this->runtimeContextBridge();
        $submissionId = $this->v2PositiveInt($request->getUserVar('submissionId'));
        if ($submissionId === null) {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'submissionId is required.',
                $result->correlationId(),
                400
            );
        }

        $relationship = null;
        if ($result->verified()) {
            $submission = $bridge->loadSubmission($submissionId);
            if ($submission) {
                $candidate = $bridge->resolveSubmissionRelationship($result->identity(), $submission);
                if ($candidate && !$candidate->isEmpty()) {
                    $relationship = $candidate;
                }
            }
        }

        $resourceAssurance = $relationship ? 'v3' : $result->assurance();
        $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $resourceAssurance,
            $result->identity(),
            $relationship
        ));
        $actions = $decision ? $bridge->availableActions($decision) : [];

        $data = $relationship
            ? SubmissionVerificationSerializer::verified($relationship, 'v3', $actions)
            : SubmissionVerificationSerializer::unverified($result, $actions);

        SupportApiResponse::success($data, $result->correlationId());
    }

    /**
     * Runs the shared Support API pipeline (service auth, rate limit,
     * conversation-tuple parsing, session resolution, live identity reload)
     * so every endpoint above stays a thin wrapper around its own response
     * shape instead of reimplementing this each time.
     */
    private function resolveSupportApiRequest($request, string $endpoint): SupportApiRequestContext|SupportApiFailure
    {
        $correlationId = CorrelationId::fromRequestOrGenerate();

        try {
            $context = $request->getContext();
            $contextId = $context ? (int) $context->getId() : 0;
            if ($contextId <= 0) {
                return new SupportApiFailure(SupportApiErrorCode::VALIDATION_ERROR, 'Journal context could not be resolved.', 400, $correlationId);
            }

            $configuredTokens = (string) $this->v2EffectiveSetting($contextId, 'chatwootSupportApiToken', '');
            $chatwootAccountId = trim((string) $request->getUserVar('chatwootAccountId'));
            $chatwootContactId = trim((string) $request->getUserVar('chatwootContactId'));
            $chatwootConversationId = trim((string) $request->getUserVar('chatwootConversationId'));

            $resolver = new SupportApiRequestResolver($this->runtimeContextBridge());

            return $resolver->resolve(
                $request,
                $correlationId,
                $contextId,
                $configuredTokens,
                $chatwootAccountId,
                $chatwootContactId,
                $chatwootConversationId,
                $endpoint,
                (string) Locale::getLocale()
            );
        } catch (\Throwable $e) {
            return new SupportApiFailure(SupportApiErrorCode::INTERNAL_ERROR, 'The request could not be completed.', 500, $correlationId);
        }
    }

    public function exportSettings($request): JSONMessage
    {
        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        }

        $contextId = (int) $context->getId();
        $settings = [];
        foreach (self::LEGACY_EXPORT_KEYS as $key) {
            $settings[$key] = $this->getSetting($contextId, $key);
        }

        $filtered = ExportPolicy::filter($settings);

        return new JSONMessage(true, [
            'contextId' => $contextId,
            'exportedAt' => date('c'),
            'settings' => $filtered['settings'],
            'redactedKeys' => $filtered['redactedKeys'],
        ]);
    }

    public function getResolvedSupportContext(): ?SupportContext
    {
        return $this->lastSupportContext;
    }

    public function getSupportSessionBootstrap(): ?SupportSessionBootstrap
    {
        return $this->supportSessionBootstrap;
    }

    private function bindingFailure(): JSONMessage
    {
        return new JSONMessage(false, ['error' => 'binding_failed']);
    }

    private function bootstrapAuthenticatedSupportSession(): void
    {
        if ($this->supportSessionBootstrapAttempted) {
            return;
        }
        $this->supportSessionBootstrapAttempted = true;

        if (!$this->lastSupportContext || !$this->lastSupportContext->isAuthenticated()) {
            return;
        }

        if (!$this->supportGatewayUsable($this->lastSupportContext->contextId())) {
            return;
        }

        $this->supportSessionBootstrap = $this->runtimeContextBridge()->bootstrapAuthenticatedSupportSession(
            $this->lastSupportContext
        );
    }

    /**
     * A binding ticket must never be minted/exposed unless the support
     * channel it would bind to is actually usable end-to-end: the widget is
     * enabled, and every setting the binding handshake later depends on
     * (browser identity + server-side conversation verification) is present.
     */
    private function supportGatewayUsable(int $contextId): bool
    {
        if (!$this->getEnabled($contextId) && !$this->getEnabled()) {
            return false;
        }

        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $websiteToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootWebsiteToken', ''));
        $identitySecret = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootIdentityValidationSecret', ''));
        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
        $inboxId = (int) $this->v2EffectiveSetting($contextId, 'chatwootInboxId', 0);

        return $baseUrl !== '' && $websiteToken !== '' && $identitySecret !== '' && $apiToken !== '' && $inboxId > 0;
    }

    private function injectProjectedContext(array $args): void
    {
        if ($this->contextProjectionInjected || !$this->lastSupportContext) {
            return;
        }

        $templateMgr = $args[0] ?? null;
        if (!is_object($templateMgr) || !method_exists($templateMgr, 'addHeader')) {
            return;
        }

        $attributes = $this->contextProjector()->project($this->lastSupportContext);
        $json = json_encode(
            $attributes,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json) || $json === '') {
            return;
        }

        $nonce = $this->v2ResolveScriptNonce($templateMgr, $this->lastSupportContext->contextId());
        if ($nonce === null) {
            return;
        }

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $script = '<script' . $nonceAttr . ' data-ojs-chatwoot-context="v2">' .
            '(function(){' .
            'if(window.__ojsSupportContextV2Installed){return;}' .
            'window.__ojsSupportContextV2Installed=true;' .
            'window.addEventListener("chatwoot:ready",function(){' .
            'if(window.$chatwoot&&typeof window.$chatwoot.setCustomAttributes==="function"){' .
            'window.$chatwoot.setCustomAttributes(' . $json . ');' .
            '}' .
            '});' .
            '})();' .
            '</script>';

        $templateMgr->addHeader('chatwootSupportContextV2Frontend', $script, ['contexts' => ['frontend']]);
        $templateMgr->addHeader('chatwootSupportContextV2Backend', $script, ['contexts' => ['backend']]);
        $this->contextProjectionInjected = true;
    }

    private function injectSupportSessionHandshake(array $args, $request): void
    {
        if (
            $this->supportSessionHandshakeInjected
            || !$this->supportSessionBootstrap
            || !$this->lastSupportContext
            || !$this->lastSupportContext->isAuthenticated()
        ) {
            return;
        }

        $templateMgr = $args[0] ?? null;
        if (!is_object($templateMgr) || !method_exists($templateMgr, 'addHeader')) {
            return;
        }

        $nonce = $this->v2ResolveScriptNonce($templateMgr, $this->lastSupportContext->contextId());
        if ($nonce === null) {
            return;
        }

        $router = is_object($request) && method_exists($request, 'getRouter') ? $request->getRouter() : null;
        if (!is_object($router) || !method_exists($router, 'url')) {
            return;
        }

        $bindUrl = (string) $router->url(
            $request,
            $this->lastSupportContext->contextPath(),
            self::SUPPORT_GATEWAY_PAGE,
            'bind'
        );
        if ($bindUrl === '') {
            return;
        }

        $ticketJson = json_encode(
            $this->supportSessionBootstrap->bindingToken(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $bindUrlJson = json_encode(
            $bindUrl,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($ticketJson) || !is_string($bindUrlJson)) {
            return;
        }

        $nonceAttr = $nonce !== '' ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';
        $script = '<script' . $nonceAttr . ' data-ojs-chatwoot-binding="v2">' .
            '(function(){' .
            'if(window.__ojsSupportBindingV2Installed){return;}' .
            'window.__ojsSupportBindingV2Installed=true;' .
            'var ticket=' . $ticketJson . ';' .
            'var endpoint=' . $bindUrlJson . ';' .
            'var inflight=false;' .
            'function csrf(){var m=document.querySelector("meta[name=csrf-token]");return m?m.getAttribute("content")||"":"";}' .
            'function onMessage(ev){' .
            'if(!ticket||inflight){return;}' .
            'var data=(ev&&ev.detail)||{};' .
            'var cid=parseInt(data.conversation_id,10);' .
            'var token=csrf();' .
            'if(!cid||!token){return;}' .
            'inflight=true;' .
            'var body=new URLSearchParams();body.set("bindingToken",ticket);body.set("conversationId",String(cid));' .
            'fetch(endpoint,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8","Accept":"application/json","X-CSRF-TOKEN":token},body:body.toString()})' .
            '.then(function(r){return r.json();})' .
            '.then(function(j){if(j&&j.status===true){ticket="";window.removeEventListener("chatwoot:on-message",onMessage);}})' .
            '.catch(function(){})' .
            '.finally(function(){inflight=false;});' .
            '}' .
            'window.addEventListener("chatwoot:on-message",onMessage);' .
            'var current=document.currentScript;if(current&&current.parentNode){current.parentNode.removeChild(current);}' .
            '})();' .
            '</script>';

        $templateMgr->addHeader('chatwootSupportBindingV2Frontend', $script, ['contexts' => ['frontend']]);
        $templateMgr->addHeader('chatwootSupportBindingV2Backend', $script, ['contexts' => ['backend']]);
        $this->supportSessionHandshakeInjected = true;
    }

    private function runtimeContextBridge(): RuntimeContextBridge
    {
        if (!$this->runtimeContextBridge) {
            $this->runtimeContextBridge = new RuntimeContextBridge();
        }
        return $this->runtimeContextBridge;
    }

    private function contextProjector(): ChatwootContextProjector
    {
        if (!$this->contextProjector) {
            $this->contextProjector = new ChatwootContextProjector();
        }
        return $this->contextProjector;
    }

    private function v2ResolveScriptNonce($templateMgr, int $contextId): ?string
    {
        if (!$this->v2Bool($this->v2EffectiveSetting($contextId, 'cspSafeMode', false))) {
            return '';
        }
        if (!is_object($templateMgr) || !method_exists($templateMgr, 'getTemplateVars')) {
            return null;
        }
        $nonce = trim((string) ($templateMgr->getTemplateVars('cspNonce') ?? ''));
        return $nonce === '' ? null : $nonce;
    }

    private function v2EffectiveSetting(int $contextId, string $key, mixed $default = null): mixed
    {
        $local = $this->getSetting($contextId, $key);
        if (!$this->v2Blank($local)) {
            return $local;
        }

        if ($key !== 'enableGlobalDefaults' && $this->v2Bool($this->getSetting($contextId, 'enableGlobalDefaults'))) {
            $global = $this->getSetting(0, $key);
            if (!$this->v2Blank($global)) {
                return $global;
            }
        }

        return $default;
    }

    private function v2NormalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        return rtrim($url, '/');
    }

    private function v2PositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value))) {
            return (int) trim($value);
        }
        return null;
    }

    private function v2Blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function v2Bool(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_string($value)) return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        return (bool) $value;
    }
}
