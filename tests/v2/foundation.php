<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\CompatibilityAdapterFactory;
use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ContextResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\SupportGatewayKernel;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class DummyRole
{
    public function __construct(private int $id)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
}

final class DummyUser
{
    public function __construct(private int $id, private array $roles)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getRoles(int $contextId): array
    {
        return $this->roles;
    }
}

final class DummyContext
{
    public function __construct(private int $id, private string $path)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getPath(): string
    {
        return $this->path;
    }
}

final class DummyRequest
{
    public function __construct(private $context, private $user = null)
    {
    }
    public function getContext()
    {
        return $this->context;
    }
    public function getUser()
    {
        return $this->user;
    }
    public function getRequestedPage(): string
    {
        return 'submission';
    }
    public function getRequestedOp(): string
    {
        return 'wizard';
    }
}

$adapter = new Ojs35CompatibilityAdapter();
check($adapter->supportsVersion('3.5.0.0'), 'OJS 3.5.0.0 should be supported by the 3.5 adapter');
check($adapter->supportsVersion('3.5.0.3'), 'OJS 3.5 patch releases should be recognized');
check(!$adapter->supportsVersion('3.6.0.0'), 'OJS 3.6 must not silently use the 3.5 adapter');
check(CompatibilityAdapterFactory::forVersion('3.6.0.0') === null, 'unsupported OJS families must fail closed');

$request = new DummyRequest(
    new DummyContext(7, 'journal-a'),
    new DummyUser(42, [new DummyRole(65536), new DummyRole(16), new DummyRole(16)])
);
$resolver = new ContextResolver($adapter);
$context = $resolver->resolve($request, 'en');
check($context !== null, 'context should resolve');
check($context->contextId() === 7, 'context id should be preserved');
check($context->contextPath() === 'journal-a', 'context path should be preserved');
check($context->userId() === 42 && $context->isAuthenticated(), 'logged-in user should be represented');
check($context->roleIds() === [16, 65536], 'role ids should be unique and sorted');
check($context->page() === 'submission' && $context->operation() === 'wizard', 'page/op should be captured');
check($context->locale() === 'en', 'locale should be captured');

$guest = $resolver->resolve(new DummyRequest(new DummyContext(8, 'journal-b')));
check($guest !== null && !$guest->isAuthenticated(), 'guest context should resolve without inventing a user');
check($guest->roleIds() === [], 'guest must not have roles');

$filtered = ExportPolicy::filter([
    'chatwootBaseUrl' => 'https://chat.example.test',
    'chatwootWebsiteToken' => 'public-widget-token',
    'chatwootApiAccessToken' => 'must-not-export',
    'chatwootIdentityValidationSecret' => 'must-not-export-either',
    'enableWidget' => true,
]);
check(!array_key_exists('chatwootApiAccessToken', $filtered['settings']), 'API token must be removed from exports');
check(!array_key_exists('chatwootIdentityValidationSecret', $filtered['settings']), 'identity secret must be removed from exports');
check(($filtered['settings']['chatwootWebsiteToken'] ?? null) === 'public-widget-token', 'website token remains exportable configuration');
check($filtered['redactedKeys'] === ['chatwootApiAccessToken', 'chatwootIdentityValidationSecret'], 'redaction must be explicit and deterministic');

$kernel = SupportGatewayKernel::forOjsVersion('3.5.0.3');
check($kernel !== null, 'kernel should compose for supported OJS 3.5');
check($kernel->resolveContext($request, 'en')?->userId() === 42, 'kernel should resolve context through the adapter');
check(SupportGatewayKernel::forOjsVersion('3.6.0.0') === null, 'kernel must not claim unimplemented OJS 3.6 support');

fwrite(STDOUT, "Foundation tests passed\n");
