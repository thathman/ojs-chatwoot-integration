<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\ChatwootCaptainClientInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportFaqCacheRepositoryInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\ApprovedFaqKnowledgeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\FaqCacheSyncService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeSourcePrecedence;

    function kno011Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    // ================================================================
    // Part 1: the FAQ cache table lands as its own additive migration
    // step, never by editing the immutable 2.0.0.0 install migration
    // (HAR-016/MIG-003).
    // ================================================================
    $baselineMigrationSource = file_get_contents($root . '/classes/v2/Migration/InstallSupportGatewayMigration.php');
    kno011Check(!str_contains($baselineMigrationSource, 'FAQ_CACHE_TABLE'), 'the immutable 2.0.0.0 install migration must never be edited to add the new FAQ table');

    $faqMigrationSource = file_get_contents($root . '/classes/v2/Migration/AddFaqCacheTableMigration.php');
    kno011Check(str_contains($faqMigrationSource, "FAQ_CACHE_TABLE = 'chatwoot_support_faq_cache'"), 'the new migration must declare the FAQ cache table name constant');
    kno011Check(str_contains($faqMigrationSource, 'Schema::dropIfExists(self::FAQ_CACHE_TABLE)'), 'down() must drop the FAQ cache table');
    kno011Check(str_contains($faqMigrationSource, 'cw_faq_cache_identity'), 'the cache table must have a uniqueness constraint on (context_id, locale, external_id)');

    $runnerSource = file_get_contents($root . '/classes/v2/Migration/SupportGatewayMigrationRunner.php');
    kno011Check(str_contains($runnerSource, 'new InstallSupportGatewayMigration()'), 'the runner must still run the real 2.0.0.0 baseline migration');
    kno011Check(str_contains($runnerSource, 'new AddFaqCacheTableMigration()'), 'the runner must run the new FAQ cache migration');
    kno011Check(
        strpos($runnerSource, 'new InstallSupportGatewayMigration()') < strpos($runnerSource, 'new AddFaqCacheTableMigration()'),
        'the baseline migration must run before the new additive step, in the array order the runner iterates'
    );

    $pluginSourceForMigration = file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    kno011Check(str_contains($pluginSourceForMigration, 'new SupportGatewayMigrationRunner()'), 'getInstallMigration() must return the additive runner, not the baseline migration directly');

    // ================================================================
    // Part 2: FaqCacheSyncService — the only place Chatwoot is called live.
    // ================================================================
    final class FakeCaptainClient implements ChatwootCaptainClientInterface
    {
        public array $responses = [];
        public ?\Throwable $throwOnList = null;

        public function findCaptainDocumentByExternalLink(int $assistantId, string $externalLink): ?array
        {
            return null;
        }
        public function createCaptainDocument(int $assistantId, string $name, string $externalLink): ?array
        {
            return null;
        }
        public function syncCaptainDocument(string $documentId): bool
        {
            return true;
        }
        public function listCaptainCustomTools(): array
        {
            return [];
        }
        public function createCaptainCustomTool(array $definition): ?array
        {
            return null;
        }
        public function updateCaptainCustomTool(string $toolId, array $definition): bool
        {
            return true;
        }
        public function listCaptainScenarios(int $assistantId): array
        {
            return [];
        }
        public function createCaptainScenario(int $assistantId, array $definition): ?array
        {
            return null;
        }
        public function updateCaptainScenario(int $assistantId, string $scenarioId, array $definition): bool
        {
            return true;
        }

        public function listCaptainAssistantResponses(int $assistantId): array
        {
            if ($this->throwOnList !== null) {
                throw $this->throwOnList;
            }
            return $this->responses;
        }
    }

    final class FakeFaqCacheRepository implements SupportFaqCacheRepositoryInterface
    {
        public array $replaceAllCalls = [];
        /** @var array<int,array<int,array{externalId:string,question:string,answer:string,syncedAt:string}>> */
        public array $stored = [];

        public function replaceAll(int $contextId, string $locale, array $faqs, int $now): void
        {
            $this->replaceAllCalls[] = [$contextId, $locale, $faqs, $now];
            $this->stored[$contextId] = array_map(
                static fn ($f) => ['externalId' => $f['externalId'], 'question' => $f['question'], 'answer' => $f['answer'], 'syncedAt' => gmdate('Y-m-d H:i:s', $now)],
                $faqs
            );
        }

        public function listApproved(int $contextId, string $locale): array
        {
            return $this->stored[$contextId] ?? [];
        }

        public function lastSyncedAt(int $contextId, string $locale): ?int
        {
            return null;
        }
    }

    // A real, currently-approved response set replaces the cache and reports the real count.
    $client = new FakeCaptainClient();
    $client->responses = [
        ['id' => 101, 'question' => 'How do I submit?', 'answer' => 'Use the submission wizard.'],
        ['id' => 102, 'question' => 'What is the review time?', 'answer' => '8-12 weeks.'],
        ['id' => 103, 'question' => '', 'answer' => 'Incomplete row, must be skipped.'],
    ];
    $repository = new FakeFaqCacheRepository();
    $service = new FaqCacheSyncService($client, $repository);

    $synced = $service->sync(1, 'en', 42, 1000);
    kno011Check($synced === 2, 'sync() must return the count of valid FAQs synced, skipping incomplete rows');
    kno011Check(count($repository->replaceAllCalls) === 1, 'sync() must call replaceAll() exactly once');
    kno011Check(count($repository->stored[1]) === 2, 'the repository must end up holding exactly the 2 valid FAQs');

    // A Chatwoot outage must leave the existing cache untouched, never wipe it.
    $failingClient = new FakeCaptainClient();
    $failingClient->throwOnList = new \RuntimeException('Chatwoot unreachable');
    $failingService = new FaqCacheSyncService($failingClient, $repository);
    $failedResult = $failingService->sync(1, 'en', 42, 2000);
    kno011Check($failedResult === -1, 'sync() must return -1 when the Chatwoot client throws');
    kno011Check(count($repository->replaceAllCalls) === 1, 'a failed sync must never call replaceAll() — the existing cache stays untouched');
    kno011Check(count($repository->stored[1]) === 2, 'the existing cache must be unchanged after a failed sync');

    // ================================================================
    // Part 3: ApprovedFaqKnowledgeProvider — anonymous-safe, local-only reads.
    // ================================================================
    final class FakeFaqContext
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    $provider = new ApprovedFaqKnowledgeProvider($repository);
    kno011Check($provider->providerId() === 'chatwoot.approved_faq', 'provider must expose a stable providerId');

    $facts = $provider->collect(new FakeFaqContext(1), new \stdClass(), 'en');
    kno011Check(count($facts) === 2, 'the provider must produce one KnowledgeFact per cached approved FAQ');
    foreach ($facts as $fact) {
        kno011Check($fact->source() === KnowledgeSourcePrecedence::SOURCE_FAQ, 'every FAQ fact must be tagged with the FAQ precedence source');
        kno011Check(str_starts_with($fact->key(), 'faq.'), 'every FAQ fact key must be namespaced under faq.');
    }
    kno011Check(str_contains($facts[0]->value(), 'How do I submit?'), 'the fact value must include the FAQ question');
    kno011Check(str_contains($facts[0]->value(), 'Use the submission wizard.'), 'the fact value must include the FAQ answer');

    // A journal with nothing synced yet must contribute zero facts, never an error.
    $emptyFacts = $provider->collect(new FakeFaqContext(999), new \stdClass(), 'en');
    kno011Check($emptyFacts === [], 'a context with no cached FAQs must produce zero facts');

    // The provider must degrade to [] rather than throw when the repository itself fails.
    final class ThrowingFaqRepository implements SupportFaqCacheRepositoryInterface
    {
        public function replaceAll(int $contextId, string $locale, array $faqs, int $now): void
        {
        }
        public function listApproved(int $contextId, string $locale): array
        {
            throw new \RuntimeException('DB unavailable');
        }
        public function lastSyncedAt(int $contextId, string $locale): ?int
        {
            return null;
        }
    }
    $throwingProvider = new ApprovedFaqKnowledgeProvider(new ThrowingFaqRepository());
    kno011Check($throwingProvider->collect(new FakeFaqContext(1), new \stdClass(), 'en') === [], 'a repository failure must degrade to an empty fact set, never an uncaught exception');

    // ================================================================
    // Part 3b: ChatwootApiService's real implementation must throw on
    // failure, never degrade to [] — ChatwootApiService itself cannot
    // be instantiated in this environment (no Guzzle/vendor, same
    // constraint TST-002/TST-003 established), so this is a source
    // assertion against the exact real contract, not a mock.
    // ================================================================
    $apiServiceSource = file_get_contents($root . '/ChatwootApiService.php');
    $listStart = strpos($apiServiceSource, 'function listCaptainAssistantResponses');
    kno011Check($listStart !== false, 'ChatwootApiService must implement listCaptainAssistantResponses()');
    $listBody = substr($apiServiceSource, $listStart, 2200);
    kno011Check(!str_contains($listBody, "if (!\$result['ok']) return []"), 'listCaptainAssistantResponses() must never collapse a request failure to an empty array');
    kno011Check(str_contains($listBody, 'throw new \RuntimeException'), 'listCaptainAssistantResponses() must throw on a real request failure');
    kno011Check(str_contains($listBody, 'total_count') && str_contains($listBody, 'count($payload)'), 'listCaptainAssistantResponses() must refuse to treat a partial/paginated page as the complete authoritative set');

    // ================================================================
    // Part 4: wiring — kernel registration, plugin entry point, scheduled task.
    // ================================================================
    $kernelSource = file_get_contents($root . '/classes/v2/SupportGatewayKernel.php');
    kno011Check(str_contains($kernelSource, 'new ApprovedFaqKnowledgeProvider(new DatabaseSupportFaqCacheRepository())'), 'SupportGatewayKernel must register ApprovedFaqKnowledgeProvider with a fresh, network-free repository');

    $pluginSource = file_get_contents($root . '/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php');
    kno011Check(str_contains($pluginSource, 'function syncFaqCache('), 'the plugin must expose a syncFaqCache() entry point');
    kno011Check(str_contains($pluginSource, 'new FaqCacheSyncScheduledTask($this)'), 'registerSchedules() must schedule FaqCacheSyncScheduledTask');

    $taskSource = file_get_contents($root . '/classes/v2/Task/FaqCacheSyncScheduledTask.php');
    kno011Check(str_contains($taskSource, 'syncFaqCache('), 'the scheduled task must call the plugin\'s syncFaqCache() entry point');
    kno011Check(str_contains($taskSource, 'getAll(true)'), 'the scheduled task must loop over every enabled journal, not a single hardcoded one');

    fwrite(STDOUT, "KNO-011 approved FAQ provider tests passed\n");
}
