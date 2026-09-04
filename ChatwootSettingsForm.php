<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliverySettingsResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Event\SupportEventType;
use APP\plugins\generic\chatwootIntegration\classes\v2\Health\OverviewCardStates;
use APP\plugins\generic\chatwootIntegration\classes\v2\Mcp\Tool\McpToolCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\IntegrationCatalog;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailTemplateKeys;
use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailTemplateService;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SecretFieldMasking;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class ChatwootSettingsForm extends Form
{
    /**
     * UX-024: sourced from the canonical SettingsRegistry (a class
     * const can't call a static method, so this is a method, not a
     * const). Secret fields the settings form must mask: never
     * rendered back in full once saved, never overwritten by
     * resubmitting the mask unchanged. See SecretFieldMasking.
     */
    private static function secretKeys(): array
    {
        return SettingsRegistry::secretKeys();
    }

    /**
     * Positive audience model (owner directive 2026-09-04): maps each
     * "who can see the widget" audience key to the legacy negative
     * setting it inverts. Exactly the same 8 real Role::ROLE_ID_
     * values (plus guests) SettingsRegistry's hideForRole_ and
     * hideForGuests keys already cover — see SettingsRegistry's own
     * note on why no new setting key was added.
     */
    private const AUDIENCE_ROLE_KEYS = [
        'guest' => 'hideForGuests',
        'author' => 'hideForRole_65536',
        'reviewer' => 'hideForRole_4096',
        'reader' => 'hideForRole_1048576',
        'manager' => 'hideForRole_16',
        'subEditor' => 'hideForRole_17',
        'assistant' => 'hideForRole_4097',
        'siteAdmin' => 'hideForRole_1',
    ];

    private static function isChecked($rawValue): bool
    {
        return in_array($rawValue, [true, 1, '1', 'true'], true);
    }

    /**
     * Automation/Event Bridge matrix (owner directive 2026-09-04, item
     * E): one row per real SupportEventType. Maps each row's slug (used
     * for its enabledKey/actionKey form field names) to the real
     * SupportEventType constant and the real Automation "Enabled"
     * setting for that type (see ChatwootIntegrationV2Plugin's
     * EVENT_TYPE_ENABLED_SETTING — the two lists must name the same
     * event types, checked by tests/v2/settings-automation-event-matrix.php).
     */
    private const EVENT_MATRIX_ROWS = [
        'submissionCreated' => [SupportEventType::SUBMISSION_CREATED, 'eventSubmissionCreated'],
        'reviewSubmitted' => [SupportEventType::SUBMISSION_REVIEW_SUBMITTED, 'eventReviewSubmitted'],
        'revisionRequested' => [SupportEventType::SUBMISSION_REVISION_REQUESTED, 'eventRevisionRequested'],
        'accepted' => [SupportEventType::SUBMISSION_ACCEPTED, 'eventAccepted'],
        'rejected' => [SupportEventType::SUBMISSION_REJECTED, 'eventRejected'],
        'publicationScheduled' => [SupportEventType::PUBLICATION_SCHEDULED, 'eventPublicationScheduled'],
        'publicationPublished' => [SupportEventType::PUBLICATION_PUBLISHED, 'eventPublicationPublished'],
        'decisionRecorded' => [SupportEventType::SUBMISSION_DECISION_RECORDED, 'eventDecisionRecorded'],
    ];

    public function __construct(private ChatwootIntegrationPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $contextId = $this->contextId;
        $plugin = $this->plugin;

        // UX-024: SettingsRegistry::keys() already includes every
        // hideForRole_* key (verified: exactly the same 7 real Role::ROLE_ID_*
        // values this form used to loop over separately) — no separate
        // role loop needed.
        $secretKeys = self::secretKeys();
        foreach (SettingsRegistry::keys() as $key) {
            $value = (string) $plugin->getSetting($contextId, $key);
            $this->setData($key, in_array($key, $secretKeys, true) ? SecretFieldMasking::displayValue($value) : $value);
        }

        // Positive audience model: derive "allowed to see the widget"
        // display checkboxes from the existing negative hideFor* values
        // so a pre-existing install's effective audience renders
        // correctly the first time this loads, with no migration step.
        foreach (self::AUDIENCE_ROLE_KEYS as $audienceKey => $hideKey) {
            $this->setData('audienceAllow_' . $audienceKey, !self::isChecked($plugin->getSetting($contextId, $hideKey)));
        }

        // Automation/Event Bridge matrix: decode the existing raw
        // eventDeliveryPerEventOverridesJson value (Advanced-only from
        // now on, but still the real storage format — see execute())
        // into one Action select per row. A row with no override shows
        // "Use default".
        $overridesJson = (string) $plugin->getSetting($contextId, 'eventDeliveryPerEventOverridesJson');
        $consentGiven = self::isChecked($plugin->getSetting($contextId, 'eventDeliveryCustomerMessageConsent'));
        $decodedOverrides = EventDeliverySettingsResolver::parsePerEventOverrides($overridesJson, $consentGiven);
        foreach (self::EVENT_MATRIX_ROWS as $rowKey => [$eventType, ]) {
            $this->setData('eventAction_' . $rowKey, $decodedOverrides[$eventType] ?? '');
        }

        parent::initData();
    }

    public function readInputData(): void
    {
        // UX-024: SettingsRegistry::keys() already includes every
        // hideForRole_* key — see initData()'s note.
        $this->readUserVars(SettingsRegistry::keys());
        $this->readUserVars(array_map(fn (string $audienceKey): string => 'audienceAllow_' . $audienceKey, array_keys(self::AUDIENCE_ROLE_KEYS)));
        $this->readUserVars(array_map(fn (string $rowKey): string => 'eventAction_' . $rowKey, array_keys(self::EVENT_MATRIX_ROWS)));
        $this->addCheck(new FormValidator($this, 'chatwootBaseUrl', 'required', 'plugins.generic.chatwootIntegration.settings.chatwootBaseUrlRequired'));
        $this->addCheck(new FormValidator($this, 'chatwootWebsiteToken', 'required', 'plugins.generic.chatwootIntegration.settings.chatwootWebsiteTokenRequired'));
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        $router = $request->getRouter();

        // Legacy fallback only (owner directive item J) — eventDeliveryGlobalMode's
        // own "(use this journal's existing legacy sync behavior)" option is
        // what actually reads this; moved to Advanced, no longer part of
        // the normal Automation workflow.
        $templateMgr->assign('eventSyncModeOptions', [
            'note' => __('plugins.generic.chatwootIntegration.settings.eventSyncMode.note'),
            'open_update' => __('plugins.generic.chatwootIntegration.settings.eventSyncMode.openUpdate'),
        ]);
        $templateMgr->assign('lazyLoadTriggerOptions', [
            'idle' => __('plugins.generic.chatwootIntegration.settings.lazyLoadTrigger.idle'),
            'interaction' => __('plugins.generic.chatwootIntegration.settings.lazyLoadTrigger.interaction'),
        ]);
        // Widget tab console (owner directive 2026-09-04): every option
        // here is a real window.chatwootSettings value the deployed
        // Chatwoot SDK actually reads (verified against the real bundle
        // at support.airixmedia.com/packs/js/sdk.js) — never invented.
        $templateMgr->assign('widgetPositionOptions', [
            'right' => __('plugins.generic.chatwootIntegration.settings.widgetPosition.right'),
            'left' => __('plugins.generic.chatwootIntegration.settings.widgetPosition.left'),
        ]);
        $templateMgr->assign('widgetLauncherStyleOptions', [
            'standard' => __('plugins.generic.chatwootIntegration.settings.widgetLauncherStyle.standard'),
            'expanded_bubble' => __('plugins.generic.chatwootIntegration.settings.widgetLauncherStyle.expandedBubble'),
        ]);
        $templateMgr->assign('widgetLanguageModeOptions', [
            'match_ojs' => __('plugins.generic.chatwootIntegration.settings.widgetLanguageMode.matchOjs'),
            'browser' => __('plugins.generic.chatwootIntegration.settings.widgetLanguageMode.browser'),
            'fixed' => __('plugins.generic.chatwootIntegration.settings.widgetLanguageMode.fixed'),
        ]);
        $templateMgr->assign('widgetThemeOptions', [
            'auto' => __('plugins.generic.chatwootIntegration.settings.widgetTheme.auto'),
            'light' => __('plugins.generic.chatwootIntegration.settings.widgetTheme.light'),
            'dark' => __('plugins.generic.chatwootIntegration.settings.widgetTheme.dark'),
        ]);

        // Positive audience model (owner directive 2026-09-04, item D):
        // human labels for the live "effective audience" summary — see
        // AUDIENCE_ROLE_KEYS and settingsForm.tpl's cwUpdateEffectiveAudience().
        $templateMgr->assign('audienceLabelGuest', __('plugins.generic.chatwootIntegration.settings.audience.guest'));
        $templateMgr->assign('audienceLabelAuthor', __('plugins.generic.chatwootIntegration.settings.audience.author'));
        $templateMgr->assign('audienceLabelReviewer', __('plugins.generic.chatwootIntegration.settings.audience.reviewer'));
        $templateMgr->assign('audienceLabelReader', __('plugins.generic.chatwootIntegration.settings.audience.reader'));
        $templateMgr->assign('audienceLabelManager', __('plugins.generic.chatwootIntegration.settings.audience.manager'));
        $templateMgr->assign('audienceLabelSubEditor', __('plugins.generic.chatwootIntegration.settings.audience.subEditor'));
        $templateMgr->assign('audienceLabelAssistant', __('plugins.generic.chatwootIntegration.settings.audience.assistant'));
        $templateMgr->assign('audienceLabelSiteAdmin', __('plugins.generic.chatwootIntegration.settings.audience.siteAdmin'));
        $templateMgr->assign('audienceNoOneLabel', __('plugins.generic.chatwootIntegration.settings.audience.noOne'));
        $templateMgr->assign('audienceEffectivePrefix', __('plugins.generic.chatwootIntegration.settings.audience.effectivePrefix'));

        $templateMgr->assign('eventDeliveryGlobalModeOptions', [
            '' => __('plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.useLegacy'),
            EventDeliveryMode::PRIVATE_NOTE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.privateNote'),
            EventDeliveryMode::OPEN_UPDATE_CONVERSATION => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.openUpdateConversation'),
            EventDeliveryMode::UPDATE_CONTEXT => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.updateContext'),
            EventDeliveryMode::AUDIT_ONLY => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.auditOnly'),
            EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.optInCustomerMessage'),
        ]);

        // Automation/Event Bridge matrix (owner directive 2026-09-04,
        // item E): per-row Action options — same real EventDeliveryMode
        // values as the global default, but "" reads as "use the default
        // action" for a row rather than eventDeliveryGlobalModeOptions'
        // journal-wide "use legacy eventSyncMode" meaning.
        $templateMgr->assign('eventActionOptions', [
            '' => __('plugins.generic.chatwootIntegration.settings.eventAction.useDefault'),
            EventDeliveryMode::PRIVATE_NOTE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.privateNote'),
            EventDeliveryMode::OPEN_UPDATE_CONVERSATION => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.openUpdateConversation'),
            EventDeliveryMode::UPDATE_CONTEXT => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.updateContext'),
            EventDeliveryMode::AUDIT_ONLY => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.auditOnly'),
            EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.optInCustomerMessage'),
        ]);
        $templateMgr->assign('eventMatrixRows', array_map(
            fn (string $rowKey): array => [
                'rowKey' => $rowKey,
                'label' => __('plugins.generic.chatwootIntegration.settings.eventMatrix.' . $rowKey),
                'currentAction' => (string) $this->getData('eventAction_' . $rowKey),
            ],
            array_keys(self::EVENT_MATRIX_ROWS)
        ));

        $params = ['plugin' => $this->plugin->getName(), 'category' => 'generic'];
        $templateMgr->assign('healthCheckUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'healthCheck'])));
        $templateMgr->assign('testMessageUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'testMessage'])));
        $templateMgr->assign('exportSettingsUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'exportSettings'])));
        $templateMgr->assign('importSettingsUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'importSettings'])));
        $templateMgr->assign('saveGlobalProfileUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'saveGlobalProfile'])));
        $templateMgr->assign('applyGlobalProfileUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'applyGlobalProfile'])));
        $templateMgr->assign('syncCaptainResourcesUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'syncCaptainResources'])));
        $templateMgr->assign('retryDeadLetterEventsUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'retryDeadLetterEvents'])));
        $templateMgr->assign('sendSupportMailTestUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'sendSupportMailTest'])));
        $templateMgr->assign('discoverChatwootResourcesUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'discoverChatwootResources'])));

        $context = $request->getContext();
        $mcpEndpointUrl = $context
            ? $request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $context->getPath(), 'ojsMcpGateway')
            : '';
        $templateMgr->assign('mcpEndpointUrl', $mcpEndpointUrl);
        $templateMgr->assign('mcpProtocolRevision', '2026-07-28');

        // API & MCP tab (owner directive 2026-09-04, item H): real
        // configured-state + generate/rotate URLs for both plugin-owned
        // service credentials, and a real tool-catalog summary sourced
        // from McpToolCatalog (never hand-copied, see its own drift-guard
        // test) so this can never silently list stale/wrong tool names.
        $templateMgr->assign('generateServiceCredentialUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'generateServiceCredential'])));
        $templateMgr->assign('supportApiTokenConfigured', trim((string) $this->getData('chatwootSupportApiToken')) !== '');
        $templateMgr->assign('mcpServiceTokenConfigured', trim((string) $this->getData('mcpServiceToken')) !== '');
        $templateMgr->assign('mcpToolSummaries', McpToolCatalog::summaries());
        $templateMgr->assign('mcpToolCount', McpToolCatalog::count());
        $templateMgr->assign('apiMcpGeneratedLabel', __('plugins.generic.chatwootIntegration.settings.apiMcp.generatedLabel'));

        // Owner browser review 2026-09-04 finding #3: the JS success
        // handler for a fresh discovery needs these same two localized
        // strings to refresh the visible "Last verified"/"Connected"
        // captions immediately, not only on the next full page load.
        $templateMgr->assign('discoverConnectedPrefixJs', __('plugins.generic.chatwootIntegration.settings.discover.connectedAccount'));
        $templateMgr->assign('discoverLastVerifiedPrefixJs', __('plugins.generic.chatwootIntegration.settings.discover.lastVerifiedPrefix'));
        $templateMgr->assign('apiMcpRotateLabel', __('plugins.generic.chatwootIntegration.settings.apiMcp.rotate'));

        // Integrations tab (owner directive 2026-09-04, item I): real
        // installed/enabled state for real, verified sibling Airix/OJS
        // plugins only — see IntegrationCatalog's own note on why this
        // list is fixed and narrow, never speculative.
        $templateMgr->assign('integrationEntries', IntegrationCatalog::resolve($context ? (int) $context->getId() : 0));
        $templateMgr->assign('pluginsPageUrl', $context
            ? $request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $context->getPath(), 'management', 'settings', ['website'], ['anchor' => 'plugins'])
            : '');

        // Verification tab (owner directive 2026-09-04, item G / HAR-014
        // remainder): real EmailTemplate customization state — never
        // claims "healthy"/"verified", only whether an admin has
        // customized the seeded default yet. See
        // VerificationEmailTemplateService::isCustomized().
        $verificationContextId = $context ? (int) $context->getId() : 0;
        $templateMgr->assign('verificationPinTemplateExists', VerificationEmailTemplateService::isCustomized($verificationContextId, VerificationEmailTemplateKeys::PIN));
        $templateMgr->assign('verificationLinkTemplateExists', VerificationEmailTemplateService::isCustomized($verificationContextId, VerificationEmailTemplateKeys::LINK));
        $templateMgr->assign('manageEmailTemplatesUrl', $context
            ? $request->getDispatcher()->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $context->getPath(), 'management', 'settings', ['workflow'], ['anchor' => 'emails'])
            : '');

        $healthSummary = method_exists($this->plugin, 'supportGatewayHealthSummary') ? $this->plugin->supportGatewayHealthSummary($request) : null;
        $healthArray = $healthSummary?->toArray();
        $templateMgr->assign('supportGatewayHealth', $healthArray);

        // Overview dashboard (owner directive 2026-09-04, item F): real
        // per-module states, never conflating configured with healthy or
        // optional/off with degraded — see OverviewCardStates's own note.
        $overviewCards = $healthArray !== null
            ? array_map(
                fn (array $card): array => $card + ['label' => __('plugins.generic.chatwootIntegration.settings.overview.card.' . $card['key'])],
                OverviewCardStates::build($healthArray, trim((string) $this->getData('chatwootIdentityValidationSecret')) !== '')
            )
            : [];
        $templateMgr->assign('overviewCards', $overviewCards);
        // Owner browser review 2026-09-04: Health Check/Captain Sync/Retry
        // Dead Letters human-text labels — see settingsForm.tpl's
        // cwFormatHealthCheck()/cwFormatCaptainSync().
        $templateMgr->assign('healthLabelReachable', __('plugins.generic.chatwootIntegration.settings.health.label.reachable'));
        $templateMgr->assign('healthLabelNotReachable', __('plugins.generic.chatwootIntegration.settings.health.label.notReachable'));
        $templateMgr->assign('healthLabelNotConfigured', __('plugins.generic.chatwootIntegration.settings.health.label.notConfigured'));
        $templateMgr->assign('healthLabelVerified', __('plugins.generic.chatwootIntegration.settings.health.label.verified'));
        $templateMgr->assign('healthLabelInvalid', __('plugins.generic.chatwootIntegration.settings.health.label.invalid'));
        $templateMgr->assign('healthLabelNotChecked', __('plugins.generic.chatwootIntegration.settings.health.label.notChecked'));
        $templateMgr->assign('healthLabelComplete', __('plugins.generic.chatwootIntegration.settings.health.label.complete'));
        $templateMgr->assign('healthLabelIncomplete', __('plugins.generic.chatwootIntegration.settings.health.label.incomplete'));
        $templateMgr->assign('healthLabelJustNow', __('plugins.generic.chatwootIntegration.settings.health.label.justNow'));
        $templateMgr->assign('healthLineService', __('plugins.generic.chatwootIntegration.settings.health.line.service'));
        $templateMgr->assign('healthLineApi', __('plugins.generic.chatwootIntegration.settings.health.line.api'));
        $templateMgr->assign('healthLineIdentity', __('plugins.generic.chatwootIntegration.settings.health.line.identity'));
        $templateMgr->assign('healthLineSettings', __('plugins.generic.chatwootIntegration.settings.health.line.settings'));
        $templateMgr->assign('healthLineLastChecked', __('plugins.generic.chatwootIntegration.settings.health.line.lastChecked'));
        $templateMgr->assign('captainStatusSynced', __('plugins.generic.chatwootIntegration.settings.captainStatus.synced'));
        $templateMgr->assign('captainStatusCreated', __('plugins.generic.chatwootIntegration.settings.captainStatus.created'));
        $templateMgr->assign('captainStatusNoop', __('plugins.generic.chatwootIntegration.settings.captainStatus.noop'));
        $templateMgr->assign('captainStatusConflict', __('plugins.generic.chatwootIntegration.settings.captainStatus.conflict'));
        $templateMgr->assign('captainStatusFailed', __('plugins.generic.chatwootIntegration.settings.captainStatus.failed'));
        $templateMgr->assign('captainStatusUnavailable', __('plugins.generic.chatwootIntegration.settings.captainStatus.unavailable'));
        $templateMgr->assign('captainNoneLabel', __('plugins.generic.chatwootIntegration.settings.captainStatus.none'));
        $templateMgr->assign('captainUpToDateLabel', __('plugins.generic.chatwootIntegration.settings.captainSync.upToDate'));
        $templateMgr->assign('captainCompletedLabel', __('plugins.generic.chatwootIntegration.settings.captainSync.completed'));
        $templateMgr->assign('captainCompletedWithIssuesLabel', __('plugins.generic.chatwootIntegration.settings.captainSync.completedWithIssues'));
        $templateMgr->assign('captainLineDocument', __('plugins.generic.chatwootIntegration.settings.captainSync.lineDocument'));
        $templateMgr->assign('captainLineTools', __('plugins.generic.chatwootIntegration.settings.captainSync.lineTools'));
        $templateMgr->assign('captainLineScenarios', __('plugins.generic.chatwootIntegration.settings.captainSync.lineScenarios'));
        $templateMgr->assign('retryRetriedLabel', __('plugins.generic.chatwootIntegration.settings.health.retryDeadLetters.retried'));
        $templateMgr->assign('retryNoneLabel', __('plugins.generic.chatwootIntegration.settings.health.retryDeadLetters.none'));

        $overviewStateLabels = [
            OverviewCardStates::HEALTHY => __('plugins.generic.chatwootIntegration.settings.overview.state.healthy'),
            OverviewCardStates::CONFIGURED => __('plugins.generic.chatwootIntegration.settings.overview.state.configured'),
            OverviewCardStates::OPTIONAL_OFF => __('plugins.generic.chatwootIntegration.settings.overview.state.optionalOff'),
            OverviewCardStates::NOT_CONFIGURED => __('plugins.generic.chatwootIntegration.settings.overview.state.notConfigured'),
            OverviewCardStates::NEVER_CHECKED => __('plugins.generic.chatwootIntegration.settings.overview.state.neverChecked'),
            OverviewCardStates::DEGRADED => __('plugins.generic.chatwootIntegration.settings.overview.state.degraded'),
            OverviewCardStates::FAILED => __('plugins.generic.chatwootIntegration.settings.overview.state.failed'),
            OverviewCardStates::ACTION_REQUIRED => __('plugins.generic.chatwootIntegration.settings.overview.state.actionRequired'),
        ];
        $templateMgr->assign('overviewStateLabels', $overviewStateLabels);

        // Owner browser review 2026-09-04: the overview needs one human
        // banner sentence summarizing what actually needs attention,
        // instead of making the admin read every card to find problems.
        // Never claims "healthy" for the whole system — only that
        // nothing here needs attention right now.
        $needsAttentionStates = [OverviewCardStates::FAILED, OverviewCardStates::ACTION_REQUIRED, OverviewCardStates::DEGRADED];
        $needsAttentionLabels = array_values(array_map(
            fn (array $card): string => $card['label'],
            array_filter($overviewCards, fn (array $card): bool => in_array($card['state'], $needsAttentionStates, true))
        ));
        $templateMgr->assign('overviewNeedsAttentionLabels', $needsAttentionLabels);

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;

        foreach (self::secretKeys() as $key) {
            $submitted = (string) $this->getData($key);
            $existing = (string) $plugin->getSetting($contextId, $key);
            $this->setData($key, SecretFieldMasking::resolveSavedValue($submitted, $existing));
        }

        // Positive audience model: translate the submitted "allowed"
        // checkboxes back into the underlying negative hideFor* values
        // the runtime gate in addChatwootWidget() actually reads, so
        // that gate never has to change.
        foreach (self::AUDIENCE_ROLE_KEYS as $audienceKey => $hideKey) {
            $allowed = self::isChecked($this->getData('audienceAllow_' . $audienceKey));
            $this->setData($hideKey, !$allowed);
        }

        // Automation/Event Bridge matrix: encode the submitted per-row
        // Action selects back into eventDeliveryPerEventOverridesJson —
        // the real storage format EventDeliverySettingsResolver already
        // parses (unchanged). A row left at "" (use default) is simply
        // omitted, exactly like never having had an override.
        $overrides = [];
        foreach (self::EVENT_MATRIX_ROWS as $rowKey => [$eventType, ]) {
            $mode = trim((string) $this->getData('eventAction_' . $rowKey));
            if ($mode !== '') {
                $overrides[$eventType] = $mode;
            }
        }
        $this->setData('eventDeliveryPerEventOverridesJson', $overrides === [] ? '' : json_encode($overrides));

        // UX-024: SettingsRegistry::keys()/::type() already includes
        // every hideForRole_* key — see initData()'s note.
        foreach (SettingsRegistry::keys() as $key) {
            $plugin->updateSetting($contextId, $key, $this->getData($key), SettingsRegistry::type($key));
        }

        parent::execute(...$functionArgs);
    }
}
