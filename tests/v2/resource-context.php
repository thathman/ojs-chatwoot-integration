<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ResourceContextResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;

function resourceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeResource
{
    public function __construct(private int $id, private int $contextId)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getData(string $key): mixed
    {
        return $key === 'contextId' ? $this->contextId : null;
    }
}

final class FakeTemplateManager
{
    public function __construct(private array $vars)
    {
    }
    public function getTemplateVars(string $key): mixed
    {
        return $this->vars[$key] ?? null;
    }
}

final class FakeResourceRequest
{
    public function __construct(private mixed $submissionId = null, private array $args = [])
    {
    }
    public function getUserVar(string $key): mixed
    {
        return $key === 'submissionId' ? $this->submissionId : null;
    }
    public function getRequestedArgs(): array
    {
        return $this->args;
    }
}

$resolver = new ResourceContextResolver();
$workflow = new SupportContext(7, 'journal-a', 42, [65536], 'workflow', 'index', 'en');

$template = new FakeTemplateManager(['submission' => new FakeResource(101, 7)]);
$spoofedRequest = new FakeResourceRequest('999', ['888']);
$detected = $resolver->resolve($workflow, $spoofedRequest, $template);
resourceCheck($detected !== null, 'server template resource should resolve');
resourceCheck($detected->id() === 101, 'server-resolved template resource must outrank user-controlled hints');
resourceCheck($detected->source() === 'template:submission', 'template source should be recorded');
resourceCheck(($detected->toArray()['contract'] ?? '') === 'detection_only', 'resource context must be explicitly non-authoritative');

$articleTemplate = new FakeTemplateManager(['article' => new FakeResource(202, 7)]);
$article = $resolver->resolve(new SupportContext(7, 'journal-a', null, [], 'article', 'view', 'en'), new FakeResourceRequest(), $articleTemplate);
resourceCheck($article?->type() === 'submission' && $article?->id() === 202, 'public article template should normalize to submission resource');
resourceCheck($article?->source() === 'template:article', 'article template source should be preserved');

$crossContextTemplate = new FakeTemplateManager(['submission' => new FakeResource(101, 8)]);
$crossContext = $resolver->resolve($workflow, new FakeResourceRequest('999', ['888']), $crossContextTemplate);
resourceCheck($crossContext === null, 'cross-journal server resource must fail closed instead of falling back to request hints');

$parameter = $resolver->resolve($workflow, new FakeResourceRequest('303'), new FakeTemplateManager([]));
resourceCheck($parameter?->id() === 303 && $parameter?->source() === 'request_parameter', 'explicit submissionId may be detected as a non-authoritative hint');

$route = $resolver->resolve($workflow, new FakeResourceRequest(null, ['404', 'extra']), null);
resourceCheck($route?->id() === 404 && $route?->source() === 'known_route', 'known workflow route first argument may be detected');

$submissionPage = new SupportContext(7, 'journal-a', 42, [65536], 'submission', 'wizard', 'en');
$unsafeRoute = $resolver->resolve($submissionPage, new FakeResourceRequest(null, ['2', '505']), null);
resourceCheck($unsafeRoute === null, 'arbitrary numeric submission-page route arguments must not be guessed as resource IDs');

$badParameter = $resolver->resolve($workflow, new FakeResourceRequest('101 OR 1=1'), null);
resourceCheck($badParameter === null, 'non-canonical numeric input must be rejected');

fwrite(STDOUT, "Resource context tests passed\n");
