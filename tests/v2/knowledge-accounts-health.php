<?php

declare(strict_types=1);

namespace PKP\core {
    final class PKPApplication
    {
        public const ROUTE_PAGE = 'page';
    }
}

namespace PKP\orcid {
    final class OrcidManager
    {
        public static bool $enabled = false;
        public static function isEnabled($context = null): bool { return self::$enabled; }
    }
}

namespace PKP\plugins {
    final class PluginRegistry
    {
        /** @var array<string,array<string,object>> */
        public static array $plugins = [];
        public static function loadCategory(string $category, bool $enabledOnly = false): array { return self::$plugins[$category] ?? []; }
        public static function getPlugin(string $category, string $name): ?object { return self::$plugins[$category][$name] ?? null; }
    }
}

namespace APP\plugins\generic\magicLogin {
    /** Mirrors only the surface AccountsKnowledgeProvider/adapter actually call — see a real local checkout of Airix360/ojs-magic-login. */
    final class MagicLoginPlugin
    {
        public function __construct(private bool $pluginEnabled, private bool $settingEnabled) {}
        public function getEnabled(int $contextId): bool { return $this->pluginEnabled; }
        public function getSetting(int $contextId, string $name): mixed { return $name === 'enabled' ? $this->settingEnabled : null; }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\AccountsKnowledgeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeClassification;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeFact;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthReport;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeHealthService;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeRouteCatalog;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeSitemapRenderer;

    function knowledgeAccountsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    class FakeAccountsContext
    {
        public function __construct(private array $data) {}
        public function getId(): int { return 1; }
        public function getPath(): string { return 'journal-a'; }
        public function getSupportedLocales(): array { return ['en']; }
        public function getPrimaryLocale(): string { return 'en'; }
        public function getData(string $key): mixed { return $this->data[$key] ?? null; }
    }

    final class FakeAccountsDispatcher
    {
        public function url($request, $routeType, $path, $page, $op = null): string
        {
            return $op !== null ? "https://example.test/{$path}/{$page}/{$op}" : "https://example.test/{$path}/{$page}";
        }
    }

    final class FakeAccountsRequest
    {
        public function getDispatcher(): FakeAccountsDispatcher { return new FakeAccountsDispatcher(); }
    }

    // ================================================================
    // Part 1: AccountsKnowledgeProvider.
    // ================================================================
    \PKP\plugins\PluginRegistry::$plugins = [];
    $adapter = new Ojs35CompatibilityAdapter();
    $compiler = new KnowledgeCompiler();
    $compiler->registerProvider(new AccountsKnowledgeProvider($adapter));
    $request = new FakeAccountsRequest();

    \PKP\orcid\OrcidManager::$enabled = false;
    $openRegContext = new FakeAccountsContext(['disableUserReg' => false]);
    $openRegCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.registrationAvailable')?->value() === 'true', 'registration-enabled journal must report registrationAvailable=true');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.registrationUrl')?->value() === 'https://example.test/journal-a/user/register', 'registration URL must use the real user/register route');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.loginUrl')?->value() === 'https://example.test/journal-a/login', 'login URL must use the real login route');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.passwordResetUrl')?->value() === 'https://example.test/journal-a/login/lostPassword', 'password-reset URL must use the real login/lostPassword route');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.orcidEnabled')?->value() === 'false', 'ORCID disabled must report orcidEnabled=false');

    $disabledRegContext = new FakeAccountsContext(['disableUserReg' => true]);
    $disabledRegCompilation = $compiler->compile($disabledRegContext, $request, 2, 'en');
    knowledgeAccountsCheck($disabledRegCompilation->fact('accounts.registrationAvailable')?->value() === 'false', 'disabled registration must report registrationAvailable=false');
    knowledgeAccountsCheck($disabledRegCompilation->fact('accounts.registrationUrl') === null, 'disabled registration must never publish a misleading registration URL');
    knowledgeAccountsCheck($disabledRegCompilation->fact('accounts.loginUrl') !== null, 'login must remain available even when registration is disabled');
    knowledgeAccountsCheck($disabledRegCompilation->fact('accounts.passwordResetUrl') !== null, 'password reset must remain available even when registration is disabled');

    \PKP\orcid\OrcidManager::$enabled = true;
    $orcidCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($orcidCompilation->fact('accounts.orcidEnabled')?->value() === 'true', 'ORCID enabled must report orcidEnabled=true');

    // ================================================================
    // Airix Magic Login availability: absent/disabled/enabled, URL correctness, no email lookup.
    // ================================================================
    $noMagicLoginCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($noMagicLoginCompilation->fact('accounts.magicLoginEnabled') === null, 'no magic-login fact when the plugin is absent');

    \PKP\plugins\PluginRegistry::$plugins['generic']['magicloginplugin'] = new \APP\plugins\generic\magicLogin\MagicLoginPlugin(false, true);
    $pluginDisabledCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($pluginDisabledCompilation->fact('accounts.magicLoginEnabled') === null, 'no magic-login fact when the plugin itself is disabled, even if its own "enabled" setting is on');

    \PKP\plugins\PluginRegistry::$plugins['generic']['magicloginplugin'] = new \APP\plugins\generic\magicLogin\MagicLoginPlugin(true, false);
    $settingDisabledCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($settingDisabledCompilation->fact('accounts.magicLoginEnabled') === null, 'no magic-login fact when the journal has not opted in via its own "enabled" setting');

    \PKP\plugins\PluginRegistry::$plugins['generic']['magicloginplugin'] = new \APP\plugins\generic\magicLogin\MagicLoginPlugin(true, true);
    $magicLoginCompilation = $compiler->compile($openRegContext, $request, 1, 'en');
    knowledgeAccountsCheck($magicLoginCompilation->fact('accounts.magicLoginEnabled')?->value() === 'true', 'enabled plugin + enabled setting must surface magicLoginEnabled=true');
    knowledgeAccountsCheck($magicLoginCompilation->fact('accounts.magicLoginUrl')?->value() === 'https://example.test/journal-a/magicLogin/request', 'magic-login URL must use the real magicLogin/request route');

    // No individual account data / no email lookup anywhere in this provider's source.
    $providerSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Knowledge/AccountsKnowledgeProvider.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $providerSource .= is_array($token) ? $token[1] : $token;
    }
    foreach (['getUserByEmail', 'getUserById', '$email', 'SupportSession', 'getEnabled()->', 'sendVerificationLink', 'confirm(', 'token'] as $forbidden) {
        knowledgeAccountsCheck(!str_contains($providerSource, $forbidden), "AccountsKnowledgeProvider must never reference \"{$forbidden}\" — this is help information, never account state or an identity lookup");
    }

    // ================================================================
    // Part 2: multi-journal accounts URL isolation.
    // ================================================================
    final class MultiPathAccountsContext extends FakeAccountsContext
    {
        public function __construct(private string $journalPath, array $data) { parent::__construct($data); }
        public function getPath(): string { return $this->journalPath; }
    }
    $contextB = new MultiPathAccountsContext('journal-b', ['disableUserReg' => false]);
    $compilationB = $compiler->compile($contextB, $request, 3, 'en');
    knowledgeAccountsCheck($compilationB->fact('accounts.registrationUrl')?->value() === 'https://example.test/journal-b/user/register', 'each journal must get its own scoped registration URL, never another journal\'s path');
    knowledgeAccountsCheck($openRegCompilation->fact('accounts.registrationUrl')?->value() !== $compilationB->fact('accounts.registrationUrl')?->value(), 'two journals must never share the same accounts URL');

    // ================================================================
    // Part 3: KnowledgeRouteCatalog — single source for nav + sitemap.
    // ================================================================
    knowledgeAccountsCheck(in_array('accounts', KnowledgeRouteCatalog::categories(), true), 'route catalog must include the new accounts category');
    knowledgeAccountsCheck(KnowledgeRouteCatalog::keyPrefixFor('accounts') === 'accounts.', 'accounts category must map to the accounts. key prefix');
    knowledgeAccountsCheck(KnowledgeRouteCatalog::keyPrefixFor('nonexistent') === null, 'an unrecognized category must resolve to null, not a guessed prefix');

    $sitemapUrls = ['https://example.test/journal-a/support-knowledge'];
    foreach (KnowledgeRouteCatalog::categories() as $category) {
        $sitemapUrls[] = "https://example.test/journal-a/support-knowledge/{$category}";
    }
    $sitemapXml = KnowledgeSitemapRenderer::render($sitemapUrls);
    knowledgeAccountsCheck(str_starts_with($sitemapXml, '<?xml version="1.0" encoding="UTF-8"?>'), 'sitemap must start with a valid XML declaration');
    knowledgeAccountsCheck(str_contains($sitemapXml, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'), 'sitemap must use the real sitemap.org namespace');
    foreach ($sitemapUrls as $url) {
        knowledgeAccountsCheck(substr_count($sitemapXml, "<loc>{$url}</loc>") === 1, "sitemap must contain each generated route exactly once ({$url})");
    }
    foreach (['ojsSupportGateway', 'verificationRequest', 'verificationConfirm', '/admin', 'submissionVerify'] as $forbiddenRoute) {
        knowledgeAccountsCheck(!str_contains($sitemapXml, $forbiddenRoute), "sitemap must never contain a Support API/verification/admin route (\"{$forbiddenRoute}\")");
    }

    $xmlSpecialUrl = 'https://example.test/journal-a?x=1&y=2';
    $escapedXml = KnowledgeSitemapRenderer::render([$xmlSpecialUrl]);
    knowledgeAccountsCheck(str_contains($escapedXml, '&amp;'), 'sitemap must XML-escape special characters (& -> &amp;), not leave raw XML-breaking characters');
    knowledgeAccountsCheck(!str_contains($escapedXml, 'x=1&y=2'), 'raw unescaped ampersand must never appear in sitemap output');

    // ================================================================
    // Part 4: KnowledgeHealthService.
    // ================================================================
    final class ThrowingHealthProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.throwing_health'; }
        public function collect($context, $request, string $locale): array { throw new \RuntimeException('boom'); }
    }
    final class OkHealthProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.ok_health'; }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('healthtest.fact', 'value', KnowledgeClassification::PUBLIC, 'ojs.context', $locale, $this->providerId())];
        }
    }
    final class EmptyHealthProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.empty_health'; }
        public function collect($context, $request, string $locale): array { return []; }
    }

    // Healthy: one provider succeeds with facts, no failures.
    $healthyCompiler = new KnowledgeCompiler();
    $healthyCompiler->registerProvider(new OkHealthProvider());
    $healthyService = new KnowledgeHealthService($healthyCompiler);
    $healthyReport = $healthyService->buildReport(new FakeAccountsContext([]), $request, 1, 'en');
    knowledgeAccountsCheck($healthyReport->state() === KnowledgeHealthReport::STATE_HEALTHY, 'a successful provider with facts must report healthy');
    knowledgeAccountsCheck($healthyReport->publicFactCount() === 1, 'health must report the actual public fact count');
    knowledgeAccountsCheck($healthyReport->fingerprint() !== '', 'health fingerprint must equal the real compilation fingerprint, never blank');
    knowledgeAccountsCheck($healthyReport->generatedRoutes() === KnowledgeRouteCatalog::categories(), 'health must report the same generated routes as the route catalog');

    // Empty: optional provider absent must never look like failure.
    $emptyCompiler = new KnowledgeCompiler();
    $emptyCompiler->registerProvider(new EmptyHealthProvider());
    $emptyReport = (new KnowledgeHealthService($emptyCompiler))->buildReport(new FakeAccountsContext([]), $request, 1, 'en');
    knowledgeAccountsCheck($emptyReport->state() === KnowledgeHealthReport::STATE_EMPTY, 'zero public facts with no failures must report empty, not failed');
    knowledgeAccountsCheck($emptyReport->failedProviders() === [], 'an optional provider producing nothing must never appear as a failed provider');

    // Degraded: one provider throws, another succeeds.
    $degradedCompiler = new KnowledgeCompiler();
    $degradedCompiler->registerProvider(new ThrowingHealthProvider());
    $degradedCompiler->registerProvider(new OkHealthProvider());
    $degradedReport = (new KnowledgeHealthService($degradedCompiler))->buildReport(new FakeAccountsContext([]), $request, 1, 'en');
    knowledgeAccountsCheck($degradedReport->state() === KnowledgeHealthReport::STATE_DEGRADED, 'one throwing provider alongside a healthy one must report degraded, not failed/healthy');
    knowledgeAccountsCheck($degradedReport->failedProviders() === ['test.throwing_health'], 'the throwing provider must be identified by id in failedProviders');
    knowledgeAccountsCheck($degradedReport->healthyProviders() === ['test.ok_health'], 'the succeeding provider must be identified by id in healthyProviders');
    knowledgeAccountsCheck($degradedReport->publicFactCount() === 1, 'degraded health must still report the facts the healthy provider produced — public pages are not broken by an unrelated failure');

    // Failed: every provider throws.
    $failedCompiler = new KnowledgeCompiler();
    $failedCompiler->registerProvider(new ThrowingHealthProvider());
    $failedReport = (new KnowledgeHealthService($failedCompiler))->buildReport(new FakeAccountsContext([]), $request, 1, 'en');
    knowledgeAccountsCheck($failedReport->state() === KnowledgeHealthReport::STATE_FAILED, 'every provider throwing must report failed');

    // ================================================================
    // Part 5: conflict health metadata — safe fields only, never losing values.
    // ================================================================
    final class StructuredConflictProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.structured_conflict'; }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('fee.publicationAmount', '100.00', KnowledgeClassification::PUBLIC, 'ojs.context', $locale, $this->providerId())];
        }
    }
    final class StalePageConflictProvider implements KnowledgeProviderInterface
    {
        public function providerId(): string { return 'test.stale_conflict'; }
        public function collect($context, $request, string $locale): array
        {
            return [new KnowledgeFact('fee.publicationAmount', '75.00', KnowledgeClassification::PUBLIC, 'ojs.static_page', $locale, $this->providerId())];
        }
    }
    $conflictCompiler = new KnowledgeCompiler();
    $conflictCompiler->registerProvider(new StructuredConflictProvider());
    $conflictCompiler->registerProvider(new StalePageConflictProvider());
    $conflictReport = (new KnowledgeHealthService($conflictCompiler))->buildReport(new FakeAccountsContext([]), $request, 1, 'en');
    knowledgeAccountsCheck($conflictReport->conflictCount() === 1, 'health must report the actual conflict count');
    $conflictMeta = $conflictReport->conflicts()[0];
    knowledgeAccountsCheck($conflictMeta['key'] === 'fee.publicationAmount', 'conflict metadata must include the colliding key');
    knowledgeAccountsCheck($conflictMeta['winnerSource'] === 'ojs.context', 'conflict metadata must include the winning source');
    knowledgeAccountsCheck($conflictMeta['loserSource'] === 'ojs.static_page', 'conflict metadata must include the losing source');
    knowledgeAccountsCheck(!in_array('75.00', $conflictMeta, true), 'conflict metadata must never repeat the losing fact\'s value');
    knowledgeAccountsCheck(!in_array('100.00', $conflictMeta, true), 'conflict metadata must never repeat the winning fact\'s value either — only key/source, per the freeze directive');

    // ================================================================
    // Part 6: health never consults SupportSession/Chatwoot identity.
    // ================================================================
    $healthSource = '';
    foreach (['KnowledgeHealthReport.php', 'KnowledgeHealthService.php'] as $file) {
        foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Knowledge/' . $file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $healthSource .= is_array($token) ? $token[1] : $token;
        }
    }
    foreach (['SupportSession', 'ChatwootConversation', 'conversationId', 'chatwootApiAccessToken'] as $forbidden) {
        knowledgeAccountsCheck(!str_contains($healthSource, $forbidden), "Knowledge health code must never reference \"{$forbidden}\"");
    }

    fwrite(STDOUT, "Knowledge accounts/sitemap/health tests passed\n");
}
