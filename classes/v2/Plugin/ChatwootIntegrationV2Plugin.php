<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Plugin;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationPlugin;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
use PKP\core\JSONMessage;
use PKP\facades\Locale;

/**
 * Transitional v2 runtime shell.
 *
 * It deliberately inherits the proven v1 behavior and overrides only seams
 * that have a tested v2 implementation. This keeps migration incremental.
 */
class ChatwootIntegrationV2Plugin extends ChatwootIntegrationPlugin
{
    private const LEGACY_EXPORT_KEYS = [
        'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
        'enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests',
        'hideForRole_1','hideForRole_16','hideForRole_17','hideForRole_4097','hideForRole_65536','hideForRole_4096','hideForRole_1048576',
        'enableGlobalDefaults','retryQueueEnabled','maxRetryAttempts','eventSyncMode','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected',
        'eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','lazyLoadTrigger','excludedPages','cspSafeMode','skipBackendPages'
    ];

    private ?RuntimeContextBridge $runtimeContextBridge = null;
    private ?SupportContext $lastSupportContext = null;

    /**
     * Resolve the normalized server-side support context before delegating to
     * the unchanged v1 widget renderer. Failure here must never break chat.
     */
    public function addChatwootWidget($hookName, $args)
    {
        try {
            $request = Application::get()->getRequest();
            $this->lastSupportContext = $this->runtimeContextBridge()->resolve(
                $request,
                (string) Locale::getLocale()
            );
        } catch (\Throwable $e) {
            $this->lastSupportContext = null;
        }

        return parent::addChatwootWidget($hookName, $args);
    }

    /**
     * Preserve the v1 export shape while removing credential-bearing fields.
     */
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

    /**
     * Internal seam for upcoming Support API/session work. This is not exposed
     * as an HTTP endpoint and must not be treated as authorization by itself.
     */
    public function getResolvedSupportContext(): ?SupportContext
    {
        return $this->lastSupportContext;
    }

    private function runtimeContextBridge(): RuntimeContextBridge
    {
        if (!$this->runtimeContextBridge) {
            $this->runtimeContextBridge = new RuntimeContextBridge();
        }

        return $this->runtimeContextBridge;
    }
}
