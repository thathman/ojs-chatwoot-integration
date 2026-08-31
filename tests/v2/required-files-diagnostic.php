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
        /** @var array<int,string> genreId => localized name */
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
    final class RequiredSubmissionFilesPlugin
    {
        /** @param int[] $genreIds */
        public function __construct(private bool $enabled, private array $genreIds)
        {
        }
        public function getEnabled(int $contextId): bool
        {
            return $this->enabled;
        }
        public function getRequiredGenreIds(int $contextId): array
        {
            return $this->genreIds;
        }
    }
}

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,int[]> submissionId => uploaded genre ids */
        public static array $uploadedGenreIdsBySubmission = [];

        public static function submissionFile(): object
        {
            return new class () {
                public function getCollector(): object
                {
                    return new class () {
                        private array $submissionIds = [];
                        public function filterBySubmissionIds(array $ids): static
                        {
                            $this->submissionIds = $ids;
                            return $this;
                        }
                        public function getMany(): array
                        {
                            $result = [];
                            foreach ($this->submissionIds as $submissionId) {
                                foreach (\APP\facades\Repo::$uploadedGenreIdsBySubmission[$submissionId] ?? [] as $genreId) {
                                    $result[] = new class ($genreId) {
                                        public function __construct(private int $genreId)
                                        {
                                        }
                                        public function getGenreId(): int
                                        {
                                            return $this->genreId;
                                        }
                                    };
                                }
                            }
                            return $result;
                        }
                    };
                }
            };
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;

    function requiredFilesDiagnosticCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeContextForRequiredFilesDiagnostic
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    final class FakeSubmissionForRequiredFilesDiagnostic
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    \PKP\db\DAORegistry::$genreNames = [10 => 'Manuscript', 11 => 'Data Availability Statement', 12 => 'Cover Letter'];

    $adapter = new Ojs35CompatibilityAdapter();
    $context = new FakeContextForRequiredFilesDiagnostic(7);

    // --- feature disabled/absent: never reports anything missing ---
    \PKP\plugins\PluginRegistry::$plugins = [];
    $submission = new FakeSubmissionForRequiredFilesDiagnostic(900);
    requiredFilesDiagnosticCheck(
        $adapter->getMissingRequiredSubmissionFileGenreNames($context, $submission) === [],
        'when the plugin is absent, nothing must be reported missing (deliberately indistinguishable from "all satisfied" — see DIA-006 tasklist note)'
    );

    // --- feature enabled, submission has every required genre uploaded ---
    \PKP\plugins\PluginRegistry::$plugins = [
        'generic' => ['requiredsubmissionfilesplugin' => new \APP\plugins\generic\requiredSubmissionFiles\RequiredSubmissionFilesPlugin(true, [10, 11])],
    ];
    \APP\facades\Repo::$uploadedGenreIdsBySubmission[900] = [10, 11];
    requiredFilesDiagnosticCheck(
        $adapter->getMissingRequiredSubmissionFileGenreNames($context, $submission) === [],
        'a submission with every required genre uploaded must report nothing missing'
    );

    // --- feature enabled, submission missing one required genre ---
    \APP\facades\Repo::$uploadedGenreIdsBySubmission[900] = [10];
    requiredFilesDiagnosticCheck(
        $adapter->getMissingRequiredSubmissionFileGenreNames($context, $submission) === ['Data Availability Statement'],
        'a submission missing genre 11 must report exactly its localized name'
    );

    // --- an uploaded genre not in the required set must never appear as "missing" ---
    \APP\facades\Repo::$uploadedGenreIdsBySubmission[901] = [12];
    $otherSubmission = new FakeSubmissionForRequiredFilesDiagnostic(901);
    requiredFilesDiagnosticCheck(
        $adapter->getMissingRequiredSubmissionFileGenreNames($context, $otherSubmission) === ['Manuscript', 'Data Availability Statement'],
        'only genres actually configured as required may ever be reported missing, regardless of what else is uploaded'
    );

    // --- cross-submission isolation: submission 901's uploads must never satisfy submission 900's requirement ---
    requiredFilesDiagnosticCheck(
        $adapter->getMissingRequiredSubmissionFileGenreNames($context, $submission) === ['Data Availability Statement'],
        'submission 900 must independently report only its own missing genres, unaffected by submission 901\'s uploads'
    );

    // --- invalid input degrades safely ---
    requiredFilesDiagnosticCheck($adapter->getMissingRequiredSubmissionFileGenreNames(null, $submission) === [], 'a non-object context must degrade to an empty result');
    requiredFilesDiagnosticCheck($adapter->getMissingRequiredSubmissionFileGenreNames($context, null) === [], 'a non-object submission must degrade to an empty result');

    fwrite(STDOUT, "Required-files diagnostic adapter tests passed\n");
}
