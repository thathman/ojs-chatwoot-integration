<?php

declare(strict_types=1);

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,array<int,array{id:int,title:string,group:bool}>> keyed by contextId */
        public static array $sectionsByContextId = [];

        public static function section(): object
        {
            return new class {
                public function getSectionList(int $contextId, bool $excludeInactive = false): array
                {
                    return \APP\facades\Repo::$sectionsByContextId[$contextId] ?? [];
                }
            };
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\CoreJournalKnowledgeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFingerprint;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHtmlRenderer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeSanitizer;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;

    function knowledgeCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeKnowledgeContext
    {
        public function __construct(
            private int $id,
            private string $path,
            private array $data,
            private array $localized,
            private array $supportedLocales,
            private string $primaryLocale
        ) {
        }

        public function getId(): int { return $this->id; }
        public function getPath(): string { return $this->path; }
        public function getData(string $key): mixed { return $this->data[$key] ?? null; }
        public function getSupportedLocales(): array { return $this->supportedLocales; }
        public function getPrimaryLocale(): string { return $this->primaryLocale; }
        public function getLocalizedName(): string { return $this->localized['name'][$this->primaryLocale] ?? 'Journal'; }

        public function getLocalizedData(string $key, ?string $preferredLocale = null, ?string &$selectedLocale = null): mixed
        {
            $values = $this->localized[$key] ?? [];
            if ($preferredLocale !== null && array_key_exists($preferredLocale, $values)) {
                $selectedLocale = $preferredLocale;
                return $values[$preferredLocale];
            }
            if (array_key_exists($this->primaryLocale, $values)) {
                $selectedLocale = $this->primaryLocale;
                return $values[$this->primaryLocale];
            }
            $selectedLocale = null;
            return null;
        }
    }

    final class FakeKnowledgeDispatcher
    {
        public function url($request, $routeType, $path, $page, $op = null): string
        {
            return "https://example.test/{$path}/{$page}/{$op}";
        }
    }

    final class FakeKnowledgeRequest
    {
        public function __construct(private FakeKnowledgeContext $context) {}
        public function getContext(): FakeKnowledgeContext { return $this->context; }
        public function getDispatcher(): FakeKnowledgeDispatcher { return new FakeKnowledgeDispatcher(); }
    }

    final class ThrowingKnowledgeProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.throwing'; }
        public function collect($context, $request, string $locale): array
        {
            throw new \RuntimeException('provider exploded');
        }
    }

    final class UnsupportedFactProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.unsupported'; }
        public function collect($context, $request, string $locale): array
        {
            return [
                new KnowledgeFact('secret.thing', 'should never render', KnowledgeClassification::PRIVATE, 'test', $locale, $this->providerId()),
                new KnowledgeFact('unsupported.thing', 'also never renders', KnowledgeClassification::UNSUPPORTED, 'test', $locale, $this->providerId()),
            ];
        }
    }

    function makeContext(int $id, string $journalName): FakeKnowledgeContext
    {
        return new FakeKnowledgeContext(
            $id,
            'journal-' . $id,
            [
                'publisherInstitution' => 'Publisher ' . $id,
                'contactEmail' => "editor{$id}@example.test",
                'defaultReviewMode' => 2,
            ],
            [
                'name' => ['en' => $journalName],
                'about' => ['en' => '<p>About ' . $journalName . '</p><script>alert(1)</script>'],
                'authorGuidelines' => ['en' => '<p>Guidelines <b>bold</b></p>'],
                'copyrightNotice' => ['en' => '<p onclick="steal()">Copyright <a href="javascript:evil()">link</a></p>'],
            ],
            ['en', 'fr'],
            'en'
        );
    }

    // ================================================================
    // Part 1: KnowledgeSanitizer — malicious HTML stripped, safe HTML survives.
    // ================================================================
    $dirty = '<p>Hello</p><script>alert(1)</script><iframe src="evil"></iframe><form><input></form>';
    $sanitized = KnowledgeSanitizer::sanitize($dirty);
    knowledgeCheck(!str_contains($sanitized, '<script'), 'sanitizer must strip <script>');
    knowledgeCheck(!str_contains($sanitized, '<iframe'), 'sanitizer must strip <iframe>');
    knowledgeCheck(!str_contains($sanitized, '<form'), 'sanitizer must strip <form>');
    knowledgeCheck(str_contains($sanitized, '<p>Hello</p>'), 'sanitizer must preserve safe formatting tags');

    $eventHandler = KnowledgeSanitizer::sanitize('<p onclick="steal()">click</p>');
    knowledgeCheck(!str_contains($eventHandler, 'onclick'), 'sanitizer must strip event-handler attributes');

    $jsUrl = KnowledgeSanitizer::sanitize('<a href="javascript:evil()">link</a>');
    knowledgeCheck(!str_contains(strtolower($jsUrl), 'javascript:'), 'sanitizer must neutralize javascript: URLs');
    knowledgeCheck(str_contains($jsUrl, '<a'), 'sanitizer must preserve the safe <a> tag itself');

    // ================================================================
    // Part 2: KnowledgeFingerprint — deterministic, order-independent, content-sensitive.
    // ================================================================
    $factsA = [
        new KnowledgeFact('journal.name', 'Journal A', KnowledgeClassification::PUBLIC, 'test', 'en', 'test'),
        new KnowledgeFact('journal.publisher', 'Publisher A', KnowledgeClassification::PUBLIC, 'test', 'en', 'test'),
    ];
    $factsAReordered = array_reverse($factsA);
    $factsAChanged = [
        new KnowledgeFact('journal.name', 'Journal A', KnowledgeClassification::PUBLIC, 'test', 'en', 'test'),
        new KnowledgeFact('journal.publisher', 'Publisher B', KnowledgeClassification::PUBLIC, 'test', 'en', 'test'),
    ];

    knowledgeCheck(KnowledgeFingerprint::compute($factsA) === KnowledgeFingerprint::compute($factsA), 'fingerprint must be deterministic for the same facts');
    knowledgeCheck(KnowledgeFingerprint::compute($factsA) === KnowledgeFingerprint::compute($factsAReordered), 'fingerprint must not change when fact order changes');
    knowledgeCheck(KnowledgeFingerprint::compute($factsA) !== KnowledgeFingerprint::compute($factsAChanged), 'fingerprint must change when a fact value changes');

    // ================================================================
    // Part 3: KnowledgeCompiler — isolation, strict classification, multi-journal isolation.
    // ================================================================
    $compiler = new KnowledgeCompiler();
    $compiler->registerProvider(new ThrowingKnowledgeProvider());
    $compiler->registerProvider(new UnsupportedFactProvider());
    $compiler->registerProvider(new CoreJournalKnowledgeProvider(new Ojs35CompatibilityAdapter()));

    $contextA = makeContext(1, 'Journal A');
    $contextB = makeContext(2, 'Journal B');
    $request = new FakeKnowledgeRequest($contextA);

    \APP\facades\Repo::$sectionsByContextId[1] = [['id' => 1, 'title' => 'Articles', 'group' => false]];
    \APP\facades\Repo::$sectionsByContextId[2] = [['id' => 2, 'title' => 'Reviews', 'group' => false]];

    $compilationA = $compiler->compile($contextA, $request, 1, 'en');
    $compilationB = $compiler->compile($contextB, new FakeKnowledgeRequest($contextB), 2, 'en');

    knowledgeCheck($compilationA->fact('secret.thing') === null, 'private facts must never render');
    knowledgeCheck($compilationA->fact('unsupported.thing') === null, 'unsupported facts must never render — unsupported classification stays invisible');
    knowledgeCheck($compilationA->fact('journal.name')?->value() === 'Journal A', 'a throwing provider must not prevent core knowledge from compiling');
    knowledgeCheck($compilationA->fact('journal.publisher')?->value() === 'Publisher 1', 'core provider facts must be present after isolation');

    foreach ($compilationA->facts() as $fact) {
        knowledgeCheck($fact->isPublic(), 'every fact reaching a compilation must be classification=public');
    }

    knowledgeCheck($compilationA->fact('journal.name')?->value() === 'Journal A', 'context A must only ever see its own facts');
    knowledgeCheck($compilationB->fact('journal.name')?->value() === 'Journal B', 'context B must only ever see its own facts');
    knowledgeCheck($compilationA->fact('journal.publisher')?->value() !== $compilationB->fact('journal.publisher')?->value(), 'two journals must never leak facts into each other');
    knowledgeCheck($compilationA->fact('submission.sections')?->value() === 'Articles', 'context A sections must not leak from context B');
    knowledgeCheck($compilationB->fact('submission.sections')?->value() === 'Reviews', 'context B sections must not leak from context A');

    // Malicious HTML in a real journal setting must come out sanitized end-to-end.
    $about = $compilationA->fact('journal.about');
    knowledgeCheck($about !== null && !str_contains($about->value(), '<script'), 'end-to-end compiled facts must be sanitized, not just the raw sanitizer call');

    // Missing settings simply omit the fact — no fabricated placeholder value.
    $sparseContext = new FakeKnowledgeContext(3, 'journal-3', [], [], ['en'], 'en');
    $sparseCompilation = $compiler->compile($sparseContext, new FakeKnowledgeRequest($sparseContext), 3, 'en');
    knowledgeCheck($sparseCompilation->fact('journal.publisher') === null, 'a missing setting must be omitted, never fabricated');
    knowledgeCheck($sparseCompilation->fact('journal.name') === null, 'a missing localized setting must be omitted, never fabricated');

    // Locale fallback: requesting an unsupported locale must deterministically fall back to the primary locale.
    $deLocaleCompilation = $compiler->compile($contextA, $request, 1, 'de');
    knowledgeCheck($deLocaleCompilation->locale() === 'en', 'requesting an unsupported locale must fall back to the journal primary locale deterministically');
    knowledgeCheck($deLocaleCompilation->fingerprint() === $compilationA->fingerprint(), 'the same effective locale fallback must produce the same fingerprint');

    $frLocaleCompilation = $compiler->compile($contextA, $request, 1, 'fr');
    knowledgeCheck($frLocaleCompilation->locale() === 'fr', 'a supported requested locale must be honored, not silently overridden');

    // ================================================================
    // Part 4: no unrelated object types / secrets ever enter Knowledge output.
    // ================================================================
    foreach ($compilationA->facts() as $fact) {
        knowledgeCheck(is_string($fact->value()), 'every fact value must be a plain string, never a User/Submission/ReviewAssignment object');
    }

    $knowledgeSource = '';
    foreach (glob($root . '/classes/v2/Knowledge/*.php') as $file) {
        $tokens = token_get_all((string) file_get_contents($file));
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $knowledgeSource .= is_array($token) ? $token[1] : $token;
        }
    }
    foreach (['SupportSession', 'ConversationId', 'conversationId', 'chatwootApiAccessToken', 'chatwootIdentityValidationSecret', 'chatwootSupportApiToken', 'PaymentSupportProviderInterface', "'paid'", "'unpaid'", "'waived'"] as $forbidden) {
        knowledgeCheck(!str_contains($knowledgeSource, $forbidden), "Knowledge/ source must never reference \"{$forbidden}\" — private/live state has no path into public knowledge");
    }

    // ================================================================
    // Part 5: KnowledgeHtmlRenderer — root links every generated category page.
    // ================================================================
    $navLinks = ['About' => '/support-knowledge/about', 'Submissions' => '/support-knowledge/submissions', 'Review' => '/support-knowledge/review', 'Policies' => '/support-knowledge/policies'];
    $indexHtml = KnowledgeHtmlRenderer::renderIndex('Journal A', $navLinks);
    foreach ($navLinks as $url) {
        knowledgeCheck(str_contains($indexHtml, $url), "generated root page must link every generated category page ({$url})");
    }

    $categoryHtml = KnowledgeHtmlRenderer::renderCategory('Journal A', 'About', $compilationA->factsWithKeyPrefix('journal.'), $navLinks, 'en');
    knowledgeCheck(!str_contains($categoryHtml, '<script'), 'rendered category page must never contain a script tag');
    knowledgeCheck(str_contains($categoryHtml, 'Publisher 1'), 'rendered category page must include the compiled fact values');

    // ================================================================
    // Part 6: routing wiring — public GET page, not the Bearer/CSRF pipeline.
    // ================================================================
    $pluginSource = (string) file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    knowledgeCheck(str_contains($pluginSource, "SUPPORT_KNOWLEDGE_PAGE = 'support-knowledge'"), 'the generated knowledge pages must live on their own page identifier, not ojsSupportGateway');
    knowledgeCheck(str_contains($pluginSource, 'function supportKnowledgeIndexRequest'), 'plugin must implement the generated knowledge root');
    knowledgeCheck(str_contains($pluginSource, 'function supportKnowledgeCategoryRequest'), 'plugin must implement generated knowledge category pages');

    $handlerSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Http/SupportKnowledgePageHandler.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $handlerSource .= is_array($token) ? $token[1] : $token;
    }
    foreach (['ServiceTokenAuthenticator', 'Bearer', 'requirePost', 'csrfValid'] as $forbidden) {
        knowledgeCheck(!str_contains($handlerSource, $forbidden), "generated knowledge page handler code must never reference \"{$forbidden}\" — these are public GET documents, not the Support API pipeline");
    }

    fwrite(STDOUT, "Knowledge compiler tests passed\n");
}
