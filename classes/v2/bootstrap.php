<?php

/**
 * Lightweight PSR-4-style autoloader for v2 Support Gateway classes.
 *
 * The legacy plugin files remain loaded exactly as OJS expects. This loader is
 * deliberately scoped to the v2 namespace so it cannot shadow OJS, PKP or v1
 * plugin classes.
 */

$prefix = 'APP\\plugins\\generic\\chatwootIntegration\\classes\\v2\\';
$baseDir = __DIR__ . DIRECTORY_SEPARATOR;

spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || str_contains($relative, '..')) {
        return;
    }

    $path = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
