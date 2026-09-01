<?php

declare(strict_types=1);

namespace APP\facades {
    final class Repo
    {
        /** @var array<int,object> */
        public static array $usersById = [];

        public static function user(): self
        {
            return new self();
        }

        public function get(int $id): ?object
        {
            return self::$usersById[$id] ?? null;
        }
    }
}

namespace {

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ContextResolver;

    function contextResolverIsolationCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class DummyRoleForIsolation
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    final class DummyUserForIsolation
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

    final class DummyContextForIsolation
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

    final class DummyRequestForIsolation
    {
        public function __construct(private $context, private $user, private string $page, private string $op)
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
        public function getRequestedPage()
        {
            return $this->page;
        }
        public function getRequestedOp()
        {
            return $this->op;
        }
    }

    \APP\facades\Repo::$usersById[42] = new DummyUserForIsolation(42, [new DummyRoleForIsolation(65538)]);

    $adapter = new Ojs35CompatibilityAdapter();
    $resolver = new ContextResolver($adapter);

    // ================================================================
    // CTX-009: multi-journal isolation for ContextResolver/SupportContext
    // itself — a request bound to journal A must never see journal B's
    // data, and identically-numbered users under different journals must
    // never cross-contaminate roles.
    // ================================================================
    $requestA = new DummyRequestForIsolation(
        new DummyContextForIsolation(7, 'journal-a'),
        new DummyUserForIsolation(42, [new DummyRoleForIsolation(65536)]), // reviewer only, in journal A
        'submission',
        'wizard'
    );
    $requestB = new DummyRequestForIsolation(
        new DummyContextForIsolation(9, 'journal-b'),
        new DummyUserForIsolation(42, [new DummyRoleForIsolation(16)]), // same user id, different roles, in journal B
        'workflow',
        'index'
    );

    $contextA = $resolver->resolve($requestA, 'en');
    $contextB = $resolver->resolve($requestB, 'en');

    contextResolverIsolationCheck($contextA !== null && $contextB !== null, 'both requests must resolve independently');
    contextResolverIsolationCheck($contextA->contextId() === 7 && $contextB->contextId() === 9, 'each context must carry its own real journal id');
    contextResolverIsolationCheck($contextA->contextPath() === 'journal-a' && $contextB->contextPath() === 'journal-b', 'each context must carry its own real journal path');
    contextResolverIsolationCheck($contextA->roleIds() === [65536] && $contextB->roleIds() === [16], 'the same numeric user id under two different journals must never share role evidence');
    contextResolverIsolationCheck($contextA->page() === 'submission' && $contextB->page() === 'workflow', 'each context must carry its own real page/operation, never the other journal\'s');

    // resolveForUser() must be equally isolated when re-deriving roles live
    // for a specific user id under a specific journal.
    $rerivedA = $resolver->resolveForUser($requestA, 42, 'en');
    $rerivedB = $resolver->resolveForUser($requestB, 42, 'en');
    contextResolverIsolationCheck($rerivedA->contextId() === 7 && $rerivedB->contextId() === 9, 'resolveForUser() must preserve each request\'s own journal, never swap them');

    // A request whose context id is 0/invalid must fail closed rather than
    // accidentally resolving into another journal's context.
    $invalidRequest = new DummyRequestForIsolation(new DummyContextForIsolation(0, ''), null, 'index', 'index');
    contextResolverIsolationCheck($resolver->resolve($invalidRequest, 'en') === null, 'a zero/invalid context id must fail closed, never fall back to a different journal');

    // ================================================================
    // CTX-010: locale handling — ContextResolver has no normalization
    // logic of its own (real locale negotiation is PKP\facades\Locale's
    // job, called by every real caller before constructing $locale); this
    // proves the class faithfully stores whatever it's given, including the
    // documented empty-string default, rather than silently mangling it.
    // ================================================================
    $defaultLocaleContext = $resolver->resolve($requestA);
    contextResolverIsolationCheck($defaultLocaleContext->locale() === '', 'omitting $locale must default to the documented empty string, never a guessed locale');

    $explicitLocaleContext = $resolver->resolve($requestA, 'fr_CA');
    contextResolverIsolationCheck($explicitLocaleContext->locale() === 'fr_CA', 'an explicit locale must be stored verbatim, never normalized/truncated by this class');

    // Two requests for the same journal with different locales must resolve
    // independently — a locale is per-request, never sticky across calls.
    $secondLocaleContext = $resolver->resolve($requestA, 'de_DE');
    contextResolverIsolationCheck($explicitLocaleContext->locale() === 'fr_CA' && $secondLocaleContext->locale() === 'de_DE', 'locale must never leak or stick between independent resolve() calls');

    fwrite(STDOUT, "Context resolver isolation tests passed\n");

}
