<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Plugin;

use APP\core\Application;
use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationPlugin;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ChatwootContextProjector;
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
    private ?ChatwootContextProjector $contextProjector = null;
    private ?SupportContext $lastSupportContext = null;
    private bool $contextProjectionInjected = false;

    /**
     * Resolve the normalized server-side support context, project only safe
     * display attributes into Chatwoot, then delegate to the unchanged v1
     * widget renderer. Failure here must never break the legacy widget path.
     */
    public function addChatwootWidget($hookName, $args)
    {
        try {
            $request = Application::get()->getRequest();
            $this->lastSupportContext = $this->runtimeContextBridge()->resolve(
                $request,
                (string) Locale::getLocale()
            );
            $this->injectProjectedContext($args);
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

        $contextId = $this->lastSupportContext->contextId();
        $nonce = '';
        if ($this->v2Bool($this->v2EffectiveSetting($contextId, 'cspSafeMode', false))) {
            if (method_exists($templateMgr, 'getTemplateVars')) {
                $nonce = trim((string) ($templateMgr->getTemplateVars('cspNonce') ?? ''));
            }
            if ($nonce === '') {
                return;
            }
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

        if (isset($args[2]) && is_string($args[2]) && stripos($args[2], 'data-ojs-chatwoot-context="v2"') === false) {
            if (stripos($args[2], '</body>') !== false) {
                $args[2] = preg_replace('/<\/body>/i', $script . "\n</body>", $args[2], 1);
            } else {
                $args[2] .= $script;
            }
        }

        $this->contextProjectionInjected = true;
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
