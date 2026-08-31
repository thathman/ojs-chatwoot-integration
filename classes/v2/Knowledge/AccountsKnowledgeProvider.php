<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

/**
 * Public account-*help* knowledge — never account state
 * (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §3 "Accounts/support"). "Users can
 * register here" is knowledge; "john@example.com is registered" is
 * live/private identity state that belongs to the Support API, never here.
 *
 * Every accessor verified against a real local checkout of `pkp-lib`
 * `stable-3_5_0`: `disableUserReg` is the exact single boolean
 * `RegistrationHandler::validate()` itself gates on when a journal
 * context is present (no second, unprovable condition layered on top);
 * `\PKP\orcid\OrcidManager::isEnabled($context)` is ORCID's own published
 * integration point (accounts for both site-wide and journal-level
 * configuration), not a raw `orcidEnabled` setting read directly; the
 * `user/register`, `login`, and `login/lostPassword` routes are the exact
 * routes OJS core's own frontend templates use
 * (`templates/frontend/pages/userLogin.tpl`, `userLostPassword.tpl`,
 * `submissions.tpl`).
 */
final class AccountsKnowledgeProvider implements KnowledgeProviderInterface
{
    public function __construct(private OjsCompatibilityAdapterInterface $adapter)
    {
    }

    public function providerId(): string
    {
        return 'core.accounts';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context) || !method_exists($context, 'getData')) {
            return [];
        }

        $facts = [];
        $this->addRegistration($facts, $context, $request, $locale);
        $this->addLoginAndPasswordReset($facts, $context, $request, $locale);
        $this->addOrcid($facts, $context, $locale);
        $this->addMagicLogin($facts, $context, $request, $locale);
        return $facts;
    }

    private function addRegistration(array &$facts, $context, $request, string $locale): void
    {
        try {
            $disabled = (bool) $context->getData('disableUserReg');
        } catch (\Throwable $e) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'accounts.registrationAvailable',
            $disabled ? 'false' : 'true',
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            'disableUserReg'
        );

        if ($disabled) {
            return;
        }

        $url = $this->buildUrl($request, $context, 'user', 'register');
        if ($url !== null) {
            $facts[] = new KnowledgeFact('accounts.registrationUrl', $url, KnowledgeClassification::PUBLIC, 'ojs.dispatcher', $locale, $this->providerId(), 'user/register');
        }
    }

    private function addLoginAndPasswordReset(array &$facts, $context, $request, string $locale): void
    {
        $loginUrl = $this->buildUrl($request, $context, 'login', null);
        if ($loginUrl !== null) {
            $facts[] = new KnowledgeFact('accounts.loginUrl', $loginUrl, KnowledgeClassification::PUBLIC, 'ojs.dispatcher', $locale, $this->providerId(), 'login');
        }

        $resetUrl = $this->buildUrl($request, $context, 'login', 'lostPassword');
        if ($resetUrl !== null) {
            $facts[] = new KnowledgeFact('accounts.passwordResetUrl', $resetUrl, KnowledgeClassification::PUBLIC, 'ojs.dispatcher', $locale, $this->providerId(), 'login/lostPassword');
        }
    }

    private function addOrcid(array &$facts, $context, string $locale): void
    {
        if (!class_exists('\PKP\orcid\OrcidManager')) {
            return;
        }

        try {
            $enabled = (bool) \PKP\orcid\OrcidManager::isEnabled($context);
        } catch (\Throwable $e) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'accounts.orcidEnabled',
            $enabled ? 'true' : 'false',
            KnowledgeClassification::PUBLIC,
            'ojs.orcid_manager',
            $locale,
            $this->providerId(),
            'OrcidManager::isEnabled()'
        );
    }

    /**
     * Airix Magic Login availability (docs/v2/AIRIX360_INTEGRATIONS.md
     * §7.2 AML-002) — only whether passwordless sign-in is offered by this
     * journal and, if so, its public request URL. Never touches whether
     * any specific email has an account (AML-004's anti-enumeration
     * rule) — this fact has no email parameter anywhere in its call chain.
     */
    private function addMagicLogin(array &$facts, $context, $request, string $locale): void
    {
        try {
            $availability = $this->adapter->getAirixMagicLoginAvailability($context, $request);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($availability) || !($availability['enabled'] ?? false)) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'accounts.magicLoginEnabled',
            'true',
            KnowledgeClassification::PUBLIC,
            'airix.magic_login',
            $locale,
            $this->providerId(),
            "MagicLoginPlugin::getSetting('enabled')"
        );

        $requestUrl = $availability['requestUrl'] ?? null;
        if (is_string($requestUrl) && $requestUrl !== '') {
            $facts[] = new KnowledgeFact(
                'accounts.magicLoginUrl',
                $requestUrl,
                KnowledgeClassification::PUBLIC,
                'airix.magic_login',
                $locale,
                $this->providerId(),
                'magicLogin/request'
            );
        }
    }

    private function buildUrl($request, $context, string $page, ?string $op): ?string
    {
        if (!is_object($request) || !method_exists($context, 'getPath')) {
            return null;
        }

        try {
            $path = $context->getPath();
            if (!is_string($path) || $path === '') {
                return null;
            }
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'url')) {
                return null;
            }
            $url = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $path, $page, $op);
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
