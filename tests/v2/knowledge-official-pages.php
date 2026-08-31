<?php

declare(strict_types=1);

namespace PKP\plugins {
    final class PluginRegistry
    {
        /** @var array<string,array<string,object>> */
        public static array $plugins = [];
        public static function loadCategory(string $category, bool $enabledOnly = false): array
        {
            return self::$plugins[$category] ?? [];
        }
        public static function getPlugin(string $category, string $name): ?object
        {
            return self::$plugins[$category][$name] ?? null;
        }
    }
}

namespace PKP\db {
    final class DAORegistry
    {
        public static ?object $staticPagesDao = null;
        public static function getDAO(string $name): object
        {
            if ($name === 'StaticPagesDAO' && self::$staticPagesDao !== null) {
                return self::$staticPagesDao;
            }
            throw new \Exception("no fake DAO registered for {$name}");
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeSourcePrecedence;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\OfficialPageKnowledgeProvider;

    function knowledgePagesCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeStaticPagesPlugin
    {
        public function __construct(private bool $enabled)
        {
        }
        public function getEnabled(): bool
        {
            return $this->enabled;
        }
    }

    final class FakeStaticPage
    {
        public function __construct(private string $path, private array $titles, private array $contents)
        {
        }
        public function getPath(): string
        {
            return $this->path;
        }
        public function getTitle(string $locale): string
        {
            return $this->titles[$locale] ?? '';
        }
        public function getContent(string $locale): string
        {
            return $this->contents[$locale] ?? '';
        }
    }

    final class FakeStaticPagesDao
    {
        /** @var array<int,array<int,FakeStaticPage>> */
        public array $pagesByContextId = [];
        public function getByContextId(int $contextId): object
        {
            $pages = $this->pagesByContextId[$contextId] ?? [];
            return new class ($pages) {
                public function __construct(private array $pages)
                {
                }
                public function toArray(): array
                {
                    return $this->pages;
                }
            };
        }
    }

    final class FakeOfficialPagesContext
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getSupportedLocales(): array
        {
            return ['en'];
        }
        public function getPrimaryLocale(): string
        {
            return 'en';
        }
    }

    // ================================================================
    // Part 1: OfficialPageKnowledgeProvider — detection, sanitization, isolation.
    // ================================================================
    $adapter = new Ojs35CompatibilityAdapter();
    $compiler = new KnowledgeCompiler();
    $compiler->registerProvider(new OfficialPageKnowledgeProvider($adapter));

    \PKP\plugins\PluginRegistry::$plugins = [];
    $contextA = new FakeOfficialPagesContext(1);
    $noPluginCompilation = $compiler->compile($contextA, new \stdClass(), 1, 'en');
    knowledgePagesCheck($noPluginCompilation->facts() === [], 'no static pages fact when the Static Pages plugin is absent');

    \PKP\plugins\PluginRegistry::$plugins['generic']['staticpagesplugin'] = new FakeStaticPagesPlugin(false);
    $disabledCompilation = $compiler->compile($contextA, new \stdClass(), 1, 'en');
    knowledgePagesCheck($disabledCompilation->facts() === [], 'no static pages fact when the Static Pages plugin is disabled');

    \PKP\plugins\PluginRegistry::$plugins['generic']['staticpagesplugin'] = new FakeStaticPagesPlugin(true);
    $dao = new FakeStaticPagesDao();
    $dao->pagesByContextId[1] = [
        new FakeStaticPage('editorial-team', ['en' => 'Editorial Team'], ['en' => '<p>Our team</p><script>alert(1)</script>']),
    ];
    $dao->pagesByContextId[2] = [
        new FakeStaticPage('history', ['en' => 'History'], ['en' => '<p>Founded in 1990</p>']),
    ];
    \PKP\db\DAORegistry::$staticPagesDao = $dao;

    $pagesCompilationA = $compiler->compile($contextA, new \stdClass(), 1, 'en');
    $editorialFact = $pagesCompilationA->fact('officialPage.editorial-team');
    knowledgePagesCheck($editorialFact !== null, 'an enabled static page must produce a KnowledgeFact');
    knowledgePagesCheck(str_contains($editorialFact->value(), 'Editorial Team'), 'the fact must include the page title');
    knowledgePagesCheck(str_contains($editorialFact->value(), 'Our team'), 'the fact must include the sanitized page content');
    knowledgePagesCheck(!str_contains($editorialFact->value(), '<script'), 'static page content must be sanitized');
    knowledgePagesCheck($editorialFact->source() === KnowledgeSourcePrecedence::SOURCE_OFFICIAL_PAGE, 'the fact source must be tagged as an official page for precedence purposes');

    $contextB = new FakeOfficialPagesContext(2);
    $pagesCompilationB = $compiler->compile($contextB, new \stdClass(), 2, 'en');
    knowledgePagesCheck($pagesCompilationB->fact('officialPage.editorial-team') === null, 'context B must never see context A\'s static pages');
    knowledgePagesCheck($pagesCompilationA->fact('officialPage.history') === null, 'context A must never see context B\'s static pages');

    // ================================================================
    // Part 2: KnowledgeSourcePrecedence + conflict resolution.
    // ================================================================
    final class StructuredFactProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string
        {
            return 'test.structured';
        }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('fee.publicationAmount', '100.00', KnowledgeClassification::PUBLIC, 'ojs.context', $locale, $this->providerId())];
        }
    }

    final class StaleOfficialPageFactProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string
        {
            return 'test.stale_page';
        }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('fee.publicationAmount', '75.00', KnowledgeClassification::PUBLIC, KnowledgeSourcePrecedence::SOURCE_OFFICIAL_PAGE, $locale, $this->providerId())];
        }
    }

    final class UnknownSourceFactProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string
        {
            return 'test.unknown_source';
        }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('fee.publicationAmount', '999.00', KnowledgeClassification::PUBLIC, 'totally.unrecognized.source', $locale, $this->providerId())];
        }
    }

    $conflictCompiler = new KnowledgeCompiler();
    $conflictCompiler->registerProvider(new StructuredFactProvider());
    $conflictCompiler->registerProvider(new StaleOfficialPageFactProvider());
    $conflictCompiler->registerProvider(new UnknownSourceFactProvider());

    $conflictContext = new FakeOfficialPagesContext(9);
    $conflictCompilation = $conflictCompiler->compile($conflictContext, new \stdClass(), 9, 'en');

    knowledgePagesCheck($conflictCompilation->fact('fee.publicationAmount')?->value() === '100.00', 'structured OJS configuration must win over a stale official page or an unknown source');

    $winningKeys = array_map(static fn ($f) => $f->key() . '=' . $f->value(), $conflictCompilation->facts());
    knowledgePagesCheck(count(array_filter($winningKeys, static fn ($v) => str_starts_with($v, 'fee.publicationAmount='))) === 1, 'a colliding key must appear exactly once in the compiled facts — never duplicated');

    knowledgePagesCheck(count($conflictCompilation->conflicts()) === 2, 'both losing facts (stale page + unknown source) must be recorded as conflicts');
    foreach ($conflictCompilation->conflicts() as $conflict) {
        knowledgePagesCheck($conflict->key() === 'fee.publicationAmount', 'recorded conflict must reference the colliding key');
        knowledgePagesCheck($conflict->winner()->value() === '100.00', 'recorded conflict must reference the actual winning fact');
        knowledgePagesCheck($conflict->loser()->value() !== '100.00', 'recorded conflict must reference a losing fact, never the winner itself');
    }

    // A conflict loser must never leak into the rendered facts.
    foreach ($conflictCompilation->facts() as $fact) {
        knowledgePagesCheck($fact->value() !== '75.00' && $fact->value() !== '999.00', 'a losing conflict value must never appear among the compiled facts');
    }

    fwrite(STDOUT, "Knowledge official pages / precedence tests passed\n");
}
