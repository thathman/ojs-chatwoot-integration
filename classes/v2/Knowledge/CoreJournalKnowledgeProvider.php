<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;

/**
 * First Knowledge Provider: low-risk authoritative facts pulled directly
 * from OJS 3.5's own structured Context settings — never scraped from the
 * rendered journal website. Every accessor here is verified against a real
 * local checkout of `pkp-lib`/`ojs` `stable-3_5_0`
 * (`schemas/context.json`, `classes/context/Context.php`,
 * `classes/section/Repository.php`,
 * `classes/submission/reviewAssignment/ReviewAssignment.php`), not
 * guessed. A setting this provider cannot find is simply omitted — never
 * invented (docs/v2/KNOWLEDGE_DIAGNOSTICS.md: "the compiler compiles, it
 * does not hallucinate").
 *
 * Key prefixes double as the generated-page category (see
 * SupportKnowledgePageHandler): `journal.*` -> about,
 * `submission.*` -> submissions, `review.*` -> review, `policy.*` -> policies.
 *
 * Only `getLocalizedData()`/`getData()`/`getSupportedLocales()` etc. are
 * used — never a raw settings-table read where a public Context accessor
 * already exists, and never a User/Submission/ReviewAssignment object.
 */
final class CoreJournalKnowledgeProvider implements KnowledgeProviderInterface
{
    /** ReviewAssignment::SUBMISSION_REVIEW_METHOD_* — verified against pkp-lib stable-3_5_0. */
    private const REVIEW_METHOD_SENTENCES = [
        1 => 'This journal uses single-anonymous peer review: reviewer identities are withheld from authors.',
        2 => 'This journal uses double-anonymous peer review: author and reviewer identities are withheld from each other.',
        3 => 'This journal uses open peer review: author and reviewer identities are visible to each other.',
    ];

    public function providerId(): string
    {
        return 'core.journal';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context)) {
            return [];
        }

        $facts = [];
        $this->addPlain($facts, $context, 'journal.publisher', 'publisherInstitution', $locale);
        $this->addPlain($facts, $context, 'journal.contactName', 'contactName', $locale);
        $this->addPlain($facts, $context, 'journal.contactEmail', 'contactEmail', $locale);
        $this->addPlain($facts, $context, 'journal.issnOnline', 'onlineIssn', $locale);
        $this->addPlain($facts, $context, 'journal.issnPrint', 'printIssn', $locale);

        $this->addLocalized($facts, $context, 'journal.name', 'name', $locale, false);
        $this->addLocalized($facts, $context, 'journal.description', 'description', $locale, true);
        $this->addLocalized($facts, $context, 'journal.about', 'about', $locale, true);
        $this->addLocalized($facts, $context, 'submission.authorGuidelines', 'authorGuidelines', $locale, true);
        $this->addLocalized($facts, $context, 'submission.checklist', 'submissionChecklist', $locale, true);
        $this->addLocalized($facts, $context, 'review.guidelines', 'reviewGuidelines', $locale, true);
        $this->addLocalized($facts, $context, 'policy.copyright', 'copyrightNotice', $locale, true);
        $this->addLocalized($facts, $context, 'policy.licenseTerms', 'licenseTerms', $locale, true);
        $this->addPlain($facts, $context, 'policy.licenseUrl', 'licenseUrl', $locale);

        $this->addLanguages($facts, $context, $locale);
        $this->addReviewModel($facts, $context, $locale);
        $this->addUrl($facts, $context, $request, $locale);
        $this->addSections($facts, $context, $locale);

        return $facts;
    }

    private function addPlain(array &$facts, $context, string $key, string $settingName, string $locale): void
    {
        try {
            if (!method_exists($context, 'getData')) {
                return;
            }
            $value = $context->getData($settingName);
        } catch (\Throwable $e) {
            return;
        }
        $this->pushIfNotEmpty($facts, $key, $value, $locale, $settingName);
    }

    private function addLocalized(array &$facts, $context, string $key, string $settingName, string $locale, bool $isHtml): void
    {
        try {
            if (!method_exists($context, 'getLocalizedData')) {
                return;
            }
            $selectedLocale = $locale;
            $value = $context->getLocalizedData($settingName, $locale, $selectedLocale);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $clean = $isHtml ? KnowledgeSanitizer::sanitize($value) : trim($value);
        if ($clean === '') {
            return;
        }

        $facts[] = new KnowledgeFact(
            $key,
            $clean,
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            is_string($selectedLocale) && $selectedLocale !== '' ? $selectedLocale : $locale,
            $this->providerId(),
            $settingName
        );
    }

    private function addLanguages(array &$facts, $context, string $locale): void
    {
        try {
            if (!method_exists($context, 'getSupportedLocales')) {
                return;
            }
            $supported = (array) $context->getSupportedLocales();
        } catch (\Throwable $e) {
            return;
        }

        $supported = array_values(array_filter(array_map('strval', $supported), static fn (string $l): bool => $l !== ''));
        if ($supported === []) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'journal.languages',
            implode(', ', $supported),
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            'supportedLocales'
        );
    }

    private function addReviewModel(array &$facts, $context, string $locale): void
    {
        try {
            if (!method_exists($context, 'getData')) {
                return;
            }
            $mode = $context->getData('defaultReviewMode');
        } catch (\Throwable $e) {
            return;
        }

        $sentence = self::REVIEW_METHOD_SENTENCES[(int) $mode] ?? null;
        if ($sentence === null) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'review.model',
            $sentence,
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            'defaultReviewMode'
        );
    }

    private function addUrl(array &$facts, $context, $request, string $locale): void
    {
        if (!is_object($request) || !method_exists($context, 'getPath') || !method_exists($context, 'getId')) {
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
            $url = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, $path, 'index');
        } catch (\Throwable $e) {
            return;
        }

        $this->pushIfNotEmpty($facts, 'journal.url', $url, $locale, 'dispatcher');
    }

    private function addSections(array &$facts, $context, string $locale): void
    {
        if (!method_exists($context, 'getId') || !class_exists('\APP\facades\Repo')) {
            return;
        }

        try {
            $contextId = (int) $context->getId();
            $sections = \APP\facades\Repo::section()->getSectionList($contextId, true);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($sections) || $sections === []) {
            return;
        }

        $titles = [];
        foreach ($sections as $section) {
            $title = is_array($section) ? ($section['title'] ?? null) : null;
            if (is_string($title) && trim($title) !== '') {
                $titles[] = trim($title);
            }
        }

        if ($titles === []) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'submission.sections',
            implode(', ', $titles),
            KnowledgeClassification::PUBLIC,
            'ojs.section_repository',
            $locale,
            $this->providerId(),
            'Repo::section()->getSectionList()'
        );
    }

    private function pushIfNotEmpty(array &$facts, string $key, mixed $value, string $locale, string $settingName): void
    {
        if (!is_string($value) && !is_numeric($value)) {
            return;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $facts[] = new KnowledgeFact(
            $key,
            $value,
            KnowledgeClassification::PUBLIC,
            'ojs.context',
            $locale,
            $this->providerId(),
            $settingName
        );
    }
}
