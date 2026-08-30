<?php

declare(strict_types=1);

namespace PKP\core {
    final class PKPApplication
    {
        public const ROUTE_PAGE = 'page';
    }
}

namespace {

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\CorePublicationKnowledgeProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;

function knowledgePublicationCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakePublicationContext
{
    public function __construct(private array $data, private array $localized = []) {}
    public function getPath(): string { return 'journal-a'; }
    public function getSupportedLocales(): array { return ['en']; }
    public function getPrimaryLocale(): string { return 'en'; }
    public function getData(string $key): mixed { return $this->data[$key] ?? null; }

    public function getLocalizedData(string $key, ?string $preferredLocale = null, ?string &$selectedLocale = null): mixed
    {
        $values = $this->localized[$key] ?? [];
        if ($preferredLocale !== null && array_key_exists($preferredLocale, $values)) {
            $selectedLocale = $preferredLocale;
            return $values[$preferredLocale];
        }
        $selectedLocale = null;
        return null;
    }
}

final class FakePublicationDispatcher
{
    public function url($request, $routeType, $path, $page, $op = null): string
    {
        return "https://example.test/{$path}/{$page}/{$op}";
    }
}

final class FakePublicationRequest
{
    public function getDispatcher(): FakePublicationDispatcher { return new FakePublicationDispatcher(); }
}

$compiler = new KnowledgeCompiler();
$compiler->registerProvider(new CorePublicationKnowledgeProvider());
$request = new FakePublicationRequest();

// ================================================================
// Access model: deterministic sentence per publishingMode, never invented specifics.
// ================================================================
$openContext = new FakePublicationContext(['publishingMode' => 0]);
$openCompilation = $compiler->compile($openContext, $request, 1, 'en');
knowledgePublicationCheck(
    $openCompilation->fact('publication.accessModel')?->value() === 'This journal provides open access to its published content.',
    'PUBLISHING_MODE_OPEN must produce the open-access sentence'
);

$subscriptionContext = new FakePublicationContext(['publishingMode' => 1, 'delayedOpenAccessDuration' => 12]);
$subscriptionCompilation = $compiler->compile($subscriptionContext, $request, 1, 'en');
$subscriptionFact = $subscriptionCompilation->fact('publication.accessModel')?->value();
knowledgePublicationCheck(str_contains((string) $subscriptionFact, 'subscription'), 'PUBLISHING_MODE_SUBSCRIPTION must mention subscription');
knowledgePublicationCheck(str_contains((string) $subscriptionFact, '12 week'), 'a configured delayed-open-access duration must appear verbatim, not a guessed timeline');

$noneContext = new FakePublicationContext(['publishingMode' => 2]);
$noneCompilation = $compiler->compile($noneContext, $request, 1, 'en');
knowledgePublicationCheck(
    str_contains((string) $noneCompilation->fact('publication.accessModel')?->value(), 'does not currently publish'),
    'PUBLISHING_MODE_NONE must produce its own distinct sentence'
);

$missingModeContext = new FakePublicationContext([]);
$missingModeCompilation = $compiler->compile($missingModeContext, $request, 1, 'en');
knowledgePublicationCheck($missingModeCompilation->fact('publication.accessModel') === null, 'a missing/unrecognized publishingMode must omit the fact, never guess a default');

// ================================================================
// DOI policy: boolean-derived, omitted when disabled.
// ================================================================
knowledgePublicationCheck($openCompilation->fact('publication.doiAssigned') === null, 'DOI fact must be omitted when enableDois is false/unset');
$doiContext = new FakePublicationContext(['publishingMode' => 0, 'enableDois' => true]);
$doiCompilation = $compiler->compile($doiContext, $request, 1, 'en');
knowledgePublicationCheck($doiCompilation->fact('publication.doiAssigned')?->value() === 'true', 'enableDois=true must surface publication.doiAssigned');

// ================================================================
// Open-access policy text: localized, sanitized, omitted when unset.
// ================================================================
knowledgePublicationCheck($openCompilation->fact('policy.openAccessPolicy') === null, 'no openAccessPolicy text configured must omit the fact');
$policyTextContext = new FakePublicationContext(
    ['publishingMode' => 0],
    ['openAccessPolicy' => ['en' => '<p>We follow BOAI.</p><script>alert(1)</script>']]
);
$policyTextCompilation = $compiler->compile($policyTextContext, $request, 1, 'en');
$policyText = $policyTextCompilation->fact('policy.openAccessPolicy')?->value();
knowledgePublicationCheck($policyText !== null && str_contains($policyText, 'BOAI'), 'configured open-access policy text must appear');
knowledgePublicationCheck($policyText !== null && !str_contains($policyText, '<script'), 'open-access policy text must be sanitized');

// ================================================================
// Issue URLs: real OJS core routes, never fabricated when the dispatcher is unavailable.
// ================================================================
knowledgePublicationCheck($openCompilation->fact('publication.currentIssueUrl')?->value() === 'https://example.test/journal-a/issue/current', 'current issue URL must use the real issue/current route');
knowledgePublicationCheck($openCompilation->fact('publication.archiveUrl')?->value() === 'https://example.test/journal-a/issue/archive', 'archive URL must use the real issue/archive route');

$noRequestCompilation = $compiler->compile($openContext, new \stdClass(), 1, 'en');
knowledgePublicationCheck($noRequestCompilation->fact('publication.currentIssueUrl') === null, 'without a usable request/dispatcher, the URL fact must be omitted, never fabricated');

// ================================================================
// No submission-specific state ever appears in this provider's source.
// ================================================================
$source = '';
foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Knowledge/CorePublicationKnowledgeProvider.php')) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $source .= is_array($token) ? $token[1] : $token;
}
foreach (['$submission', 'SupportSession', 'getPublicationFields', 'getIssueInfo'] as $forbidden) {
    knowledgePublicationCheck(!str_contains($source, $forbidden), "CorePublicationKnowledgeProvider must never reference \"{$forbidden}\" — that is submission-specific live state, not journal-level knowledge");
}

fwrite(STDOUT, "Knowledge publication tests passed\n");

}
