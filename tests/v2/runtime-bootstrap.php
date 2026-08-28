<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\ExportPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\SupportGatewayKernel;

function runtimeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

runtimeCheck(class_exists(Ojs35CompatibilityAdapter::class), 'scoped autoloader should load compatibility classes');
runtimeCheck(class_exists(ExportPolicy::class), 'scoped autoloader should load settings classes');
runtimeCheck(class_exists(SupportGatewayKernel::class), 'scoped autoloader should load root v2 classes');
runtimeCheck(SupportGatewayKernel::forOjsVersion('3.5.0.0') !== null, 'kernel should compose through autoloaded dependencies');
runtimeCheck(SupportGatewayKernel::forOjsVersion('3.6.0.0') === null, 'autoloading must not weaken compatibility fail-closed behavior');

fwrite(STDOUT, "Runtime bootstrap tests passed\n");
