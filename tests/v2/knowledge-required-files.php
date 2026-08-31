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
        /** @var array<string,string> genreId => localized name */
        public static array $genreNames = [];

        public static function getDAO(string $name): object
        {
            return new class () {
                public function getById(int $id, int $contextId): ?object
                {
                    $name = \PKP\db\DAORegistry::$genreNames[$id] ?? null;
                    if ($name === null) {
                        return null;
                    }
                    return new class ($name) {
                        public function __construct(private string $name)
                        {
                        }
                        public function getLocalizedName(): string
                        {
                            return $this->name;
                        }
                    };
                }
            };
        }
    }
}

namespace APP\plugins\generic\requiredSubmissionFiles {
    /** Mirrors only the surface the adapter actually calls — see a real local checkout of Airix360/ojs-required-submission-files-airix. */
    final class RequiredSubmissionFilesPlugin
    {
        /** @param array<int,int[]> $genreIdsByContext real plugin settings are per-context, via getSetting($contextId, 'requiredGenreIds') */
        public function __construct(private bool $enabled, private array $genreIdsByContext)
        {
        }
        public function getEnabled(int $contextId): bool
        {
            return $this->enabled;
        }
        public function getRequiredGenreIds(int $contextId): array
        {
            return $this->genreIdsByContext[$contextId] ?? [];
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\CoreJournalKnowledgeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;

    function knowledgeRequiredFilesCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeRequiredFilesContext
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
        public function getData(string $key): mixed
        {
            return null;
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

    $adapter = new Ojs35CompatibilityAdapter();
    $compiler = new KnowledgeCompiler();
    $compiler->registerProvider(new CoreJournalKnowledgeProvider($adapter));
    $context = new FakeRequiredFilesContext(7);

    // ================================================================
    // Absent / disabled / no configured genres -> omit the fact.
    // ================================================================
    \PKP\plugins\PluginRegistry::$plugins = [];
    $absentCompilation = $compiler->compile($context, new \stdClass(), 7, 'en');
    knowledgeRequiredFilesCheck($absentCompilation->fact('submission.requiredFileGenres') === null, 'no fact when the plugin is absent');

    \PKP\plugins\PluginRegistry::$plugins['generic']['requiredsubmissionfilesplugin'] = new \APP\plugins\generic\requiredSubmissionFiles\RequiredSubmissionFilesPlugin(false, [7 => [1, 2]]);
    $disabledCompilation = $compiler->compile($context, new \stdClass(), 7, 'en');
    knowledgeRequiredFilesCheck($disabledCompilation->fact('submission.requiredFileGenres') === null, 'no fact when the plugin is disabled');

    \PKP\plugins\PluginRegistry::$plugins['generic']['requiredsubmissionfilesplugin'] = new \APP\plugins\generic\requiredSubmissionFiles\RequiredSubmissionFilesPlugin(true, [7 => []]);
    $noGenresCompilation = $compiler->compile($context, new \stdClass(), 7, 'en');
    knowledgeRequiredFilesCheck($noGenresCompilation->fact('submission.requiredFileGenres') === null, 'no fact when no genres are configured');

    // ================================================================
    // Configured genres resolve to real localized names.
    // ================================================================
    \PKP\db\DAORegistry::$genreNames = [1 => 'Manuscript', 2 => 'Cover Letter'];
    \PKP\plugins\PluginRegistry::$plugins['generic']['requiredsubmissionfilesplugin'] = new \APP\plugins\generic\requiredSubmissionFiles\RequiredSubmissionFilesPlugin(true, [7 => [1, 2]]);
    $resolvedCompilation = $compiler->compile($context, new \stdClass(), 7, 'en');
    $fact = $resolvedCompilation->fact('submission.requiredFileGenres');
    knowledgeRequiredFilesCheck($fact !== null, 'configured genres must surface the fact');
    knowledgeRequiredFilesCheck(str_contains($fact->value(), 'Manuscript') && str_contains($fact->value(), 'Cover Letter'), 'fact must include every resolvable genre name');

    // ================================================================
    // A genre ID configured but since removed/disabled must be silently
    // skipped, exactly as the plugin's own checkRequiredGenres() hook does —
    // never surfaced as a broken/blank reference.
    // ================================================================
    \PKP\db\DAORegistry::$genreNames = [1 => 'Manuscript'];
    \PKP\plugins\PluginRegistry::$plugins['generic']['requiredsubmissionfilesplugin'] = new \APP\plugins\generic\requiredSubmissionFiles\RequiredSubmissionFilesPlugin(true, [7 => [1, 999]]);
    $staleGenreCompilation = $compiler->compile($context, new \stdClass(), 7, 'en');
    $staleFact = $staleGenreCompilation->fact('submission.requiredFileGenres');
    knowledgeRequiredFilesCheck($staleFact !== null && $staleFact->value() === 'Manuscript', 'a removed/unresolvable genre ID must be silently skipped, never a blank placeholder');

    // ================================================================
    // Multi-journal isolation.
    // ================================================================
    $contextB = new FakeRequiredFilesContext(8);
    $compilationB = $compiler->compile($contextB, new \stdClass(), 8, 'en');
    knowledgeRequiredFilesCheck($compilationB->fact('submission.requiredFileGenres') === null, 'context B must never see context A\'s configured genre plugin state by accident');

    // ================================================================
    // getAirixRequiredSubmissionFileGenres() itself (the journal-level
    // policy fact this Knowledge provider reads) must stay
    // submission-agnostic. The submission-specific "which genres are
    // still missing a file" diagnosis is a deliberately separate method
    // (getMissingRequiredSubmissionFileGenreNames(), DIA-006) — this test
    // only asserts the two stay separate, not that the submission-level
    // check doesn't exist.
    // ================================================================
    $adapterSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Compatibility/Ojs35CompatibilityAdapter.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $adapterSource .= is_array($token) ? $token[1] : $token;
    }
    $methodStart = strpos($adapterSource, 'function getAirixRequiredSubmissionFileGenres');
    knowledgeRequiredFilesCheck($methodStart !== false, 'getAirixRequiredSubmissionFileGenres() must exist as its own method');
    $nextMethodStart = strpos($adapterSource, 'function ', $methodStart + 1);
    knowledgeRequiredFilesCheck($nextMethodStart !== false, 'a following method must exist to bound this method body');
    $methodBody = substr($adapterSource, $methodStart, $nextMethodStart - $methodStart);
    foreach (['filterBySubmissionIds', 'submissionFile', '$submissionId', 'checkRequiredGenres'] as $forbidden) {
        knowledgeRequiredFilesCheck(!str_contains($methodBody, $forbidden), "getAirixRequiredSubmissionFileGenres() itself must never touch \"{$forbidden}\" — that belongs only to the separate submission-specific missing-files diagnosis");
    }

    fwrite(STDOUT, "Knowledge required-files tests passed\n");
}
