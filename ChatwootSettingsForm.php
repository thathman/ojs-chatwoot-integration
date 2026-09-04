<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Event\EventDeliveryMode;
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

        parent::initData();
    }

    public function readInputData(): void
    {
        // UX-024: SettingsRegistry::keys() already includes every
        // hideForRole_* key — see initData()'s note.
        $this->readUserVars(SettingsRegistry::keys());
        $this->addCheck(new FormValidator($this, 'chatwootBaseUrl', 'required', 'plugins.generic.chatwootIntegration.settings.chatwootBaseUrlRequired'));
        $this->addCheck(new FormValidator($this, 'chatwootWebsiteToken', 'required', 'plugins.generic.chatwootIntegration.settings.chatwootWebsiteTokenRequired'));
    }

    public function fetch($request, $template = null, $display = false): string
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        $router = $request->getRouter();

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

        $templateMgr->assign('eventDeliveryGlobalModeOptions', [
            '' => __('plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.useLegacy'),
            EventDeliveryMode::PRIVATE_NOTE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.privateNote'),
            EventDeliveryMode::OPEN_UPDATE_CONVERSATION => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.openUpdateConversation'),
            EventDeliveryMode::UPDATE_CONTEXT => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.updateContext'),
            EventDeliveryMode::AUDIT_ONLY => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.auditOnly'),
            EventDeliveryMode::OPT_IN_CUSTOMER_MESSAGE => __('plugins.generic.chatwootIntegration.settings.eventDeliveryMode.optInCustomerMessage'),
        ]);

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

        $healthSummary = method_exists($this->plugin, 'supportGatewayHealthSummary') ? $this->plugin->supportGatewayHealthSummary($request) : null;
        $templateMgr->assign('supportGatewayHealth', $healthSummary?->toArray());

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

        // UX-024: SettingsRegistry::keys()/::type() already includes
        // every hideForRole_* key — see initData()'s note.
        foreach (SettingsRegistry::keys() as $key) {
            $plugin->updateSetting($contextId, $key, $this->getData($key), SettingsRegistry::type($key));
        }

        parent::execute(...$functionArgs);
    }
}
