<?php

declare(strict_types=1);

namespace PKP\db {
    final class DAORegistry
    {
        public static string $version = '3.5.0.0';

        public static function getDAO(string $name): object
        {
            return new class () {
                public function getCurrentVersion(): object
                {
                    return new class () {
                        public function getVersionString(): string
                        {
                            return \PKP\db\DAORegistry::$version;
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

    use APP\plugins\generic\chatwootIntegration\classes\v2\Runtime\RuntimeContextBridge;

    function contextCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeRole
    {
        public function __construct(private int $id)
        {
        }
        public function getId(): int
        {
            return $this->id;
        }
    }

    final class FakeUser
    {
        public function getId(): int
        {
            return 42;
        }
        public function getRoles(int $contextId): array
        {
            return [new FakeRole(65536), new FakeRole(16), new FakeRole(16)];
        }
    }

    final class FakeContext
    {
        public function getId(): int
        {
            return 7;
        }
        public function getPath(): string
        {
            return 'journal-a';
        }
    }

    final class FakeRequest
    {
        public function getContext(): object
        {
            return new FakeContext();
        }
        public function getUser(): object
        {
            return new FakeUser();
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

    $bridge = new RuntimeContextBridge();
    $context = $bridge->resolve(new FakeRequest(), 'en');

    contextCheck($context !== null, 'supported OJS runtime should resolve a support context');
    contextCheck($bridge->resolvedVersion() === '3.5.0.0', 'runtime should use VersionDAO version');
    contextCheck($context->contextId() === 7, 'journal id should be preserved');
    contextCheck($context->contextPath() === 'journal-a', 'journal path should be preserved');
    contextCheck($context->userId() === 42, 'authenticated user should be preserved');
    contextCheck($context->roleIds() === [16, 65536], 'roles should be normalized and deduplicated');
    contextCheck($context->page() === 'submission', 'requested page should be preserved');
    contextCheck($context->operation() === 'wizard', 'requested operation should be preserved');

    \PKP\db\DAORegistry::$version = '3.6.0.0';
    $unsupported = $bridge->resolve(new FakeRequest(), 'en');
    contextCheck($unsupported === null, 'unsupported OJS family must fail closed in v2 context bridge');
    contextCheck($bridge->resolvedVersion() === '3.6.0.0', 'bridge should expose the version it evaluated');

    fwrite(STDOUT, "Runtime context tests passed\n");
}
