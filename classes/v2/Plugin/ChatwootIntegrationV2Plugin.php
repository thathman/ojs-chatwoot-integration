<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Plugin;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\ChatwootApiService;
use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\CorrelationId;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\DiagnosticResultSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaginationParams;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PaymentStatusSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\PublicationStatusSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\RequiredActionsSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionListSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionSupportSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SubmissionVerificationSerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFailure;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiFieldAllowlist;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiRequestResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportApiResponse;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\SupportIdentitySerializer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\DatabaseSupportApiAuditLogger;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CanonicalToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainCustomToolProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainDocumentProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthReport;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainProvisioningHealthService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainScenarioProvisioner;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\CaptainSyncResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Captain\DatabaseSupportKnowledgeSyncRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\ChatwootConversationVerifier;
use APP\plugins\generic\chatwootIntegration\classes\v2\Chatwoot\LegacyWidgetIdentifierResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ChatwootContextProjector;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\AccountDiagnosticEngine;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SubmissionDiagnosticEngine;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\DatabaseSupportEventQueueRepository;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\DecisionRecordedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliverySettingsResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\PublicationStatusEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SubmissionCreatedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SubmissionStatusChangedEventAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEvent;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventMessageBuilder;
use APP\plugins\generic\chatwootIntegration\classes\v2\Handoff\EscalationIdempotencyGuard;
use APP\plugins\generic\chatwootIntegration\classes\v2\Handoff\HandoffSummaryFormatter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\SupportGatewayHealthAggregator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\SupportGatewayHealthSummary;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\JsonRequestBodyParser;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\McpGatewayPageHandler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ResponseTimingNormalizer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\ServiceTokenAuthenticator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\SupportGatewayPageHandler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Http\SupportKnowledgePageHandler;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHtmlRenderer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeRouteCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeSitemapRenderer;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpAuthenticator;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpDispatcher;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpErrorCode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpHandlerError;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpProtocol;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpRequestParser;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResourceRegistry;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpResponse;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpSupportApiFailureMapper;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\McpToolRegistry;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\AccountDiagnosticsTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\CapabilitiesListTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\EscalateSupportTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\FeePolicyTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\JournalProfileTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\PaymentStatusTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\PublicationStatusTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\RequiredActionsTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionDiagnosticsTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionListTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionPolicyTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SubmissionSupportStatusTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\SupportIdentityTool;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionBootstrap;
use APP\plugins\generic\chatwootIntegration\classes\v2\Session\SupportSessionService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\State\RequiredActionMapper;
use APP\plugins\generic\chatwootIntegration\classes\v2\State\SupportStateMapper;
use APP\plugins\generic\chatwootIntegration\classes\v2\Task\CaptainSyncScheduledTask;
use APP\plugins\generic\chatwootIntegration\classes\v2\Task\DeliverQueuedSupportEventsTask;
use APP\plugins\generic\chatwootIntegration\classes\v2\Task\PurgeExpiredSupportDataTask;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\SupportMailTestMailable;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\SupportVerificationMailable;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationChallenge;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailContentBuilder;
use Illuminate\Support\Facades\Mail;
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
class ChatwootIntegrationV2Plugin extends ChatwootIntegrationBasePlugin implements \PKP\plugins\interfaces\HasTaskScheduler
{
    private const SUPPORT_GATEWAY_PAGE = 'ojsSupportGateway';
    private const SUPPORT_KNOWLEDGE_PAGE = 'support-knowledge';
    private const MCP_GATEWAY_PAGE = 'ojsMcpGateway';
    /** IDN-015: response-timing floor for ojs_request_verification, masking the found-vs-not-found timing gap. */
    private const VERIFICATION_REQUEST_TIMING_FLOOR_SECONDS = 0.3;
    private const LEGACY_EXPORT_KEYS = [
        'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
        'chatwootCaptainAssistantId',
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

    /** ADM-003/ADM-005/ADM-006: adds the v2-only verbs ahead of v1's own manage() dispatch. */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'syncCaptainResources') {
            return $this->syncCaptainResources($request);
        }
        if ($request->getUserVar('verb') === 'retryDeadLetterEvents') {
            return $this->retryDeadLetterEvents($request);
        }
        if ($request->getUserVar('verb') === 'sendSupportMailTest') {
            return $this->sendSupportMailTest($request);
        }
        return parent::manage($args, $request);
    }

    /**
     * Wires the long-flagged known gap (docs/v2/TASKLIST.md IDN-017:
     * "no scheduled task ever calls purgeExpired() — expired rows are
     * never actually swept") into OJS 3.5's real scheduled-task lifecycle
     * — verified against a real local checkout of `pkp-lib`
     * (`PKP\plugins\interfaces\HasTaskScheduler`,
     * `PKP\scheduledTask\PKPScheduler::registerPluginSchedules()`, which
     * auto-discovers any plugin implementing this interface). Daily is
     * deliberately generous relative to session/challenge TTLs (minutes
     * to hours) — this is cleanup of already-unusable rows, not a
     * security control in itself.
     */
    public function registerSchedules(\PKP\scheduledTask\PKPScheduler $scheduler): void
    {
        $scheduler->addSchedule(new PurgeExpiredSupportDataTask())
            ->daily()
            ->name(PurgeExpiredSupportDataTask::class)
            ->withoutOverlapping();

        $scheduler->addSchedule(new CaptainSyncScheduledTask($this))
            ->daily()
            ->name(CaptainSyncScheduledTask::class)
            ->withoutOverlapping();

        // Deliberately more frequent than purge/Captain sync: a queued
        // support event sitting unsent for up to a day would defeat the
        // point of near-real-time editorial notifications.
        $scheduler->addSchedule(new DeliverQueuedSupportEventsTask($this))
            ->everyFiveMinutes()
            ->name(DeliverQueuedSupportEventsTask::class)
            ->withoutOverlapping();
    }

    public function setSupportGatewayPageHandler(string $hookName, array $args): bool
    {
        $page = & $args[0];
        $handler = & $args[3];

        if ($page === self::SUPPORT_GATEWAY_PAGE) {
            $handler = new SupportGatewayPageHandler($this);
            return true;
        }

        if ($page === self::SUPPORT_KNOWLEDGE_PAGE) {
            $handler = new SupportKnowledgePageHandler($this);
            return true;
        }

        if ($page === self::MCP_GATEWAY_PAGE) {
            $handler = new McpGatewayPageHandler($this);
            return true;
        }

        return false;
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
     * EVT-003/EVT-011: alongside v1's existing synchronous `Submission::add`
     * handling (untouched, always runs first, its return value is what
     * this method returns), also converts the real submission into a
     * normalized `SupportEvent` and enqueues it in the Event Bridge v2
     * queue. Nothing yet reads that queue and delivers to Chatwoot — this
     * is deliberately enqueue-only, so a bug here can never reach the
     * live Chatwoot API. Any failure is swallowed: an Event Bridge v2
     * problem must never break real submission creation, exactly like
     * `addChatwootWidget()`'s own try/catch around v2 work.
     */
    public function handleSubmissionCreated($hookName, $args)
    {
        $result = parent::handleSubmissionCreated($hookName, $args);

        try {
            $submission = $args[0] ?? null;
            if (is_object($submission) && method_exists($submission, 'getData')) {
                $contextId = (int) $submission->getData('contextId');
                if ($contextId > 0) {
                    $title = $this->runtimeContextBridge()->getSubmissionTitle($submission);
                    $this->v2EnqueueEvent(SubmissionCreatedEventAdapter::fromSubmission($submission, $contextId, $title));
                }
            }
        } catch (\Throwable $e) {
            // Event Bridge v2 enqueue failure must never break real
            // submission creation.
        }

        return $result;
    }

    /**
     * EVT-004/EVT-011: same pattern as `handleSubmissionCreated()` above —
     * v1's real `Decision::add` handling runs first and unconditionally,
     * then the decision is converted and enqueued, isolated in its own
     * try/catch.
     */
    public function handleEditorDecision($hookName, $args)
    {
        $result = parent::handleEditorDecision($hookName, $args);

        try {
            $decision = $args[0] ?? null;
            if (is_object($decision) && method_exists($decision, 'getData')) {
                $submissionId = (int) $decision->getData('submissionId');
                $submission = $submissionId > 0 ? $this->runtimeContextBridge()->loadSubmission($submissionId) : null;
                if (is_object($submission) && method_exists($submission, 'getData')) {
                    $contextId = (int) $submission->getData('contextId');
                    if ($contextId > 0) {
                        $title = $this->runtimeContextBridge()->getSubmissionTitle($submission);
                        $this->v2EnqueueEvent(DecisionRecordedEventAdapter::fromDecision($decision, $submission, $contextId, $title));
                    }
                }
            }
        } catch (\Throwable $e) {
            // Event Bridge v2 enqueue failure must never break a real
            // editorial decision.
        }

        return $result;
    }

    /**
     * EVT-005/EVT-011: same pattern — v1's real `Submission::updateStatus`
     * handling runs first and unconditionally.
     */
    public function handleSubmissionStatusUpdated($hookName, $args)
    {
        $result = parent::handleSubmissionStatusUpdated($hookName, $args);

        try {
            $newStatus = (int) ($args[0] ?? 0);
            $oldStatus = (int) ($args[1] ?? 0);
            $submission = $args[2] ?? null;
            if (is_object($submission) && method_exists($submission, 'getData')) {
                $contextId = (int) $submission->getData('contextId');
                if ($contextId > 0) {
                    $title = $this->runtimeContextBridge()->getSubmissionTitle($submission);
                    $this->v2EnqueueEvent(SubmissionStatusChangedEventAdapter::fromStatusChange($submission, $oldStatus, $newStatus, $contextId, $title));
                }
            }
        } catch (\Throwable $e) {
            // Event Bridge v2 enqueue failure must never break a real
            // submission status update.
        }

        return $result;
    }

    /**
     * EVT-005/EVT-011: same pattern — v1's real `Publication::publish`
     * handling runs first and unconditionally.
     */
    public function handlePublicationPublished($hookName, $args)
    {
        $result = parent::handlePublicationPublished($hookName, $args);

        try {
            $publication = $args[0] ?? null;
            $submission = $args[2] ?? null;
            if (is_object($submission) && method_exists($submission, 'getData')) {
                $contextId = (int) $submission->getData('contextId');
                if ($contextId > 0) {
                    $title = $this->runtimeContextBridge()->getSubmissionTitle($submission);
                    $this->v2EnqueueEvent(PublicationStatusEventAdapter::fromPublication($publication, $submission, $contextId, $title));
                }
            }
        } catch (\Throwable $e) {
            // Event Bridge v2 enqueue failure must never break a real
            // publication publish.
        }

        return $result;
    }

    /** Shared EVT-010/EVT-011 resolve+enqueue step for every hook wiring above. */
    private function v2EnqueueEvent(?SupportEvent $event): void
    {
        if ($event === null) {
            return;
        }
        $contextId = $event->contextId();
        $legacyMode = (string) $this->v2EffectiveSetting($contextId, 'eventSyncMode', 'note');
        $configuredGlobalMode = trim((string) $this->v2EffectiveSetting($contextId, 'eventDeliveryGlobalMode', ''));
        $consentGiven = (bool) $this->v2EffectiveSetting($contextId, 'eventDeliveryCustomerMessageConsent', false);
        $overridesJson = (string) $this->v2EffectiveSetting($contextId, 'eventDeliveryPerEventOverridesJson', '');

        $globalMode = EventDeliverySettingsResolver::resolveGlobalMode($configuredGlobalMode, $legacyMode, $consentGiven);
        $perEventOverrides = EventDeliverySettingsResolver::parsePerEventOverrides($overridesJson, $consentGiven);

        $mode = EventDeliveryPolicy::resolve($event->type(), $globalMode, $perEventOverrides);
        (new DatabaseSupportEventQueueRepository())->enqueue($event, $mode);
    }

    /**
     * EVT-011/EVT-012: the Event Bridge v2 delivery consumer — the first
     * point this whole build calls the live Chatwoot API from a queued
     * event rather than a live HTTP request. Mirrors v1's real
     * `sendChatwootEvent()` exactly (find/create contact by the resolved
     * author's email, post a note to an existing conversation, or open
     * one when the resolved delivery mode allows it and an inbox is
     * configured) — deliberately reusing that same real logic shape
     * rather than inventing a new delivery algorithm.
     *
     * Called by `DeliverQueuedSupportEventsTask` on the same daily
     * schedule as purge/Captain sync. A single row's failure never stops
     * the batch; `attempts`/`run_after` bookkeeping (EVT-014) is the
     * existing repository's job, not this method's.
     *
     * @return array{delivered:int,failed:int}
     */
    public function deliverQueuedSupportEvents(int $limit = 20): array
    {
        $bridge = $this->runtimeContextBridge();
        $repo = new DatabaseSupportEventQueueRepository();
        $delivered = 0;
        $failed = 0;
        $now = time();

        foreach ($repo->fetchPendingBatch($limit, $now) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $attempts = (int) ($row['attempts'] ?? 0) + 1;

            try {
                $ok = $this->v2DeliverQueuedEventRow($bridge, $row);
                if ($ok) {
                    $repo->markDelivered($id, time());
                    $delivered++;
                } else {
                    $repo->markFailed($id, 'delivery_failed', $attempts, 5, time());
                    $failed++;
                }
            } catch (\Throwable $e) {
                $repo->markFailed($id, 'internal_error', $attempts, 5, time());
                $failed++;
            }
        }

        return ['delivered' => $delivered, 'failed' => $failed];
    }

    /** @param array<string,mixed> $row */
    private function v2DeliverQueuedEventRow($bridge, array $row): bool
    {
        $mode = (string) ($row['delivery_mode'] ?? EventDeliveryMode::PRIVATE_NOTE);

        // AUDIT_ONLY never touches Chatwoot at all — the queue row's own
        // lifecycle (enqueued -> delivered) already is the audit trail for
        // this mode; there is nothing further to send.
        if ($mode === EventDeliveryMode::AUDIT_ONLY) {
            return true;
        }

        $contextId = (int) ($row['context_id'] ?? 0);
        $submissionId = (int) ($row['resource_id'] ?? 0);
        if ($contextId <= 0 || $submissionId <= 0) {
            return false;
        }

        $submission = $bridge->loadSubmission($submissionId);
        if (!is_object($submission)) {
            return false;
        }

        $author = $bridge->getPrimarySubmissionAuthor($submission);
        if ($author === null) {
            return false;
        }

        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
        $inboxId = (int) $this->v2EffectiveSetting($contextId, 'chatwootInboxId', 0);
        if ($baseUrl === '' || $apiToken === '') {
            return false;
        }

        $attributes = json_decode((string) ($row['attributes'] ?? '{}'), true);
        $attributes = is_array($attributes) ? $attributes : [];
        $message = SupportEventMessageBuilder::buildFromFields((string) ($row['event_type'] ?? ''), $submissionId, $attributes);

        // Both open/update and opt-in customer message may need to create
        // a brand-new contact/conversation; private note never does (a
        // note only ever attaches to a conversation that already exists).
        $mayCreateContactOrConversation = in_array($mode, [EventDeliveryMode::OPEN_UPDATE_CONVERSATION, EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE], true);

        $api = new ChatwootApiService($baseUrl, $apiToken);
        $contact = $api->findContactByEmail($author['email']);
        if (!$contact && $mayCreateContactOrConversation) {
            $contact = $api->createContact($author['email'], $author['name'], (string) $author['userId']);
        }
        if (!$contact || empty($contact['id'])) {
            return false;
        }

        $conversations = $api->getContactConversations((int) $contact['id']);
        if (!empty($conversations) && !empty($conversations[0]['id'])) {
            return $mode === EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE
                ? (bool) $api->createConversationMessage((int) $conversations[0]['id'], $message, false)
                : (bool) $api->createConversationNote((int) $conversations[0]['id'], $message);
        }
        if ($mayCreateContactOrConversation && $inboxId > 0) {
            return (bool) $api->createConversation((int) $contact['id'], $inboxId, $message);
        }

        return false;
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
     * Server-to-server endpoint for Chatwoot Captain: privacy-preserving
     * account/login diagnostic (ojs_diagnose_account in
     * docs/v2/API_MCP_SPEC.md §7.9), gated on `account.diagnose_own` (V2).
     *
     * Deliberately scoped to the currently V2-authenticated identity's own
     * account only — `scope` selects which deterministic check to run, but
     * there is no user/email/username parameter. This must never become an
     * arbitrary account lookup endpoint; Captain cannot ask "diagnose user
     * X", only "diagnose me". See AccountDiagnosticEngine for exactly what
     * each scope can and cannot prove — most scopes correctly return
     * `unknown` rather than guessing, since this codebase has no visibility
     * into email delivery, login-attempt history, or password state.
     */
    public function supportAccountDiagnosticsRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'accountDiagnostics');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $scope = trim((string) $request->getUserVar('scope'));
        if (!in_array($scope, AccountDiagnosticEngine::SCOPES, true)) {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'scope must be one of: ' . implode(', ', AccountDiagnosticEngine::SCOPES),
                $result->correlationId(),
                400
            );
        }

        $bridge = $this->runtimeContextBridge();
        $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $result->assurance(),
            $result->identity()
        ));
        $actions = $decision ? $bridge->availableActions($decision) : [];

        if (!$decision || !$decision->allows('account.diagnose_own')) {
            SupportApiResponse::success(DiagnosticResultSerializer::unverified($result, $actions), $result->correlationId());
        }

        $accountFields = $bridge->getUserAccountFields($result->identity()->userId() ?? 0);
        $mailConfig = $scope === AccountDiagnosticEngine::SCOPE_MAIL_CONFIGURATION ? $bridge->getMailTransportConfiguration() : null;
        $diagnosis = AccountDiagnosticEngine::diagnose($scope, $accountFields['disabled'], $accountFields['dateValidated'], $mailConfig);

        SupportApiResponse::success(DiagnosticResultSerializer::verified($diagnosis, $actions), $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: creates a structured
     * human handoff — a Chatwoot private note summarizing exactly what the
     * gateway already safely knows (ojs_escalate_support in
     * docs/v2/API_MCP_SPEC.md §7.12). Gated on `support.escalate`
     * (deliberately V0/unauthenticated, same as every other version of
     * this capability — a human handoff must remain available even when
     * verification itself is failing, which is often exactly why one is
     * needed).
     *
     * "Does not grant additional data access": every fact folded into the
     * summary is independently re-checked against the exact same
     * capability the dedicated endpoint for that fact enforces
     * (submission.read_own_support_status/read_own_required_actions/
     * read_own_publication_status/read_own_payment_status) — this can
     * never surface more to a human agent's note than the verified caller
     * could already read themselves through those endpoints. `reason` is
     * the one caller-supplied field; it is capped and stripped of control
     * characters (see HandoffSummaryFormatter) but never otherwise
     * trusted as authoritative about OJS state.
     *
     * The private note always targets the conversation tuple the caller
     * supplied for this same request (the same chatwootAccountId/
     * chatwootConversationId every Support API call already carries) —
     * never a caller-supplied "post to conversation X" override. Posting
     * is best-effort: a Chatwoot API failure never fails the whole
     * request, since the important outcome for Captain is "the escalation
     * was recorded," not "Chatwoot's note API happened to succeed."
     */
    public function supportEscalateRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'escalate');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $reason = trim((string) $request->getUserVar('reason'));
        if ($reason === '') {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'reason is required.',
                $result->correlationId(),
                400
            );
        }

        $bridge = $this->runtimeContextBridge();
        $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $result->assurance(),
            $result->identity()
        ));
        if (!$decision || !$decision->allows('support.escalate')) {
            SupportApiResponse::error(SupportApiErrorCode::CAPABILITY_DENIED, 'Escalation is not available.', $result->correlationId(), 403);
        }

        $submissionId = $this->v2PositiveInt($request->getUserVar('submissionId'));
        $relationship = null;
        $supportState = null;
        $requiredActions = [];
        $publicationFacts = null;
        $paymentFacts = null;
        $diagnostic = null;

        if ($submissionId !== null && $result->verified()) {
            $submission = $bridge->loadSubmission($submissionId);
            if ($submission) {
                $candidate = $bridge->resolveSubmissionRelationship($result->identity(), $submission);
                if ($candidate && !$candidate->isEmpty()) {
                    $relationship = $candidate;
                    $resourceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
                        CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
                        'v3',
                        $result->identity(),
                        $relationship
                    ));

                    if ($resourceDecision && $resourceDecision->allows('submission.read_own_support_status')) {
                        $supportState = $this->supportStateForDiagnostics($bridge, $submission);
                    }
                    if ($resourceDecision && $resourceDecision->allows('submission.read_own_required_actions') && $supportState !== null) {
                        $requiredActions = array_values(array_unique(array_merge(
                            $relationship->has('author') ? RequiredActionMapper::forAuthor($supportState) : [],
                            $relationship->has('reviewer') ? RequiredActionMapper::forReviewer($bridge->getReviewAssignmentStatuses($submissionId, $result->identity()->userId() ?? 0)) : []
                        )));
                        // HOF-005: re-derived internally from the required
                        // actions this same request already independently
                        // verified — never a caller-supplied diagnostic
                        // (Captain could claim any code).
                        $diagnostic = SubmissionDiagnosticEngine::diagnoseRequiredAction($requiredActions);
                    }
                    if ($resourceDecision && $resourceDecision->allows('submission.read_own_publication_status') && $supportState !== null) {
                        $publicationFacts = ['status' => $supportState === 'published' || $supportState === 'scheduled_for_publication' ? $supportState : 'not_yet_published', 'doi' => null];
                        $publicationDoi = $bridge->getPublicationFields($submission)['doi'];
                        $publicationFacts['doi'] = ($supportState === 'published' || $supportState === 'scheduled_for_publication') ? $publicationDoi : null;
                    }
                    if ($resourceDecision && $resourceDecision->allows('submission.read_own_payment_status')) {
                        $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext($request));
                        $paymentStatus = 'not_applicable';
                        if ($feeInfo['enabled']) {
                            $paid = $bridge->hasPaidPublicationFee($result->identity()->userId() ?? 0, $submissionId);
                            $paymentStatus = $paid ? 'paid' : 'unpaid';
                        }
                        $paymentFacts = ['feeEnabled' => $feeInfo['enabled'], 'status' => $paymentStatus];
                    }
                }
            }
        }

        $summary = HandoffSummaryFormatter::build(
            SupportIdentitySerializer::serialize($result),
            $relationship,
            $supportState,
            $requiredActions,
            $publicationFacts,
            $paymentFacts,
            $reason,
            $diagnostic
        );

        $chatwootAccountId = trim((string) $request->getUserVar('chatwootAccountId'));
        $chatwootConversationId = trim((string) $request->getUserVar('chatwootConversationId'));
        $idempotencyKey = trim((string) $request->getUserVar('idempotencyKey'));
        if ($idempotencyKey === '') {
            $idempotencyKey = hash('sha256', $chatwootAccountId . ':' . $chatwootConversationId . ':' . $reason . ':' . ($submissionId ?? 0));
        }

        $noteCreated = false;
        $duplicate = false;
        $guard = new EscalationIdempotencyGuard();
        if (!$guard->claim($chatwootAccountId . ':' . $chatwootConversationId . ':' . $idempotencyKey)) {
            $duplicate = true;
        } elseif ($chatwootAccountId !== '' && $chatwootConversationId !== '') {
            try {
                $contextId = $result->identity()->contextId();
                $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
                $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
                if ($baseUrl !== '' && $apiToken !== '') {
                    $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
                    $noteCreated = (bool) $chatwoot->createConversationNote($chatwootConversationId, HandoffSummaryFormatter::renderNoteText($summary));
                }
            } catch (\Throwable $e) {
                $noteCreated = false;
            }
        }

        SupportApiResponse::success([
            'escalated' => true,
            'noteCreated' => $noteCreated,
            'duplicate' => $duplicate,
            'summary' => $summary,
        ], $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: requests an external
     * PIN or secure-link verification challenge (ojs_request_verification
     * in docs/v2/API_MCP_SPEC.md §7.1), for a user who has not reached V2
     * through an authenticated OJS session (e.g. arriving via WhatsApp,
     * email, or an external Chatwoot widget instance).
     *
     * The claimed `email` is a lookup key only, never identity. Regardless
     * of whether the email exists, the account is disabled, mail cannot be
     * sent, or the request is throttled, the public response is always the
     * same generic shape — anti-enumeration is enforced structurally here,
     * not by convention: every branch below falls through to the exact
     * same `SupportApiResponse::success(['verificationRequested' => true])`
     * call, and any failure along the way (lookup miss, rate limit,
     * mail-send exception) is swallowed silently rather than surfaced.
     *
     * Successful verification establishes V2 only (see
     * supportVerificationConfirmRequest) — never V3, even when `purpose`
     * is submission-related; resource-scoped assurance is always a
     * separate, later step.
     */
    public function supportVerificationRequestRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'verificationRequest');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $email = trim((string) $request->getUserVar('email'));
        $purpose = trim((string) $request->getUserVar('purpose'));
        if ($email === '' || !in_array($purpose, VerificationChallenge::PURPOSES, true)) {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'email and a valid purpose are required.',
                $result->correlationId(),
                400
            );
        }

        $method = trim((string) $request->getUserVar('method'));
        $method = $method === VerificationChallenge::METHOD_LINK ? VerificationChallenge::METHOD_LINK : VerificationChallenge::METHOD_PIN;

        $contextId = $result->identity()->contextId();
        $chatwootAccountId = trim((string) $request->getUserVar('chatwootAccountId'));
        $chatwootContactId = trim((string) $request->getUserVar('chatwootContactId'));
        $chatwootConversationId = trim((string) $request->getUserVar('chatwootConversationId'));

        $auditReason = 'malformed_request';
        // TST-019: always generated up front (never left null) so a real
        // client can always complete verificationConfirm. When no real
        // challenge is created below, this stays a same-shape (32 hex
        // chars, matching VerificationChallengeService's own
        // bin2hex(random_bytes(16))) reference that simply never matches
        // any stored row — indistinguishable from a real one by shape or
        // timing, so returning it never leaks whether the account exists.
        $publicReference = bin2hex(random_bytes(16));
        // IDN-015: normalized below to a fixed floor regardless of which
        // branch runs, closing the timing side-channel between "user not
        // found" (near-instant) and "user found, mail sent" (real hashing
        // + mail work).
        $timingStartedAt = microtime(true);

        try {
            if ($chatwootAccountId !== '' && $chatwootContactId !== '' && $chatwootConversationId !== '') {
                $auditReason = 'user_not_found';
                $bridge = $this->runtimeContextBridge();
                $user = $bridge->getUserByEmail($email);

                if ($user) {
                    $auditReason = 'throttled';
                    $pepper = $this->v2VerificationPepper($contextId);
                    $prepared = $bridge->requestVerificationChallenge(
                        $contextId,
                        (int) $user->getId(),
                        $purpose,
                        $method,
                        $chatwootAccountId,
                        $chatwootContactId,
                        $chatwootConversationId,
                        $pepper
                    );

                    if ($prepared !== null) {
                        $auditReason = 'mail_not_configured';
                        $context = $bridge->getContext($request);
                        if (is_object($context)) {
                            $journalName = method_exists($context, 'getLocalizedName') ? (string) $context->getLocalizedName() : '';
                            $ttlMinutes = max(1, (int) ceil(($prepared->challenge()->expiresAt() - time()) / 60));
                            $subject = VerificationEmailContentBuilder::subject($journalName);

                            $body = null;
                            if ($prepared->challenge()->method() === VerificationChallenge::METHOD_LINK) {
                                $url = $bridge->getVerificationLinkUrl($request, $prepared->challenge()->publicReference(), $prepared->plaintextSecret());
                                if ($url !== null) {
                                    $body = VerificationEmailContentBuilder::linkBody($journalName, $url, $ttlMinutes);
                                }
                            } else {
                                $body = VerificationEmailContentBuilder::pinBody($journalName, $prepared->plaintextSecret(), $ttlMinutes);
                            }

                            if ($body !== null) {
                                Mail::send(new SupportVerificationMailable($context, $user, $subject, $body));
                                $auditReason = 'challenge_created';
                                $publicReference = $prepared->challenge()->publicReference();
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // A mail-send (or any other) failure here must never change
            // the public response — it would otherwise leak that an
            // account genuinely exists.
            $auditReason = 'internal_error';
        }

        $this->v2AuditVerificationEvent('verificationRequest', $contextId, $auditReason === 'challenge_created' ? 'allow' : 'deny', $auditReason, $result->correlationId());

        ResponseTimingNormalizer::normalize($timingStartedAt, self::VERIFICATION_REQUEST_TIMING_FLOOR_SECONDS);

        SupportApiResponse::success(['verificationRequested' => true, 'challenge' => $publicReference], $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: confirms a PIN
     * against a previously requested challenge (ojs_confirm_verification
     * in docs/v2/API_MCP_SPEC.md §7.2) and, on success, establishes a
     * normal V2 support session bound to this exact conversation. Never
     * returns the stored secret, and collapses every distinct failure
     * reason (wrong PIN, expired, revoked, superseded, locked out, wrong
     * conversation, wrong purpose, unknown reference) into the same
     * generic `verified: false` — the secure-link confirmation path
     * (verifyLinkRequest) is the browser-facing sibling of this endpoint.
     */
    public function supportVerificationConfirmRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'verificationConfirm');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $challengeReference = trim((string) $request->getUserVar('challenge'));
        $purpose = trim((string) $request->getUserVar('purpose'));
        $pin = trim((string) $request->getUserVar('pin'));
        if ($challengeReference === '' || !in_array($purpose, VerificationChallenge::PURPOSES, true) || $pin === '') {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'challenge, purpose, and pin are required.',
                $result->correlationId(),
                400
            );
        }

        $contextId = $result->identity()->contextId();
        $chatwootAccountId = trim((string) $request->getUserVar('chatwootAccountId'));
        $chatwootContactId = trim((string) $request->getUserVar('chatwootContactId'));
        $chatwootConversationId = trim((string) $request->getUserVar('chatwootConversationId'));

        $bridge = $this->runtimeContextBridge();
        $pepper = $this->v2VerificationPepper($contextId);
        $outcome = $bridge->confirmVerificationPin(
            $challengeReference,
            $pin,
            $contextId,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId,
            $purpose,
            $pepper
        );

        if (!$outcome->isConsumed() || !$outcome->challenge()) {
            $this->v2AuditVerificationEvent('verificationConfirm', $contextId, 'deny', $outcome->status(), $result->correlationId());
            SupportApiResponse::success(['verified' => false], $result->correlationId());
        }

        $challenge = $outcome->challenge();
        $session = $bridge->establishSupportSessionFromExternalVerification(
            $contextId,
            $challenge->userId(),
            SupportSessionService::METHOD_EXTERNAL_PIN,
            $chatwootAccountId,
            $chatwootContactId,
            $chatwootConversationId
        );

        $this->v2AuditVerificationEvent('verificationConfirm', $contextId, 'allow', $outcome->status(), $result->correlationId());

        SupportApiResponse::success([
            'verified' => true,
            'assurance' => $session ? $session->assuranceLevel() : SupportSessionService::ASSURANCE_AUTHENTICATED_SESSION,
        ], $result->correlationId());
    }

    /**
     * Browser-facing GET endpoint for the secure verification link
     * (ojs_confirm_verification's link variant, docs/v2/API_MCP_SPEC.md
     * §7.2) — deliberately not part of the service-authenticated Support
     * API pipeline, since a browser cannot supply a Bearer token. Not
     * CSRF-protected and not restricted to same-origin/the original
     * browser session: the link may legitimately be opened on a different
     * device than the one chatting, and its security comes entirely from
     * the token's own entropy, single-use consumption, and the
     * conversation binding already stored server-side on the challenge
     * (see VerificationChallengeService::confirmLinkToken()) — never from
     * requiring the "right" browser to click it.
     *
     * Always renders the same generic page shape on failure (expired,
     * wrong journal, unknown reference, already used, etc.) — anti-
     * enumeration applies to this browser-facing page exactly as it does
     * to every JSON endpoint.
     */
    public function verifyLinkRequest($request): void
    {
        $challengeReference = trim((string) $request->getUserVar('challenge'));
        $token = trim((string) $request->getUserVar('token'));
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : 0;

        $verified = false;
        if ($challengeReference !== '' && $token !== '' && $contextId > 0) {
            $bridge = $this->runtimeContextBridge();
            $outcome = $bridge->confirmVerificationLinkToken($challengeReference, $token, $contextId);

            if ($outcome->isConsumed() && $outcome->challenge()) {
                $challenge = $outcome->challenge();
                $bridge->establishSupportSessionFromExternalVerification(
                    $contextId,
                    $challenge->userId(),
                    SupportSessionService::METHOD_EXTERNAL_LINK,
                    $challenge->chatwootAccountId(),
                    $challenge->chatwootContactId(),
                    $challenge->chatwootConversationId()
                );
                $verified = true;
            }

            $this->v2AuditVerificationEvent('verify', $contextId, $verified ? 'allow' : 'deny', $outcome->status(), CorrelationId::generate());
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->v2RenderVerificationLinkPage($verified);
        exit;
    }

    /**
     * Public, unauthenticated GET root of generated journal knowledge
     * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §4). Never touches a
     * SupportSession/Chatwoot conversation/OJS user/capability — the
     * compiler behind this has no such inputs to consult (see
     * KnowledgeCompiler, KnowledgeProviderInterface).
     */
    public function supportKnowledgeIndexRequest($request): void
    {
        $context = $request->getContext();
        $journalName = $context && method_exists($context, 'getLocalizedName') ? (string) $context->getLocalizedName() : 'Journal';

        $navLinks = [];
        foreach (KnowledgeRouteCatalog::categories() as $category) {
            $navLinks[ucfirst($category)] = $this->v2KnowledgePageUrl($request, $context, $category);
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo KnowledgeHtmlRenderer::renderIndex($journalName, $navLinks);
        exit;
    }

    /**
     * One generated knowledge category page (about/submissions/review/fees/
     * publication/pages/accounts/policies — see KnowledgeRouteCatalog).
     * $category must be one of KnowledgeRouteCatalog::CATEGORIES's keys —
     * an unrecognized category renders an empty-but-valid page rather than
     * a fatal, since this is reached directly from a public URL.
     */
    public function supportKnowledgeCategoryRequest($request, string $category): void
    {
        $context = $request->getContext();
        $journalName = $context && method_exists($context, 'getLocalizedName') ? (string) $context->getLocalizedName() : 'Journal';
        $contextId = $context && method_exists($context, 'getId') ? (int) $context->getId() : 0;
        $prefix = KnowledgeRouteCatalog::keyPrefixFor($category);

        $facts = [];
        $locale = (string) Locale::getLocale();
        if ($prefix !== null && $contextId > 0) {
            $compilation = $this->runtimeContextBridge()->compileKnowledge($context, $request, $contextId, $locale);
            if ($compilation) {
                $facts = $compilation->factsWithKeyPrefix($prefix);
                $locale = $compilation->locale();
            }
        }

        $navLinks = [];
        foreach (KnowledgeRouteCatalog::categories() as $navCategory) {
            $navLinks[ucfirst($navCategory)] = $this->v2KnowledgePageUrl($request, $context, $navCategory);
        }

        header('Content-Type: text/html; charset=UTF-8');
        echo KnowledgeHtmlRenderer::renderCategory($journalName, ucfirst($category), $facts, $navLinks, $locale);
        exit;
    }

    /**
     * `/support-knowledge/sitemap.xml` — enumerates exactly the same
     * KnowledgeRouteCatalog category list the root page links, plus the
     * root itself. Never a Support API/verification/admin/submission URL:
     * this method has no way to reach any of those in the first place.
     */
    public function supportKnowledgeSitemapRequest($request): void
    {
        $context = $request->getContext();
        $urls = [$this->v2KnowledgePageUrl($request, $context, null)];
        foreach (KnowledgeRouteCatalog::categories() as $category) {
            $urls[] = $this->v2KnowledgePageUrl($request, $context, $category);
        }
        $urls = array_values(array_filter($urls, static fn (string $url): bool => $url !== ''));

        header('Content-Type: application/xml; charset=UTF-8');
        echo KnowledgeSitemapRenderer::render($urls);
        exit;
    }

    /**
     * MCP-003: the stateless Streamable HTTP MCP endpoint (ADR-023).
     * Authenticates via McpAuthenticator against the journal's own
     * `mcpServiceToken` setting — a distinct credential namespace, never
     * `chatwootSupportApiToken`/`chatwootApiAccessToken`. Only after that
     * succeeds does the raw body even get parsed, so an unauthenticated
     * caller can never use parse errors, protocol-revision negotiation, or
     * tool names as a probing oracle.
     *
     * Currently registers only `tools/list`/`tools/call` with the one
     * `journal.get_profile` tool (MCP-003's "first safe tools" slice);
     * `server/discover`/`resources/*` remain unregistered-but-supported —
     * McpDispatcher already reports those as METHOD_NOT_FOUND rather than
     * a fatal, exactly like an unrecognized method would.
     */
    public function mcpRequest($request): void
    {
        $context = $request->getContext();
        $contextId = $context && method_exists($context, 'getId') ? (int) $context->getId() : 0;
        if ($contextId <= 0) {
            $this->v2McpRespond(McpResponse::error(null, McpErrorCode::INVALID_REQUEST, 'Journal context could not be resolved.'));
        }

        $configuredMcpToken = (string) $this->v2EffectiveSetting($contextId, 'mcpServiceToken', '');
        $authorizationHeader = ServiceTokenAuthenticator::resolveAuthorizationHeader();
        if (!McpAuthenticator::verify($configuredMcpToken, $authorizationHeader)) {
            $this->v2McpRespond(McpResponse::error(null, McpErrorCode::UNAUTHORIZED, 'Request could not be authenticated.'));
        }

        $rawBody = (string) file_get_contents('php://input');
        $headers = [];
        if (isset($_SERVER['HTTP_MCP_PROTOCOL_VERSION'])) {
            $headers['Mcp-Protocol-Version'] = (string) $_SERVER['HTTP_MCP_PROTOCOL_VERSION'];
        }

        $parsed = McpRequestParser::parse($rawBody, $headers);
        if ($parsed instanceof McpResponse) {
            $this->v2McpRespond($parsed);
        }

        $locale = (string) Locale::getLocale();
        $compilation = $this->runtimeContextBridge()->compileKnowledge($context, $request, $contextId, $locale);

        $registry = new McpToolRegistry();
        $registry->register(
            JournalProfileTool::NAME,
            JournalProfileTool::DESCRIPTION,
            JournalProfileTool::inputSchema(),
            fn (array $arguments): array => JournalProfileTool::handle($compilation)
        );
        $registry->register(
            SubmissionPolicyTool::NAME,
            SubmissionPolicyTool::DESCRIPTION,
            SubmissionPolicyTool::inputSchema(),
            fn (array $arguments): array => SubmissionPolicyTool::handle($compilation)
        );
        $registry->register(
            FeePolicyTool::NAME,
            FeePolicyTool::DESCRIPTION,
            FeePolicyTool::inputSchema(),
            fn (array $arguments): array => FeePolicyTool::handle($compilation)
        );
        $identityResolver = new SupportApiRequestResolver($this->runtimeContextBridge());
        $registry->register(
            SupportIdentityTool::NAME,
            SupportIdentityTool::DESCRIPTION,
            SupportIdentityTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.identity.get_support_identity');
                return SupportIdentityTool::handle($result);
            }
        );
        $registry->register(
            RequiredActionsTool::NAME,
            RequiredActionsTool::DESCRIPTION,
            RequiredActionsTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.submission.get_required_actions');

                $bridge = $this->runtimeContextBridge();
                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                if ($submissionId === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'submissionId is required.');
                }

                $ctx = $this->v2ResolveMcpSubmissionContext($bridge, $result, $submissionId);
                $relationship = $ctx['relationship'];
                $decision = $ctx['decision'];
                $actions = $ctx['actions'];

                if (!$relationship || !$decision || !$decision->allows('submission.read_own_required_actions')) {
                    return RequiredActionsSerializer::unverified($result, $actions);
                }

                $requiredActions = [];
                if ($relationship->has('author')) {
                    $stateFields = $bridge->getSubmissionStateFields($ctx['submission']);
                    $supportState = SupportStateMapper::map(
                        $stateFields['status'],
                        $stateFields['stageId'],
                        $stateFields['reviewRoundStatus'],
                        $stateFields['submissionProgress']
                    );
                    $requiredActions = array_merge($requiredActions, RequiredActionMapper::forAuthor($supportState));
                }
                if ($relationship->has('reviewer')) {
                    $reviewStatuses = $bridge->getReviewAssignmentStatuses($submissionId, $result->identity()->userId() ?? 0);
                    $requiredActions = array_merge($requiredActions, RequiredActionMapper::forReviewer($reviewStatuses));
                }
                $requiredActions = array_values(array_unique($requiredActions));

                return RequiredActionsTool::handleVerified($relationship, $requiredActions, $actions);
            }
        );
        $registry->register(
            SubmissionSupportStatusTool::NAME,
            SubmissionSupportStatusTool::DESCRIPTION,
            SubmissionSupportStatusTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.submission.get_support_status');

                $bridge = $this->runtimeContextBridge();
                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                if ($submissionId === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'submissionId is required.');
                }

                $ctx = $this->v2ResolveMcpSubmissionContext($bridge, $result, $submissionId);
                $relationship = $ctx['relationship'];
                $decision = $ctx['decision'];
                $actions = $ctx['actions'];

                if (!$relationship || !$decision || !$decision->allows('submission.read_own_support_status')) {
                    return SubmissionSupportSerializer::unverified($result, $actions);
                }

                $stateFields = $bridge->getSubmissionStateFields($ctx['submission']);
                $supportState = SupportStateMapper::map(
                    $stateFields['status'],
                    $stateFields['stageId'],
                    $stateFields['reviewRoundStatus'],
                    $stateFields['submissionProgress']
                );
                $stateConfidence = SupportStateMapper::confidence(
                    $stateFields['status'],
                    $stateFields['stageId'],
                    $stateFields['reviewRoundStatus'],
                    $stateFields['submissionProgress']
                );

                return SubmissionSupportStatusTool::handleVerified(
                    $relationship,
                    $bridge->getSubmissionTitle($ctx['submission']),
                    $supportState,
                    SupportStateMapper::explain($supportState),
                    $actions,
                    $stateConfidence
                );
            }
        );
        $registry->register(
            PublicationStatusTool::NAME,
            PublicationStatusTool::DESCRIPTION,
            PublicationStatusTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.publication.get_status');

                $bridge = $this->runtimeContextBridge();
                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                if ($submissionId === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'submissionId is required.');
                }

                $ctx = $this->v2ResolveMcpSubmissionContext($bridge, $result, $submissionId);
                $relationship = $ctx['relationship'];
                $decision = $ctx['decision'];
                $actions = $ctx['actions'];

                if (!$relationship || !$decision || !$decision->allows('submission.read_own_publication_status')) {
                    return PublicationStatusSerializer::unverified($result, $actions);
                }

                $stateFields = $bridge->getSubmissionStateFields($ctx['submission']);
                $supportState = SupportStateMapper::map(
                    $stateFields['status'],
                    $stateFields['stageId'],
                    $stateFields['reviewRoundStatus'],
                    $stateFields['submissionProgress']
                );

                if ($supportState !== 'published' && $supportState !== 'scheduled_for_publication') {
                    return PublicationStatusTool::handleVerified($relationship, 'not_yet_published', null, null, null, $actions);
                }

                $publicationFields = $bridge->getPublicationFields($ctx['submission']);
                $issue = null;
                if ($publicationFields['issueId'] !== null) {
                    $issueInfo = $bridge->getIssueInfo($publicationFields['issueId']);
                    if ($issueInfo !== null && $issueInfo['published']) {
                        $issue = ['volume' => $issueInfo['volume'], 'number' => $issueInfo['number'], 'year' => $issueInfo['year']];
                    }
                }
                $publicUrl = $supportState === 'published' ? $bridge->getPublicSubmissionUrl($request, $ctx['submission']) : null;

                return PublicationStatusTool::handleVerified($relationship, $supportState, $publicationFields['doi'], $publicUrl, $issue, $actions);
            }
        );
        $registry->register(
            PaymentStatusTool::NAME,
            PaymentStatusTool::DESCRIPTION,
            PaymentStatusTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.payment.get_submission_status');

                $bridge = $this->runtimeContextBridge();
                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                if ($submissionId === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'submissionId is required.');
                }

                $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext($request));

                $ctx = $this->v2ResolveMcpSubmissionContext($bridge, $result, $submissionId, ['payment_status' => $feeInfo['enabled']]);
                $relationship = $ctx['relationship'];
                $decision = $ctx['decision'];
                $actions = $ctx['actions'];

                if (!$relationship || !$decision || !$decision->allows('submission.read_own_payment_status')) {
                    return PaymentStatusSerializer::unverified($result, $feeInfo, $actions);
                }

                $userId = $result->identity()->userId() ?? 0;
                $resolution = $this->v2ResolvePaymentObligations($bridge, $request, $ctx['submission'], $userId);
                $obligations = $resolution ? $resolution->obligations() : [];

                $airixObligation = $obligations[0] ?? null;
                if ($airixObligation !== null) {
                    $status = $airixObligation['status'];
                    $feeInfo = ['enabled' => true, 'amount' => $airixObligation['amount'], 'currency' => $airixObligation['currency']];
                } elseif ($resolution && $resolution->hasFailures()) {
                    $status = \APP\plugins\generic\chatwootIntegration\classes\v2\Provider\PaymentObligationStatus::UNKNOWN;
                } else {
                    $status = 'not_applicable';
                    if ($feeInfo['enabled']) {
                        $paid = $bridge->hasPaidPublicationFee($userId, $submissionId);
                        $status = $paid ? 'paid' : 'unpaid';
                    }
                }

                return PaymentStatusTool::handleVerified($relationship, $feeInfo, $status, $actions, $obligations);
            }
        );
        $registry->register(
            AccountDiagnosticsTool::NAME,
            AccountDiagnosticsTool::DESCRIPTION,
            AccountDiagnosticsTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.diagnostics.account');

                $scope = trim((string) ($arguments['scope'] ?? ''));
                if (!in_array($scope, AccountDiagnosticEngine::SCOPES, true)) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'scope must be one of: ' . implode(', ', AccountDiagnosticEngine::SCOPES));
                }

                $bridge = $this->runtimeContextBridge();
                $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
                    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
                    $result->assurance(),
                    $result->identity()
                ));
                $actions = $decision ? $bridge->availableActions($decision) : [];

                if (!$decision || !$decision->allows('account.diagnose_own')) {
                    return DiagnosticResultSerializer::unverified($result, $actions);
                }

                $accountFields = $bridge->getUserAccountFields($result->identity()->userId() ?? 0);
                $mailConfig = $scope === AccountDiagnosticEngine::SCOPE_MAIL_CONFIGURATION ? $bridge->getMailTransportConfiguration() : null;
                $diagnosis = AccountDiagnosticEngine::diagnose($scope, $accountFields['disabled'], $accountFields['dateValidated'], $mailConfig);

                return AccountDiagnosticsTool::handleVerified($diagnosis, $actions);
            }
        );
        $registry->register(
            SubmissionDiagnosticsTool::NAME,
            SubmissionDiagnosticsTool::DESCRIPTION,
            SubmissionDiagnosticsTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.diagnostics.submission');

                $scope = trim((string) ($arguments['scope'] ?? ''));
                if (!in_array($scope, SubmissionDiagnosticEngine::SCOPES, true)) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'scope must be one of: ' . implode(', ', SubmissionDiagnosticEngine::SCOPES));
                }

                $bridge = $this->runtimeContextBridge();
                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                if ($submissionId === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'submissionId is required.');
                }

                $ctx = $this->v2ResolveMcpSubmissionContext($bridge, $result, $submissionId);
                $relationship = $ctx['relationship'];
                $decision = $ctx['decision'];
                $actions = $ctx['actions'];
                $submission = $ctx['submission'];

                if (!$relationship || !$decision || !$decision->allows('submission.diagnose_own')) {
                    return DiagnosticResultSerializer::unverified($result, $actions);
                }

                $userId = $result->identity()->userId() ?? 0;
                $diagnosis = match ($scope) {
                    SubmissionDiagnosticEngine::SCOPE_SUBMISSION_ACCESS => SubmissionDiagnosticEngine::diagnoseSubmissionAccess($relationship->types()),

                    SubmissionDiagnosticEngine::SCOPE_SUBMISSION_PROGRESS => SubmissionDiagnosticEngine::diagnoseSubmissionProgress(
                        $this->supportStateForDiagnostics($bridge, $submission)
                    ),

                    SubmissionDiagnosticEngine::SCOPE_PUBLICATION => SubmissionDiagnosticEngine::diagnosePublication(
                        $this->supportStateForDiagnostics($bridge, $submission)
                    ),

                    SubmissionDiagnosticEngine::SCOPE_REQUIRED_ACTION => SubmissionDiagnosticEngine::diagnoseRequiredAction(array_values(array_unique(array_merge(
                        $relationship->has('author') ? RequiredActionMapper::forAuthor($this->supportStateForDiagnostics($bridge, $submission)) : [],
                        $relationship->has('reviewer') ? RequiredActionMapper::forReviewer($bridge->getReviewAssignmentStatuses($submissionId, $userId)) : []
                    )))),

                    SubmissionDiagnosticEngine::SCOPE_REVIEW_ACCESS => SubmissionDiagnosticEngine::diagnoseReviewAccess(
                        $relationship->has('reviewer'),
                        $relationship->has('reviewer') ? $bridge->getReviewAssignmentStatuses($submissionId, $userId) : []
                    ),

                    SubmissionDiagnosticEngine::SCOPE_PAYMENT => $this->diagnosePaymentForSubmission($bridge, $request, $result, $relationship, $submissionId, $userId, CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT),

                    SubmissionDiagnosticEngine::SCOPE_REQUIRED_FILES => SubmissionDiagnosticEngine::diagnoseRequiredFiles(
                        $bridge->getMissingRequiredSubmissionFileGenreNames($request->getContext(), $submission)
                    ),

                    SubmissionDiagnosticEngine::SCOPE_UPLOAD_LIMIT => (static function () use ($bridge): DiagnosticResult {
                        $limits = $bridge->getUploadLimits();
                        return SubmissionDiagnosticEngine::diagnoseUploadLimit($limits['uploadMaxFilesizeBytes'], $limits['postMaxSizeBytes']);
                    })(),

                    default => DiagnosticResult::unknown('UNKNOWN_SCOPE', 'This diagnostic scope is not recognized.'),
                };

                return SubmissionDiagnosticsTool::handleVerified($diagnosis, $actions);
            }
        );
        $registry->register(
            EscalateSupportTool::NAME,
            EscalateSupportTool::DESCRIPTION,
            EscalateSupportTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.support.escalate');

                $reason = trim((string) ($arguments['reason'] ?? ''));
                if ($reason === '') {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'reason is required.');
                }

                $bridge = $this->runtimeContextBridge();
                $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
                    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
                    $result->assurance(),
                    $result->identity()
                ));
                if (!$decision || !$decision->allows('support.escalate')) {
                    throw new McpHandlerError(McpErrorCode::UNAUTHORIZED, 'Escalation is not available.');
                }

                $submissionId = $this->v2PositiveInt($arguments['submissionId'] ?? null);
                $relationship = null;
                $supportState = null;
                $requiredActions = [];
                $publicationFacts = null;
                $paymentFacts = null;
                $diagnostic = null;

                if ($submissionId !== null && $result->verified()) {
                    $submission = $bridge->loadSubmission($submissionId);
                    if ($submission) {
                        $candidate = $bridge->resolveSubmissionRelationship($result->identity(), $submission);
                        if ($candidate && !$candidate->isEmpty()) {
                            $relationship = $candidate;
                            $resourceDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
                                CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
                                'v3',
                                $result->identity(),
                                $relationship
                            ));

                            if ($resourceDecision && $resourceDecision->allows('submission.read_own_support_status')) {
                                $supportState = $this->supportStateForDiagnostics($bridge, $submission);
                            }
                            if ($resourceDecision && $resourceDecision->allows('submission.read_own_required_actions') && $supportState !== null) {
                                $requiredActions = array_values(array_unique(array_merge(
                                    $relationship->has('author') ? RequiredActionMapper::forAuthor($supportState) : [],
                                    $relationship->has('reviewer') ? RequiredActionMapper::forReviewer($bridge->getReviewAssignmentStatuses($submissionId, $result->identity()->userId() ?? 0)) : []
                                )));
                                $diagnostic = SubmissionDiagnosticEngine::diagnoseRequiredAction($requiredActions);
                            }
                            if ($resourceDecision && $resourceDecision->allows('submission.read_own_publication_status') && $supportState !== null) {
                                $publicationFacts = ['status' => $supportState === 'published' || $supportState === 'scheduled_for_publication' ? $supportState : 'not_yet_published', 'doi' => null];
                                $publicationDoi = $bridge->getPublicationFields($submission)['doi'];
                                $publicationFacts['doi'] = ($supportState === 'published' || $supportState === 'scheduled_for_publication') ? $publicationDoi : null;
                            }
                            if ($resourceDecision && $resourceDecision->allows('submission.read_own_payment_status')) {
                                $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext($request));
                                $paymentStatus = 'not_applicable';
                                if ($feeInfo['enabled']) {
                                    $paid = $bridge->hasPaidPublicationFee($result->identity()->userId() ?? 0, $submissionId);
                                    $paymentStatus = $paid ? 'paid' : 'unpaid';
                                }
                                $paymentFacts = ['feeEnabled' => $feeInfo['enabled'], 'status' => $paymentStatus];
                            }
                        }
                    }
                }

                $summary = HandoffSummaryFormatter::build(
                    SupportIdentitySerializer::serialize($result),
                    $relationship,
                    $supportState,
                    $requiredActions,
                    $publicationFacts,
                    $paymentFacts,
                    $reason,
                    $diagnostic
                );

                $chatwootAccountId = trim((string) ($arguments['chatwootAccountId'] ?? ''));
                $chatwootConversationId = trim((string) ($arguments['chatwootConversationId'] ?? ''));
                $idempotencyKey = trim((string) ($arguments['idempotencyKey'] ?? ''));
                if ($idempotencyKey === '') {
                    $idempotencyKey = hash('sha256', $chatwootAccountId . ':' . $chatwootConversationId . ':' . $reason . ':' . ($submissionId ?? 0));
                }

                $noteCreated = false;
                $duplicate = false;
                $guard = new EscalationIdempotencyGuard();
                if (!$guard->claim($chatwootAccountId . ':' . $chatwootConversationId . ':' . $idempotencyKey)) {
                    $duplicate = true;
                } elseif ($chatwootAccountId !== '' && $chatwootConversationId !== '') {
                    try {
                        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
                        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
                        if ($baseUrl !== '' && $apiToken !== '') {
                            $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
                            $noteCreated = (bool) $chatwoot->createConversationNote($chatwootConversationId, HandoffSummaryFormatter::renderNoteText($summary));
                        }
                    } catch (\Throwable $e) {
                        $noteCreated = false;
                    }
                }

                return ['escalated' => true, 'noteCreated' => $noteCreated, 'duplicate' => $duplicate, 'summary' => $summary];
            }
        );
        $registry->register(
            SubmissionListTool::NAME,
            SubmissionListTool::DESCRIPTION,
            SubmissionListTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.submission.list_mine');

                $pagination = PaginationParams::parse($arguments['limit'] ?? null, $arguments['offset'] ?? null);
                if ($pagination === null) {
                    throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'limit/offset are invalid.');
                }

                if (!$result->verified()) {
                    return SubmissionListTool::handle($result);
                }

                $bridge = $this->runtimeContextBridge();
                $listDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
                    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
                    $result->assurance(),
                    $result->identity()
                ));
                if (!$listDecision || !$listDecision->allows('submission.list_own')) {
                    return SubmissionListTool::handle($result);
                }

                $candidateCap = 200;
                $candidates = $bridge->listCandidateSubmissions($result->identity()->contextId(), $result->identity()->userId() ?? 0, $candidateCap);

                $entries = [];
                $seenSubmissionIds = [];
                foreach ($candidates as $submission) {
                    $relationship = $bridge->resolveSubmissionRelationship($result->identity(), $submission);
                    if (!$relationship || $relationship->isEmpty()) {
                        continue;
                    }
                    if (!$relationship->has('author') && !$relationship->has('reviewer')) {
                        continue;
                    }
                    if (isset($seenSubmissionIds[$relationship->resourceId()])) {
                        continue;
                    }
                    $seenSubmissionIds[$relationship->resourceId()] = true;

                    $stateFields = $bridge->getSubmissionStateFields($submission);
                    $entries[] = [
                        'relationship' => $relationship,
                        'title' => $bridge->getSubmissionTitle($submission),
                        'supportState' => SupportStateMapper::map($stateFields['status'], $stateFields['stageId'], $stateFields['reviewRoundStatus'], $stateFields['submissionProgress']),
                        'actionRequired' => null,
                    ];
                }

                $total = count($entries);
                $page = array_slice($entries, $pagination->offset, $pagination->limit);
                $hasMore = ($pagination->offset + count($page)) < $total;

                return SubmissionListTool::handleVerified($result, $page, $pagination, $hasMore);
            }
        );
        $registry->register(
            CapabilitiesListTool::NAME,
            CapabilitiesListTool::DESCRIPTION,
            CapabilitiesListTool::inputSchema(),
            function (array $arguments) use ($identityResolver, $request, $contextId, $configuredMcpToken, $locale): array {
                $result = $this->v2ResolveMcpIdentity($arguments, $identityResolver, $request, $contextId, $configuredMcpToken, $locale, 'mcp.capabilities.list_available');

                $bridge = $this->runtimeContextBridge();
                $decision = $bridge->evaluateCapabilities(new CapabilityRequest(
                    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
                    $result->assurance(),
                    $result->identity()
                ));

                return CapabilitiesListTool::handle(
                    $result->verified(),
                    $result->assurance(),
                    $decision ? $bridge->availableActions($decision) : [],
                    $decision ? $bridge->disabledActions($decision) : []
                );
            }
        );

        $resourceRegistry = new McpResourceRegistry();
        $resourceRegistry->register(
            "ojs://journal/{$contextId}/support-profile",
            'Journal support profile',
            'Public journal.* support facts compiled by the Knowledge Compiler.',
            'application/json',
            fn (): array => JournalProfileTool::handle($compilation)
        );
        $resourceRegistry->register(
            "ojs://journal/{$contextId}/submission-guidelines",
            'Submission guidelines',
            'Public submission.* facts compiled by the Knowledge Compiler.',
            'application/json',
            fn (): array => SubmissionPolicyTool::handle($compilation)
        );
        $resourceRegistry->register(
            "ojs://journal/{$contextId}/fee-policy",
            'Fee policy',
            'Public fee.* facts compiled by the Knowledge Compiler.',
            'application/json',
            fn (): array => FeePolicyTool::handle($compilation)
        );

        $dispatcher = new McpDispatcher();
        $dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_LIST, fn (McpRequest $r): array => ['tools' => $registry->list()]);
        $dispatcher->registerHandler(McpProtocol::METHOD_TOOLS_CALL, function (McpRequest $r) use ($registry): array {
            $name = $r->params()['name'] ?? null;
            if (!is_string($name) || $name === '') {
                throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'params.name must be a non-empty string.');
            }
            $arguments = $r->params()['arguments'] ?? [];
            if (!is_array($arguments)) {
                throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'params.arguments must be an object when present.');
            }
            return ['content' => $registry->call($name, $arguments)];
        });
        $dispatcher->registerHandler(McpProtocol::METHOD_RESOURCES_LIST, fn (McpRequest $r): array => ['resources' => $resourceRegistry->list()]);
        $dispatcher->registerHandler(McpProtocol::METHOD_RESOURCES_READ, function (McpRequest $r) use ($resourceRegistry): array {
            $uri = $r->params()['uri'] ?? null;
            if (!is_string($uri) || $uri === '') {
                throw new McpHandlerError(McpErrorCode::INVALID_PARAMS, 'params.uri must be a non-empty string.');
            }
            return ['contents' => [$resourceRegistry->read($uri)]];
        });

        $this->v2McpRespond($dispatcher->dispatch($parsed));
    }

    private function v2McpRespond(McpResponse $response): void
    {
        header('Content-Type: application/json');
        echo json_encode($response->toArray());
        exit;
    }

    /**
     * Shared by every identity-dependent MCP tool: resolves identity
     * through the exact same SupportApiRequestResolver REST uses, reading
     * the Chatwoot conversation tuple from tool arguments instead of
     * getUserVar(). A resolver failure becomes a real McpHandlerError,
     * never silently swallowed.
     */
    private function v2ResolveMcpIdentity(
        array $arguments,
        SupportApiRequestResolver $resolver,
        $request,
        int $contextId,
        string $configuredMcpToken,
        string $locale,
        string $endpoint
    ): SupportApiRequestContext {
        $result = $resolver->resolve(
            $request,
            CorrelationId::fromRequestOrGenerate(),
            $contextId,
            $configuredMcpToken,
            trim((string) ($arguments['chatwootAccountId'] ?? '')),
            trim((string) ($arguments['chatwootContactId'] ?? '')),
            trim((string) ($arguments['chatwootConversationId'] ?? '')),
            $endpoint,
            $locale
        );
        if ($result instanceof SupportApiFailure) {
            throw McpSupportApiFailureMapper::toHandlerError($result);
        }
        return $result;
    }

    /**
     * Shared by every submission-scoped MCP tool — mirrors the exact same
     * loadSubmission -> resolveSubmissionRelationship -> evaluateCapabilities
     * sequence every submission-scoped REST endpoint already repeats.
     * Evaluated under CONSUMER_MCP_PUBLIC_SUPPORT (MCP-002), never the
     * Chatwoot Captain consumer plane, even though today's capability
     * catalog does not yet discriminate between the two.
     *
     * @return array{relationship:?ResourceRelationship,submission:?object,decision:?CapabilityDecision,actions:array<int,string>}
     */
    private function v2ResolveMcpSubmissionContext(RuntimeContextBridge $bridge, SupportApiRequestContext $result, int $submissionId, array $featureFlags = []): array
    {
        $relationship = null;
        $submission = null;
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
            CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
            $resourceAssurance,
            $result->identity(),
            $relationship,
            $featureFlags
        ));
        $actions = $decision ? $bridge->availableActions($decision) : [];

        return ['relationship' => $relationship, 'submission' => $submission, 'decision' => $decision, 'actions' => $actions];
    }

    /**
     * $context is accepted explicitly rather than derived from
     * $request->getContext() so this also works for a $request that has no
     * real HTTP-routed context — e.g. Application::get()->getRequest()
     * inside a scheduled task iterating every journal (see
     * CaptainSyncScheduledTask). Dispatcher::url() only ever uses $request
     * for base configuration, never for its context, so this is safe.
     */
    private function v2KnowledgePageUrl($request, $context, ?string $category): string
    {
        try {
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !$context || !method_exists($context, 'getPath')) {
                return '';
            }
            return (string) $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $context->getPath(), self::SUPPORT_KNOWLEDGE_PAGE, $category);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Idempotent Captain Document provisioning (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
     * §6, CaptainDocumentProvisioner). Driven daily, once per enabled
     * journal, by CaptainSyncScheduledTask (KNO-019). There is still no
     * admin settings UI (Phase 13) action to trigger it on demand — that
     * remains a separate, unbuilt manual-fallback trigger path.
     *
     * Requires `chatwootBaseUrl`/`chatwootApiAccessToken` (already used
     * elsewhere in this plugin) plus a new `chatwootCaptainAssistantId`
     * setting; returns null (never a fatal) when any is unset, or when
     * the Chatwoot API/Captain feature is unreachable — Captain is an
     * Enterprise-Edition-gated feature in self-hosted Chatwoot and must
     * degrade like any other optional integration.
     *
     * $context is accepted explicitly (rather than derived from
     * $request->getContext()) so this is callable both from an HTTP
     * request bound to one journal and from CaptainSyncScheduledTask,
     * which drives this once per enabled journal using the plugin's own
     * $request (Application::get()->getRequest() has no HTTP-routed
     * context in a scheduled task).
     */
    public function provisionCaptainKnowledgeDocument($request, $context): ?CaptainSyncResult
    {
        if (!$context || !method_exists($context, 'getId')) {
            return null;
        }
        $contextId = (int) $context->getId();

        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
        $assistantId = (int) $this->v2EffectiveSetting($contextId, 'chatwootCaptainAssistantId', 0);
        if ($baseUrl === '' || $apiToken === '' || $assistantId <= 0) {
            return null;
        }

        $knowledgeRootUrl = $this->v2KnowledgePageUrl($request, $context, null);
        if ($knowledgeRootUrl === '') {
            return null;
        }

        $locale = (string) Locale::getLocale();
        $compilation = $this->runtimeContextBridge()->compileKnowledge($context, $request, $contextId, $locale);
        if (!$compilation) {
            return null;
        }

        $journalName = method_exists($context, 'getLocalizedName') ? (string) $context->getLocalizedName() : 'Journal';

        try {
            $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
            $provisioner = new CaptainDocumentProvisioner($chatwoot, new DatabaseSupportKnowledgeSyncRepository());
            return $provisioner->provision($compilation, $assistantId, $journalName . ' Support Knowledge', $knowledgeRootUrl, time());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Idempotent Captain Custom Tool provisioning (CanonicalToolCatalog,
     * CaptainCustomToolProvisioner) — driven daily by
     * CaptainSyncScheduledTask, same as provisionCaptainKnowledgeDocument()
     * above. Requires `chatwootSupportApiToken` (the same Bearer token
     * ServiceTokenAuthenticator already verifies on every Support API
     * call) in addition to the base Chatwoot credentials.
     *
     * @see provisionCaptainKnowledgeDocument() for why $context is an
     * explicit param.
     *
     * @return array<string,CaptainSyncResult>|null
     */
    public function provisionCaptainCustomTools($request, $context): ?array
    {
        if (!$context || !method_exists($context, 'getId')) {
            return null;
        }
        $contextId = (int) $context->getId();

        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
        $serviceToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootSupportApiToken', ''));
        if ($baseUrl === '' || $apiToken === '' || $serviceToken === '') {
            return null;
        }

        $operationUrls = [];
        foreach (CanonicalToolCatalog::all() as $tool) {
            $url = $this->v2SupportGatewayUrl($request, $context, $tool->operation());
            if ($url !== '') {
                $operationUrls[$tool->operation()] = $url;
            }
        }
        if ($operationUrls === []) {
            return null;
        }

        $locale = (string) Locale::getLocale();

        try {
            $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
            $provisioner = new CaptainCustomToolProvisioner($chatwoot, new DatabaseSupportKnowledgeSyncRepository());
            return $provisioner->provisionAll($contextId, $locale, $operationUrls, $serviceToken, time());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Idempotent Captain Scenario provisioning (CanonicalScenarioCatalog,
     * CaptainScenarioProvisioner) — driven daily by
     * CaptainSyncScheduledTask, same as the Document/Custom Tool
     * provisioning entry points above. Depends on Custom Tool provisioning
     * having already run for this journal, since a scenario's instruction
     * can only reference a tool by its real assigned slug —
     * CaptainSyncScheduledTask runs tools before scenarios per journal for
     * exactly this reason.
     *
     * @see provisionCaptainKnowledgeDocument() for why $context is an
     * explicit param.
     *
     * @return array<string,CaptainSyncResult>|null
     */
    public function provisionCaptainScenarios($request, $context): ?array
    {
        if (!$context || !method_exists($context, 'getId')) {
            return null;
        }
        $contextId = (int) $context->getId();

        $baseUrl = $this->v2NormalizeBaseUrl((string) $this->v2EffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $apiToken = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootApiAccessToken', ''));
        $assistantId = (int) $this->v2EffectiveSetting($contextId, 'chatwootCaptainAssistantId', 0);
        if ($baseUrl === '' || $apiToken === '' || $assistantId <= 0) {
            return null;
        }

        $locale = (string) Locale::getLocale();

        try {
            $chatwoot = new ChatwootApiService($baseUrl, $apiToken);
            $provisioner = new CaptainScenarioProvisioner($chatwoot, new DatabaseSupportKnowledgeSyncRepository());
            return $provisioner->provisionAll($contextId, $locale, $assistantId, time());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ADM-003 (first slice): the manual "Sync/Repair Captain" admin
     * action, the on-demand fallback the three provisioning entry points
     * above always documented as missing. Deliberately zero new
     * provisioning logic — this only orchestrates the exact same three
     * already-built, already-tested entry points
     * (provisionCaptainKnowledgeDocument()/provisionCaptainCustomTools()/
     * provisionCaptainScenarios()), in the same document-then-tools-then-
     * scenarios order CaptainSyncScheduledTask already uses (a scenario's
     * instruction can only reference a tool by its real assigned slug),
     * scoped to the current journal only — never every journal
     * system-wide, unlike the scheduled task.
     *
     * Never deletes or overwrites an admin's own unrelated Captain
     * resources: that guarantee already lives entirely inside the three
     * provisioners themselves (ownership proven only by a local
     * CaptainSyncState record; an unmanaged remote resource is always a
     * reported conflict, never adopted/duplicated/overwritten) — this
     * method has no independent access to Chatwoot at all, so it cannot
     * weaken that guarantee even by accident.
     */
    public function syncCaptainResources($request): JSONMessage
    {
        $context = $request->getContext();
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        }

        try {
            $documentResult = $this->provisionCaptainKnowledgeDocument($request, $context);
            $toolResults = $this->provisionCaptainCustomTools($request, $context) ?? [];
            $scenarioResults = $this->provisionCaptainScenarios($request, $context) ?? [];

            return new JSONMessage(true, [
                'document' => $documentResult ? $documentResult->status() : 'unavailable',
                'tools' => $this->v2SummarizeCaptainSyncResults($toolResults),
                'scenarios' => $this->v2SummarizeCaptainSyncResults($scenarioResults),
            ]);
        } catch (\Throwable $e) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.captainSync.failed'));
        }
    }

    /**
     * @param array<string,CaptainSyncResult> $results
     *
     * @return array<string,int> CaptainSyncResult::STATUS_* => count
     */
    private function v2SummarizeCaptainSyncResults(array $results): array
    {
        $counts = [];
        foreach ($results as $result) {
            $counts[$result->status()] = ($counts[$result->status()] ?? 0) + 1;
        }
        return $counts;
    }

    /**
     * EVT-014's remaining admin-action half: a safe "Retry dead letters"
     * action, scoped to the current journal only. Resets up to 50 real
     * `failed` event-queue rows for this journal back to `pending` (a
     * fresh attempts budget, no delivery delay) via the real repository
     * method — never a bespoke query here. Returns only a count, never
     * any row's `attributes`/`last_error_code` content, so no raw
     * exception text or secret-bearing request body is ever exposed
     * through this action's own response.
     */
    public function retryDeadLetterEvents($request): JSONMessage
    {
        $context = $request->getContext();
        if (!$context || !method_exists($context, 'getId')) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        }

        try {
            $retried = (new DatabaseSupportEventQueueRepository())->retryDeadLetters((int) $context->getId(), 50);
            return new JSONMessage(true, ['retried' => $retried]);
        } catch (\Throwable $e) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.eventQueue.retryFailed'));
        }
    }

    /**
     * ADM-006: the admin "Send test email" diagnostic. Deliberately does
     * not reuse the verification challenge system at all (no
     * VerificationChallengeService, no pepper, no PIN/link) — a
     * dedicated `SupportMailTestMailable` sent through the same real
     * Mailable/Mail::send() transport every other OJS system email uses.
     * Proves only that OJS handed the message to the configured
     * transport (success = no exception thrown); never claims actual
     * inbox delivery, which this codebase has no visibility into.
     */
    public function sendSupportMailTest($request): JSONMessage
    {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        }
        if (!$user || !$user->getEmail()) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noUserForTest'));
        }

        try {
            $journalName = method_exists($context, 'getLocalizedName') ? (string) $context->getLocalizedName() : '';
            $subject = __('plugins.generic.chatwootIntegration.mailTest.subject', ['journalName' => $journalName]);
            $body = __('plugins.generic.chatwootIntegration.mailTest.body', ['journalName' => $journalName, 'date' => date('c')]);
            Mail::send(new SupportMailTestMailable($context, $user, $subject, $body));
            return new JSONMessage(true, __('plugins.generic.chatwootIntegration.mailTest.success'));
        } catch (\Throwable $e) {
            return new JSONMessage(false, __('plugins.generic.chatwootIntegration.mailTest.failed'));
        }
    }

    /**
     * Captain provisioning/drift health (CaptainProvisioningHealthService)
     * — a pure local-state read over the same `chatwoot_support_knowledge_sync`
     * records the three provisioners above write, never a Chatwoot API
     * call itself. Not yet wired to any route — same not-yet-built
     * admin-UI/cron caveat as `KnowledgeHealthService` (KNO-020): the
     * service/model exists and is fully tested, no UI consumes it yet.
     */
    public function captainProvisioningHealth($request): ?CaptainProvisioningHealthReport
    {
        $context = $request->getContext();
        if (!$context || !method_exists($context, 'getId')) {
            return null;
        }

        try {
            $service = new CaptainProvisioningHealthService(new DatabaseSupportKnowledgeSyncRepository());
            return $service->buildReport((int) $context->getId(), (string) Locale::getLocale());
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * ADM-002 (first slice): consolidates every already-built health
     * signal (Knowledge, Captain provisioning, payment providers,
     * credential configuration, mail transport, event-queue dead
     * letters) into one SupportGatewayHealthSummary for the admin
     * settings page. Every input gathered here is either a pure local
     * read (settings values, the local `chatwoot_support_knowledge_sync`
     * records Captain health already reads) or an OJS-internal read
     * (Knowledge Compiler, mail transport config) — never a live
     * Chatwoot/Captain API call, so this is always safe to build on
     * every admin page render. `SupportGatewayHealthAggregator` itself
     * is pure and does none of this gathering — see that class for the
     * deterministic overall-state rule.
     */
    public function supportGatewayHealthSummary($request): ?SupportGatewayHealthSummary
    {
        $context = $request->getContext();
        if (!$context || !method_exists($context, 'getId')) {
            return null;
        }

        try {
            $contextId = (int) $context->getId();
            $bridge = $this->runtimeContextBridge();

            $chatwootConfigured = $this->supportGatewayUsable($contextId);
            $supportApiConfigured = trim((string) $this->v2EffectiveSetting($contextId, 'chatwootSupportApiToken', '')) !== '';
            $mcpConfigured = trim((string) $this->v2EffectiveSetting($contextId, 'mcpServiceToken', '')) !== '';

            $mailDiagnosis = AccountDiagnosticEngine::diagnose(AccountDiagnosticEngine::SCOPE_MAIL_CONFIGURATION, null, null, $bridge->getMailTransportConfiguration());
            $verificationConfigured = $mailDiagnosis->code() === 'MAIL_CONFIGURED';

            $knowledgeHealth = $bridge->buildKnowledgeHealthReport($context, $request, $contextId, (string) Locale::getLocale());
            $captainHealth = $this->captainProvisioningHealth($request);

            $paymentProviderHealth = [];
            $airixProvider = $bridge->getAirixSubmissionFeeProvider($context);
            if ($airixProvider) {
                $paymentProviderHealth[$airixProvider->providerId()] = $airixProvider->health($context);
            }

            $deadLetterCount = 0;
            $pendingEventCount = 0;
            try {
                $queueRepo = new DatabaseSupportEventQueueRepository();
                $deadLetterCount = $queueRepo->countByStatus('failed');
                $pendingEventCount = $queueRepo->countByStatus('pending');
            } catch (\Throwable $e) {
                $deadLetterCount = 0;
                $pendingEventCount = 0;
            }

            return SupportGatewayHealthAggregator::build(
                $chatwootConfigured,
                $supportApiConfigured,
                $mcpConfigured,
                $verificationConfigured,
                $knowledgeHealth,
                $captainHealth,
                $paymentProviderHealth,
                $deadLetterCount,
                $pendingEventCount
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @see v2KnowledgePageUrl() for why $context is an explicit param. */
    private function v2SupportGatewayUrl($request, $context, string $operation): string
    {
        try {
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !$context || !method_exists($context, 'getPath')) {
                return '';
            }
            return (string) $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $context->getPath(), self::SUPPORT_GATEWAY_PAGE, $operation);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function v2RenderVerificationLinkPage(bool $verified): string
    {
        $heading = $verified ? 'Verification successful' : 'Verification link invalid or expired';
        $message = $verified
            ? 'You can return to your conversation.'
            : 'This verification link is no longer valid. Please request a new one from the conversation.';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</title></head>'
            . '<body><h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
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
     * Server-to-server endpoint for Chatwoot Captain: returns the actual
     * support DTO for exactly one submission (ojs_get_submission_support
     * in docs/v2/API_MCP_SPEC.md §7.5), gated on `submission.read_own_support_status`
     * (V3 + author/reviewer relationship). Establishes its own request-time
     * V3 assurance the same way submissionVerify() does — never trusts a
     * caller-supplied assurance claim, and never persists V3 onto the
     * conversation's support session.
     *
     * Deliberately narrow: normalized support state, one safe explanatory
     * sentence, and capability-derived available actions only. Required
     * actions (ojs_get_required_actions), publication detail
     * (ojs_get_publication_status), and milestone dates are separate,
     * not-yet-built endpoints — this one does not anticipate their shape.
     */
    public function supportSubmissionSupportRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'submissionSupport');
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
        $submission = null;
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

        if (!$relationship || !$decision || !$decision->allows('submission.read_own_support_status')) {
            // Fail safely into the same generic shape whether the submission
            // doesn't exist, belongs to another journal, has no relationship
            // to this identity, or the conversation never reached V2 — never
            // distinguishable from each other.
            SupportApiResponse::success(SubmissionSupportSerializer::unverified($result, $actions), $result->correlationId());
        }

        $stateFields = $bridge->getSubmissionStateFields($submission);
        $supportState = SupportStateMapper::map(
            $stateFields['status'],
            $stateFields['stageId'],
            $stateFields['reviewRoundStatus'],
            $stateFields['submissionProgress']
        );
        // STA-008: re-derived from the exact same fields the state itself
        // came from — never a separate, potentially-drifting judgment call.
        $stateConfidence = SupportStateMapper::confidence(
            $stateFields['status'],
            $stateFields['stageId'],
            $stateFields['reviewRoundStatus'],
            $stateFields['submissionProgress']
        );

        SupportApiResponse::success(SubmissionSupportSerializer::verified(
            $relationship,
            $bridge->getSubmissionTitle($submission),
            $supportState,
            SupportStateMapper::explain($supportState),
            $actions,
            $stateConfidence
        ), $result->correlationId());
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: returns the normalized
     * list of actions this verified user is currently expected to take for
     * exactly one submission (ojs_get_required_actions in
     * docs/v2/API_MCP_SPEC.md §7.6), gated on `submission.read_own_required_actions`
     * (V3 + author/reviewer relationship). Establishes its own request-time
     * V3 the same way submissionVerify()/submissionSupport() do.
     *
     * Deliberately narrow: only reports an action when directly provable
     * from evidence already read elsewhere in this codebase (submission
     * wizard/review-round state for authors, PKP's own computed
     * ReviewAssignment::getStatus() for reviewers) — see
     * RequiredActionMapper's own docblock for exactly what is and isn't
     * covered. An empty list is a correct, safe answer, not a placeholder.
     */
    public function supportRequiredActionsRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'requiredActions');
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
        $submission = null;
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

        if (!$relationship || !$decision || !$decision->allows('submission.read_own_required_actions')) {
            SupportApiResponse::success(RequiredActionsSerializer::unverified($result, $actions), $result->correlationId());
        }

        $requiredActions = [];
        if ($relationship->has('author')) {
            $stateFields = $bridge->getSubmissionStateFields($submission);
            $supportState = SupportStateMapper::map(
                $stateFields['status'],
                $stateFields['stageId'],
                $stateFields['reviewRoundStatus'],
                $stateFields['submissionProgress']
            );
            $requiredActions = array_merge($requiredActions, RequiredActionMapper::forAuthor($supportState));
        }
        if ($relationship->has('reviewer')) {
            $reviewStatuses = $bridge->getReviewAssignmentStatuses($submissionId, $result->identity()->userId() ?? 0);
            $requiredActions = array_merge($requiredActions, RequiredActionMapper::forReviewer($reviewStatuses));
        }
        $requiredActions = array_values(array_unique($requiredActions));

        SupportApiResponse::success(
            RequiredActionsSerializer::verified($relationship, $requiredActions, $actions),
            $result->correlationId()
        );
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: returns safe
     * publication/issue/DOI/public-URL information for exactly one
     * submission (ojs_get_publication_status in docs/v2/API_MCP_SPEC.md
     * §7.8), gated on `submission.read_own_publication_status` (V3 +
     * author/reviewer relationship). Establishes its own request-time V3
     * the same way the other submission-scoped endpoints do.
     *
     * Deliberately conservative: `doi`/`publicUrl`/`issue` are only ever
     * populated when the submission's own normalized support state is
     * exactly `published` or `scheduled_for_publication` (via the same
     * SupportStateMapper every other endpoint uses) — every other state
     * returns `status: 'not_yet_published'` with no other fields, since
     * this codebase has no evidence those identifiers even exist yet.
     * `publicUrl` is further restricted to `published` only:
     * `scheduled_for_publication` means the article is not yet visible to
     * the public, so a URL would point at nothing.
     */
    public function supportPublicationStatusRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'publicationStatus');
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
        $submission = null;
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

        if (!$relationship || !$decision || !$decision->allows('submission.read_own_publication_status')) {
            SupportApiResponse::success(PublicationStatusSerializer::unverified($result, $actions), $result->correlationId());
        }

        $stateFields = $bridge->getSubmissionStateFields($submission);
        $supportState = SupportStateMapper::map(
            $stateFields['status'],
            $stateFields['stageId'],
            $stateFields['reviewRoundStatus'],
            $stateFields['submissionProgress']
        );

        if ($supportState !== 'published' && $supportState !== 'scheduled_for_publication') {
            SupportApiResponse::success(
                PublicationStatusSerializer::verified($relationship, 'not_yet_published', null, null, null, $actions),
                $result->correlationId()
            );
        }

        $publicationFields = $bridge->getPublicationFields($submission);
        $issue = null;
        if ($publicationFields['issueId'] !== null) {
            $issueInfo = $bridge->getIssueInfo($publicationFields['issueId']);
            if ($issueInfo !== null && $issueInfo['published']) {
                $issue = [
                    'volume' => $issueInfo['volume'],
                    'number' => $issueInfo['number'],
                    'year' => $issueInfo['year'],
                ];
            }
        }
        $publicUrl = $supportState === 'published' ? $bridge->getPublicSubmissionUrl($request, $submission) : null;

        SupportApiResponse::success(
            PublicationStatusSerializer::verified($relationship, $supportState, $publicationFields['doi'], $publicUrl, $issue, $actions),
            $result->correlationId()
        );
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: returns public fee
     * facts (fee enabled, amount, currency) plus, when authorized, a
     * verified submission's paid/unpaid status (ojs_get_payment_status in
     * docs/v2/API_MCP_SPEC.md §7.7). Gated on
     * `submission.read_own_payment_status` (V3 + author relationship;
     * reviewers are not part of that capability's declared relationships).
     *
     * Unlike every other submission-scoped endpoint, the public fee facts
     * are returned regardless of verification — they describe the
     * journal's own configuration, not any specific user or submission,
     * and revealing them cannot leak anything about who is asking.
     *
     * `submission.read_own_payment_status` also requires the
     * `payment_support` journal policy (see CapabilityCatalog), which
     * defaults to false and has no admin toggle built yet — so the
     * submission-specific `status` branch is intentionally unreachable in
     * production until a future settings UI exists to opt a journal in.
     * That is a deliberate conservative default, not a bug: this endpoint
     * ships correctly wired to it rather than silently overriding it.
     *
     * `payment_status` itself is derived live from OJS's own
     * OJSPaymentManager (isConfigured() + publicationEnabled()) — never a
     * plugin setting of its own — so the feature flag always reflects
     * whatever the journal's real payment configuration currently is.
     */
    public function supportPaymentStatusRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'paymentStatus');
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

        $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext($request));

        $relationship = null;
        $submission = null;
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
            $relationship,
            ['payment_status' => $feeInfo['enabled']]
        ));
        $actions = $decision ? $bridge->availableActions($decision) : [];

        if (!$relationship || !$decision || !$decision->allows('submission.read_own_payment_status')) {
            SupportApiResponse::success(PaymentStatusSerializer::unverified($result, $feeInfo, $actions), $result->correlationId());
        }

        $userId = $result->identity()->userId() ?? 0;
        $resolution = $this->v2ResolvePaymentObligations($bridge, $request, $submission, $userId);
        $obligations = $resolution ? $resolution->obligations() : [];

        // An Airix fee producer, when present, is the authoritative fee for
        // this submission (docs/v2/AIRIX360_INTEGRATIONS.md §5.8: a
        // producer, not the native OJS publication fee, owns "what's owed
        // and why" once one is configured) — the native publication-fee
        // check below only runs as the fallback when no provider reported
        // anything.
        //
        // A provider that genuinely FAILED (as opposed to legitimately not
        // applying — see SupportProviderRegistry::NOT_APPLICABLE_HEALTH_STATES)
        // must report `unknown`, never silently fall back to the native
        // producer's state: that could report a completely different fee's
        // paid/unpaid status as if it were this journal's real answer
        // (docs/v2/PAYMENT_PORTFOLIO.md).
        $airixObligation = $obligations[0] ?? null;
        if ($airixObligation !== null) {
            $status = $airixObligation['status'];
            $feeInfo = [
                'enabled' => true,
                'amount' => $airixObligation['amount'],
                'currency' => $airixObligation['currency'],
            ];
        } elseif ($resolution && $resolution->hasFailures()) {
            $status = \APP\plugins\generic\chatwootIntegration\classes\v2\Provider\PaymentObligationStatus::UNKNOWN;
        } else {
            $status = 'not_applicable';
            if ($feeInfo['enabled']) {
                $paid = $bridge->hasPaidPublicationFee($userId, $submissionId);
                $status = $paid ? 'paid' : 'unpaid';
            }
        }

        SupportApiResponse::success(
            PaymentStatusSerializer::verified($relationship, $feeInfo, $status, $actions, $obligations),
            $result->correlationId()
        );
    }

    /**
     * Resolves every registered payment provider's obligation for this
     * submission (docs/v2/AIRIX360_TASKLIST.md PTF/APS). Returns null when
     * $submission is null (unverified caller) or no optional provider is
     * installed/enabled/compatible at all — the native OJS
     * publication-fee path is unaffected either way. A non-null,
     * zero-obligation result can still carry failures (see
     * ObligationResolution::hasFailures()) — that is the "provider
     * exists but broke" case, distinct from "no provider exists."
     */
    private function v2ResolvePaymentObligations($bridge, $request, $submission, int $userId): ?\APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ObligationResolution
    {
        if (!$submission) {
            return null;
        }

        $context = $bridge->getContext($request);
        $airixProvider = $bridge->getAirixSubmissionFeeProvider($context);
        if (!$airixProvider) {
            return null;
        }

        $registry = new \APP\plugins\generic\chatwootIntegration\classes\v2\Provider\SupportProviderRegistry();
        $registry->registerPaymentProvider($airixProvider);
        return $registry->resolveObligations($context, $submission, $userId);
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: deterministic
     * submission-flow diagnostics (ojs_diagnose_submission in
     * docs/v2/API_MCP_SPEC.md §7.10), gated on `submission.diagnose_own`
     * (V3 + author/reviewer relationship). Establishes its own
     * request-time V3 the same way the other submission-scoped endpoints
     * do.
     *
     * Deliberately does not create a second workflow interpreter — every
     * scope is a thin wrapper over the existing domain services
     * (SubmissionRelationshipResolver, SupportStateMapper,
     * RequiredActionMapper, publication/payment fields) this codebase
     * already built for the dedicated endpoints. The `payment` scope in
     * particular independently re-evaluates `submission.read_own_payment_status`
     * exactly like the dedicated payment endpoint does, so this can never
     * become a backdoor that reveals more than that endpoint would in the
     * current configuration (the `payment_support` policy still defaults
     * off, with no admin toggle built yet).
     */
    public function supportSubmissionDiagnosticsRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'submissionDiagnostics');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $scope = trim((string) $request->getUserVar('scope'));
        if (!in_array($scope, SubmissionDiagnosticEngine::SCOPES, true)) {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'scope must be one of: ' . implode(', ', SubmissionDiagnosticEngine::SCOPES),
                $result->correlationId(),
                400
            );
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
        $submission = null;
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

        if (!$relationship || !$decision || !$decision->allows('submission.diagnose_own')) {
            SupportApiResponse::success(DiagnosticResultSerializer::unverified($result, $actions), $result->correlationId());
        }

        $userId = $result->identity()->userId() ?? 0;
        $diagnosis = match ($scope) {
            SubmissionDiagnosticEngine::SCOPE_SUBMISSION_ACCESS => SubmissionDiagnosticEngine::diagnoseSubmissionAccess($relationship->types()),

            SubmissionDiagnosticEngine::SCOPE_SUBMISSION_PROGRESS => SubmissionDiagnosticEngine::diagnoseSubmissionProgress(
                $this->supportStateForDiagnostics($bridge, $submission)
            ),

            SubmissionDiagnosticEngine::SCOPE_PUBLICATION => SubmissionDiagnosticEngine::diagnosePublication(
                $this->supportStateForDiagnostics($bridge, $submission)
            ),

            SubmissionDiagnosticEngine::SCOPE_REQUIRED_ACTION => SubmissionDiagnosticEngine::diagnoseRequiredAction(array_values(array_unique(array_merge(
                $relationship->has('author') ? RequiredActionMapper::forAuthor($this->supportStateForDiagnostics($bridge, $submission)) : [],
                $relationship->has('reviewer') ? RequiredActionMapper::forReviewer($bridge->getReviewAssignmentStatuses($submissionId, $userId)) : []
            )))),

            SubmissionDiagnosticEngine::SCOPE_REVIEW_ACCESS => SubmissionDiagnosticEngine::diagnoseReviewAccess(
                $relationship->has('reviewer'),
                $relationship->has('reviewer') ? $bridge->getReviewAssignmentStatuses($submissionId, $userId) : []
            ),

            SubmissionDiagnosticEngine::SCOPE_PAYMENT => $this->diagnosePaymentForSubmission($bridge, $request, $result, $relationship, $submissionId, $userId),

            SubmissionDiagnosticEngine::SCOPE_REQUIRED_FILES => SubmissionDiagnosticEngine::diagnoseRequiredFiles(
                $bridge->getMissingRequiredSubmissionFileGenreNames($request->getContext(), $submission)
            ),

            SubmissionDiagnosticEngine::SCOPE_UPLOAD_LIMIT => (static function () use ($bridge): DiagnosticResult {
                $limits = $bridge->getUploadLimits();
                return SubmissionDiagnosticEngine::diagnoseUploadLimit($limits['uploadMaxFilesizeBytes'], $limits['postMaxSizeBytes']);
            })(),

            default => DiagnosticResult::unknown('UNKNOWN_SCOPE', 'This diagnostic scope is not recognized.'),
        };

        SupportApiResponse::success(DiagnosticResultSerializer::verified($diagnosis, $actions), $result->correlationId());
    }

    private function supportStateForDiagnostics($bridge, $submission): string
    {
        $stateFields = $bridge->getSubmissionStateFields($submission);
        return SupportStateMapper::map(
            $stateFields['status'],
            $stateFields['stageId'],
            $stateFields['reviewRoundStatus'],
            $stateFields['submissionProgress']
        );
    }

    /**
     * Independently re-evaluates submission.read_own_payment_status exactly
     * like supportPaymentStatusRequest() does — this must never grant more
     * than the dedicated endpoint would for the same identity/submission.
     */
    private function diagnosePaymentForSubmission($bridge, $request, $result, $relationship, int $submissionId, int $userId, string $consumerPlane = CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC): DiagnosticResult
    {
        $feeInfo = $bridge->getPaymentFeeInfo($bridge->getContext($request));
        $paymentDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            $consumerPlane,
            'v3',
            $result->identity(),
            $relationship,
            ['payment_status' => $feeInfo['enabled']]
        ));
        $paymentAllowed = $paymentDecision && $paymentDecision->allows('submission.read_own_payment_status');

        $paid = null;
        if ($paymentAllowed && $feeInfo['enabled']) {
            $paid = $bridge->hasPaidPublicationFee($userId, $submissionId);
        }

        return SubmissionDiagnosticEngine::diagnosePayment($paymentAllowed, $feeInfo['enabled'], $paid);
    }

    /**
     * Server-to-server endpoint for Chatwoot Captain: lets a verified V2
     * identity discover which submissions they have an actual author or
     * reviewer relationship to, so Captain never has to guess a submission
     * ID or ask the user for a manuscript number.
     *
     * A broad, safe OJS-native "any stage/review assignment" query supplies
     * only *candidates* (see RuntimeContextBridge::listCandidateSubmissions);
     * candidate membership is never treated as authorization. Every
     * candidate is independently re-checked through the same resource
     * relationship resolver submissionVerify() uses, and only
     * author/reviewer results survive — editorial-only relationships are
     * excluded from this baseline. Listing never upgrades the conversation
     * past its existing V2 identity; each submission's own request-time V3
     * assurance still has to be established separately via
     * submissionVerify() before any submission-specific detail is returned.
     */
    public function supportSubmissionListRequest($request): void
    {
        $result = $this->resolveSupportApiRequest($request, 'submissions');
        if ($result instanceof SupportApiFailure) {
            SupportApiResponse::error($result->code, $result->message, $result->correlationId, $result->httpStatus);
        }

        $pagination = PaginationParams::parse(
            $request->getUserVar('limit'),
            $request->getUserVar('offset')
        );
        if ($pagination === null) {
            SupportApiResponse::error(
                SupportApiErrorCode::VALIDATION_ERROR,
                'limit/offset are invalid.',
                $result->correlationId(),
                400
            );
        }

        if (!$result->verified()) {
            SupportApiResponse::success(SubmissionListSerializer::unverified($result), $result->correlationId());
        }

        $bridge = $this->runtimeContextBridge();
        $listDecision = $bridge->evaluateCapabilities(new CapabilityRequest(
            CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
            $result->assurance(),
            $result->identity()
        ));
        if (!$listDecision || !$listDecision->allows('submission.list_own')) {
            // Fail safely: same shape as an empty result, not a distinct
            // error — a denied capability must not be distinguishable from
            // "verified, but genuinely has nothing to list".
            SupportApiResponse::success(SubmissionListSerializer::unverified($result), $result->correlationId());
        }

        // ponytail: fixed candidate cap rather than true DB-level pagination,
        // because relationship filtering happens *after* the OJS query and
        // can legitimately drop rows (e.g. editorial-only candidates), so
        // DB-level limit/offset cannot be trusted to line up with the final
        // authorized list. Upgrade to a persisted relationship index if a
        // journal ever has more than this many stage/review assignments for
        // one user.
        $candidateCap = 200;
        $candidates = $bridge->listCandidateSubmissions($result->identity()->contextId(), $result->identity()->userId() ?? 0, $candidateCap);

        $entries = [];
        $seenSubmissionIds = [];
        foreach ($candidates as $submission) {
            $relationship = $bridge->resolveSubmissionRelationship($result->identity(), $submission);
            if (!$relationship || $relationship->isEmpty()) {
                continue;
            }
            if (!$relationship->has('author') && !$relationship->has('reviewer')) {
                continue; // editorial-only relationships are out of scope for this baseline
            }
            // Defense in depth: OJS's own candidate query should already
            // return distinct submissions, but never trust that alone.
            if (isset($seenSubmissionIds[$relationship->resourceId()])) {
                continue;
            }
            $seenSubmissionIds[$relationship->resourceId()] = true;

            $stateFields = $bridge->getSubmissionStateFields($submission);
            $entries[] = [
                'relationship' => $relationship,
                'title' => $bridge->getSubmissionTitle($submission),
                'supportState' => SupportStateMapper::map($stateFields['status'], $stateFields['stageId'], $stateFields['reviewRoundStatus'], $stateFields['submissionProgress']),
                // Unknown-safe by design: this slice has no reliable, safe
                // way to prove an action is (or isn't) required from
                // status/stageId alone. Returning false would be a guess.
                'actionRequired' => null,
            ];
        }

        $total = count($entries);
        $page = array_slice($entries, $pagination->offset, $pagination->limit);
        $hasMore = ($pagination->offset + count($page)) < $total;

        SupportApiResponse::success(
            SubmissionListSerializer::verified($result, $page, $pagination, $hasMore),
            $result->correlationId()
        );
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
        JsonRequestBodyParser::mergeIntoPostOnce();

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

            $result = $resolver->resolve(
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

            // API-006: only checked once the request is authenticated, so an
            // unauthenticated caller can never use field names as a probing
            // oracle.
            if ($result instanceof SupportApiRequestContext) {
                $unknownField = SupportApiFieldAllowlist::firstUnknownField($request, $endpoint);
                if ($unknownField !== null) {
                    return new SupportApiFailure(SupportApiErrorCode::VALIDATION_ERROR, "Unexpected field: {$unknownField}.", 400, $result->correlationId());
                }
            }

            return $result;
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

    /**
     * IDN-019: audits the external verification lifecycle (request/confirm
     * outcomes) through the same persisted sink the Support API allow/deny
     * decisions already use (AUD-001, `DatabaseSupportApiAuditLogger`).
     * Never logs a PIN, link token, or claimed email — only the same
     * privacy-safe reason codes `ChallengeAttemptOutcome::status()` already
     * exposes for exactly this purpose. Server-side only: this never
     * changes what the caller-facing response looks like, preserving
     * anti-enumeration (IDN-015).
     */
    private function v2AuditVerificationEvent(string $endpoint, int $contextId, string $decision, string $reason, string $correlationId): void
    {
        (new DatabaseSupportApiAuditLogger())->record([
            'correlationId' => $correlationId,
            'endpoint' => $endpoint,
            'contextId' => $contextId,
            'decision' => $decision,
            'reason' => $reason,
        ]);
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

    /**
     * Auto-generated once per journal, stored via the plugin's own
     * settings (a different table from the verification challenge table
     * itself) — used as the HMAC key for PIN hashing (see
     * VerificationSecretHasher). Never returned in any API response.
     */
    private function v2VerificationPepper(int $contextId): string
    {
        $pepper = trim((string) $this->getSetting($contextId, 'chatwootVerificationPepper'));
        if ($pepper === '') {
            $pepper = bin2hex(random_bytes(32));
            $this->updateSetting($contextId, 'chatwootVerificationPepper', $pepper, 'string');
        }
        return $pepper;
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
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }
}
