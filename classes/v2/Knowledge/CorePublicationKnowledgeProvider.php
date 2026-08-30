<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;

/**
 * Public publication/open-access/archiving policy — journal-level facts,
 * never a specific submission's publication status (docs/v2/KNOWLEDGE_DIAGNOSTICS.md
 * §3 "Publication"). "Submission 422 will publish next Tuesday" or "Submission
 * 422's DOI is pending" belong to the live `ojs_get_publication_status`
 * Support API endpoint, never here.
 *
 * Every accessor verified against a real local checkout of `ojs`
 * `stable-3_5_0`: `Journal::PUBLISHING_MODE_{OPEN,SUBSCRIPTION,NONE}`
 * (classes/journal/Journal.php), `enableDois`/`delayedOpenAccessDuration`
 * (schemas/context.json), and the `issue/current`+`issue/archive` page
 * routes already used by OJS core itself (NotificationManager,
 * OJSPaymentManager, SitemapHandler).
 */
final class CorePublicationKnowledgeProvider implements KnowledgeProviderInterface
{
    /** Journal::PUBLISHING_MODE_OPEN=0 / SUBSCRIPTION=1 / NONE=2 — verified against ojs stable-3_5_0. */
    private const ACCESS_MODEL_SENTENCES = [
        0 => 'This journal provides open access to its published content.',
        1 => 'This journal uses a subscription model; some or all content requires a subscription.',
        2 => 'This journal does not currently publish content online.',
    ];

    public function providerId(): string
    {
        return 'core.publication';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context) || !method_exists($context, 'getData')) {
            return [];
        }

        $facts = [];
        $this->addAccessModel($facts, $context, $locale);
        $this->addOpenAccessPolicyText($facts, $context, $locale);
        $this->addDoiPolicy($facts, $context, $locale);
        $this->addIssueUrls($facts, $context, $request, $locale);

        return $facts;
    }

    private function addAccessModel(array &$facts, $context, string $locale): void
    {
        try {
            $mode = $context->getData('publishingMode');
        } catch (\Throwable $e) {
            return;
        }

        // publishingMode=0 (OPEN) is a real, meaningful value — must not be
        // confused with "unset". Only proceed when the setting is actually present.
        if ($mode === null || !is_numeric($mode)) {
            return;
        }

        $sentence = self::ACCESS_MODEL_SENTENCES[(int) $mode] ?? null;
        if ($sentence === null) {
            return;
        }

        if ((int) $mode === 1) {
            try {
                $delayWeeks = $context->getData('delayedOpenAccessDuration');
            } catch (\Throwable $e) {
                $delayWeeks = null;
            }
            if (is_numeric($delayWeeks) && (int) $delayWeeks > 0) {
                $sentence .= sprintf(' Content becomes freely available %d week(s) after publication.', (int) $delayWeeks);
            }
        }

        $facts[] = new KnowledgeFact(
            'publication.accessModel',
            $sentence,
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            'publishingMode'
        );
    }

    private function addOpenAccessPolicyText(array &$facts, $context, string $locale): void
    {
        if (!method_exists($context, 'getLocalizedData')) {
            return;
        }

        try {
            $selectedLocale = $locale;
            $value = $context->getLocalizedData('openAccessPolicy', $locale, $selectedLocale);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $clean = KnowledgeSanitizer::sanitize($value);
        if ($clean === '') {
            return;
        }

        $facts[] = new KnowledgeFact(
            'policy.openAccessPolicy',
            $clean,
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            is_string($selectedLocale) && $selectedLocale !== '' ? $selectedLocale : $locale,
            $this->providerId(),
            'openAccessPolicy'
        );
    }

    private function addDoiPolicy(array &$facts, $context, string $locale): void
    {
        try {
            $enabled = (bool) $context->getData('enableDois');
        } catch (\Throwable $e) {
            return;
        }

        if (!$enabled) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'publication.doiAssigned',
            'true',
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            'enableDois'
        );
    }

    private function addIssueUrls(array &$facts, $context, $request, string $locale): void
    {
        if (!is_object($request) || !method_exists($context, 'getPath')) {
            return;
        }

        try {
            $path = $context->getPath();
            if (!is_string($path) || $path === '') {
                return;
            }
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'url')) {
                return;
            }

            $currentUrl = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $path, 'issue', 'current');
            if (is_string($currentUrl) && $currentUrl !== '') {
                $facts[] = new KnowledgeFact('publication.currentIssueUrl', $currentUrl, KnowledgeClassification::PUBLIC, 'ojs.dispatcher', $locale, $this->providerId(), 'issue/current');
            }

            $archiveUrl = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $path, 'issue', 'archive');
            if (is_string($archiveUrl) && $archiveUrl !== '') {
                $facts[] = new KnowledgeFact('publication.archiveUrl', $archiveUrl, KnowledgeClassification::PUBLIC, 'ojs.dispatcher', $locale, $this->providerId(), 'issue/archive');
            }
        } catch (\Throwable $e) {
            // Omit the URL facts; never fabricate one.
        }
    }
}
