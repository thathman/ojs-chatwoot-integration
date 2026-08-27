<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;
use PKP\security\Role;

class ChatwootSettingsForm extends Form
{
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

        $keys = [
            'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
            'enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests','enableGlobalDefaults','retryQueueEnabled',
            'maxRetryAttempts','eventSyncMode','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected',
            'eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','lazyLoadTrigger',
            'excludedPages','cspSafeMode','skipBackendPages','widgetSettingsJson','launcherBottomOffset',
        ];
        foreach ($keys as $key) {
            $this->setData($key, $plugin->getSetting($contextId, $key));
        }

        $roles = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_READER];
        foreach ($roles as $roleId) {
            $this->setData('hideForRole_' . $roleId, $plugin->getSetting($contextId, 'hideForRole_' . $roleId));
        }

        parent::initData();
    }

    public function readInputData(): void
    {
        $vars = [
            'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
            'enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests','enableGlobalDefaults','retryQueueEnabled',
            'maxRetryAttempts','eventSyncMode','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected',
            'eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','lazyLoadTrigger',
            'excludedPages','cspSafeMode','skipBackendPages','widgetSettingsJson','launcherBottomOffset',
        ];

        $roles = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_READER];
        foreach ($roles as $roleId) {
            $vars[] = 'hideForRole_' . $roleId;
        }

        $this->readUserVars($vars);
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

        $params = ['plugin' => $this->plugin->getName(), 'category' => 'generic'];
        $templateMgr->assign('healthCheckUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'healthCheck'])));
        $templateMgr->assign('testMessageUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'testMessage'])));
        $templateMgr->assign('exportSettingsUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'exportSettings'])));
        $templateMgr->assign('importSettingsUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'importSettings'])));
        $templateMgr->assign('saveGlobalProfileUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'saveGlobalProfile'])));
        $templateMgr->assign('applyGlobalProfileUrl', $router->url($request, null, null, 'manage', null, array_merge($params, ['verb' => 'applyGlobalProfile'])));

        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $plugin = $this->plugin;
        $contextId = $this->contextId;

        $settings = [
            'chatwootBaseUrl' => 'string',
            'chatwootWebsiteToken' => 'string',
            'chatwootIdentityValidationSecret' => 'string',
            'chatwootApiAccessToken' => 'string',
            'chatwootInboxId' => 'int',
            'enableWidget' => 'bool',
            'enableDebugMode' => 'bool',
            'enablePrivacyMode' => 'bool',
            'hideForGuests' => 'bool',
            'enableGlobalDefaults' => 'bool',
            'retryQueueEnabled' => 'bool',
            'maxRetryAttempts' => 'int',
            'eventSyncMode' => 'string',
            'eventSubmissionCreated' => 'bool',
            'eventRevisionRequested' => 'bool',
            'eventAccepted' => 'bool',
            'eventRejected' => 'bool',
            'eventPublicationScheduled' => 'bool',
            'eventPublicationPublished' => 'bool',
            'eventDecisionRecorded' => 'bool',
            'lazyLoadWidget' => 'bool',
            'lazyLoadTrigger' => 'string',
            'excludedPages' => 'string',
            'cspSafeMode' => 'bool',
            'skipBackendPages' => 'bool',
            'widgetSettingsJson' => 'string',
            'launcherBottomOffset' => 'int',
        ];

        foreach ($settings as $key => $type) {
            $plugin->updateSetting($contextId, $key, $this->getData($key), $type);
        }

        $roles = [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_AUTHOR, Role::ROLE_ID_REVIEWER, Role::ROLE_ID_ASSISTANT, Role::ROLE_ID_READER];
        foreach ($roles as $roleId) {
            $plugin->updateSetting($contextId, 'hideForRole_' . $roleId, $this->getData('hideForRole_' . $roleId), 'bool');
        }

        parent::execute(...$functionArgs);
    }
}
