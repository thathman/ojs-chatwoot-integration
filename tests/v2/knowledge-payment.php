<?php

declare(strict_types=1);

namespace APP\payment\ojs {
    /** Mirrors only the two real OJSPaymentManager methods the adapter calls — see tests/v2/payment-status.php. */
    final class OJSPaymentManager
    {
        public function __construct(private $context)
        {
        }
        public function isConfigured(): bool
        {
            return (bool) $this->context->getData('paymentsEnabled');
        }
        public function publicationEnabled(): bool
        {
            $fee = $this->context->getData('publicationFee');
            return $this->isConfigured() && is_numeric($fee) && (float) $fee > 0;
        }
    }
}

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

namespace APP\plugins\generic\submissionFee {
    /** Mirrors only the surface CorePaymentKnowledgeProvider/adapter actually call — see tests/v2/provider-registry.php. */
    final class SubmissionFeePlugin
    {
        public function __construct(private bool $enabled)
        {
        }
        public function getEnabled(): bool
        {
            return $this->enabled;
        }
        public function getCurrentVersion(): object
        {
            return new class () {
                public function getVersionString(): string
                {
                    return '1.7.0.0';
                }
            };
        }
    }

    /** Reads controllable state straight off $context, since the adapter constructs its own instance internally. */
    final class PaymentHelper
    {
        public function __construct(private SubmissionFeePlugin $plugin)
        {
        }
        public function feeEnabled($context): bool
        {
            return (bool) $context->getData('airixFeeEnabled');
        }
        public function amount($context): float
        {
            return (float) $context->getData('airixFeeAmount');
        }
        public function currency($context): string
        {
            return (string) ($context->getData('airixFeeCurrency') ?? 'USD');
        }
    }
}

namespace APP\plugins\generic\requestWaiver {
    /** Mirrors only the surface CorePaymentKnowledgeProvider/adapter actually call — see a real local checkout of Airix360/ojs-request-waiver. */
    final class RequestWaiverPlugin
    {
        public array $settings = [];
        public function __construct(private bool $enabled, private ?string $activeFeeType)
        {
        }
        public function getEnabled(int $contextId): bool
        {
            return $this->enabled;
        }
        public function activeFeeType($context): ?string
        {
            return $this->activeFeeType;
        }
        public function getSetting(int $contextId, string $name): mixed
        {
            return $this->settings[$name] ?? null;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\CorePaymentKnowledgeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge\KnowledgeCompiler;

    function knowledgePaymentCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakePaymentContext
    {
        public function __construct(private array $data)
        {
        }
        public function getId(): int
        {
            return 7;
        }
        public function getData(string $key): mixed
        {
            return $this->data[$key] ?? null;
        }
        public function getSupportedLocales(): array
        {
            return ['en'];
        }
        public function getPrimaryLocale(): string
        {
            return 'en';
        }
    }

    $adapter = new Ojs35CompatibilityAdapter();
    $compiler = new KnowledgeCompiler();
    $compiler->registerProvider(new CorePaymentKnowledgeProvider($adapter));

    // ================================================================
    // Native OJS publication fee: disabled/zero must omit, configured must appear.
    // ================================================================
    \PKP\plugins\PluginRegistry::$plugins = [];

    $noFeeContext = new FakePaymentContext(['paymentsEnabled' => false, 'publicationFee' => 0.0]);
    $noFeeCompilation = $compiler->compile($noFeeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($noFeeCompilation->fact('fee.publicationEnabled')?->value() === 'false', 'disabled payments must report publicationEnabled=false');
    knowledgePaymentCheck($noFeeCompilation->fact('fee.publicationAmount') === null, 'no amount fact when publication fee is disabled');

    $feeContext = new FakePaymentContext(['paymentsEnabled' => true, 'publicationFee' => 50.0, 'currency' => 'USD']);
    $feeCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($feeCompilation->fact('fee.publicationEnabled')?->value() === 'true', 'configured publication fee must report publicationEnabled=true');
    knowledgePaymentCheck($feeCompilation->fact('fee.publicationAmount')?->value() === '50.00', 'configured publication fee amount must appear formatted');
    knowledgePaymentCheck($feeCompilation->fact('fee.publicationCurrency')?->value() === 'USD', 'configured publication fee currency must appear');

    // ================================================================
    // Airix Submission Fee policy: only enabled+amount+currency ever surface — never obligation state.
    // ================================================================
    $airixPlugin = new \APP\plugins\generic\submissionFee\SubmissionFeePlugin(true);
    \PKP\plugins\PluginRegistry::$plugins['generic']['submissionfeeplugin'] = $airixPlugin;

    $airixDisabledContext = new FakePaymentContext(['paymentsEnabled' => false, 'publicationFee' => 0.0, 'airixFeeEnabled' => false]);
    $airixDisabledCompilation = $compiler->compile($airixDisabledContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($airixDisabledCompilation->fact('fee.submissionEnabled') === null, 'Airix plugin installed but fee disabled must surface no submission-fee facts');

    $airixEnabledContext = new FakePaymentContext([
        'paymentsEnabled' => false, 'publicationFee' => 0.0,
        'airixFeeEnabled' => true, 'airixFeeAmount' => 25.0, 'airixFeeCurrency' => 'NGN',
    ]);
    $airixEnabledCompilation = $compiler->compile($airixEnabledContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($airixEnabledCompilation->fact('fee.submissionEnabled')?->value() === 'true', 'Airix submission fee policy must surface when the sibling plugin reports it enabled');
    knowledgePaymentCheck($airixEnabledCompilation->fact('fee.submissionAmount')?->value() === '25.00', 'Airix submission fee amount must surface formatted');
    knowledgePaymentCheck($airixEnabledCompilation->fact('fee.submissionCurrency')?->value() === 'NGN', 'Airix submission fee currency must surface');
    knowledgePaymentCheck($airixEnabledCompilation->fact('fee.publicationEnabled')?->value() === 'false', 'native and Airix fee facts must coexist under distinct keys, never overwrite each other');

    // ================================================================
    // Airix Request Waiver policy text: only journal-configured boxTitle/boxBody
    // ever surface — never a submission's waiver decision/history/percent.
    // ================================================================
    $noWaiverPluginCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($noWaiverPluginCompilation->fact('fee.waiverPolicy') === null, 'no waiver fact when the Request Waiver plugin is absent');

    $disabledWaiverPlugin = new \APP\plugins\generic\requestWaiver\RequestWaiverPlugin(false, 'publication');
    \PKP\plugins\PluginRegistry::$plugins['generic']['requestwaiverplugin'] = $disabledWaiverPlugin;
    $disabledWaiverCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($disabledWaiverCompilation->fact('fee.waiverPolicy') === null, 'no waiver fact when the Request Waiver plugin is disabled');

    $noActiveFeeWaiverPlugin = new \APP\plugins\generic\requestWaiver\RequestWaiverPlugin(true, null);
    $noActiveFeeWaiverPlugin->settings = ['boxTitle' => 'Request a Fee Waiver', 'boxBody' => 'Tell us why you need a waiver.'];
    \PKP\plugins\PluginRegistry::$plugins['generic']['requestwaiverplugin'] = $noActiveFeeWaiverPlugin;
    $noActiveFeeCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($noActiveFeeCompilation->fact('fee.waiverPolicy') === null, 'no waiver fact when no fee is currently active (activeFeeType() null), even if boxBody is configured');

    $activeWaiverPlugin = new \APP\plugins\generic\requestWaiver\RequestWaiverPlugin(true, 'publication');
    $activeWaiverPlugin->settings = ['boxTitle' => 'Request a Fee Waiver', 'boxBody' => '<p>Tell us why you need a waiver.</p><script>alert(1)</script>'];
    \PKP\plugins\PluginRegistry::$plugins['generic']['requestwaiverplugin'] = $activeWaiverPlugin;
    $activeWaiverCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    $waiverFact = $activeWaiverCompilation->fact('fee.waiverPolicy');
    knowledgePaymentCheck($waiverFact !== null, 'an active fee with configured waiver text must surface fee.waiverPolicy');
    knowledgePaymentCheck(str_contains($waiverFact->value(), 'Request a Fee Waiver'), 'waiver policy fact must include the configured title');
    knowledgePaymentCheck(str_contains($waiverFact->value(), 'Tell us why you need a waiver'), 'waiver policy fact must include the configured body');
    knowledgePaymentCheck(!str_contains($waiverFact->value(), '<script'), 'waiver policy text must be sanitized');

    $emptyBodyWaiverPlugin = new \APP\plugins\generic\requestWaiver\RequestWaiverPlugin(true, 'submission');
    $emptyBodyWaiverPlugin->settings = ['boxTitle' => 'Waivers', 'boxBody' => ''];
    \PKP\plugins\PluginRegistry::$plugins['generic']['requestwaiverplugin'] = $emptyBodyWaiverPlugin;
    $emptyBodyCompilation = $compiler->compile($feeContext, new \stdClass(), 7, 'en');
    knowledgePaymentCheck($emptyBodyCompilation->fact('fee.waiverPolicy') === null, 'an empty configured body must omit the fact rather than publishing a title-only stub');

    $policySource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Knowledge/CorePaymentKnowledgeProvider.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $policySource .= is_array($token) ? $token[1] : $token;
    }
    foreach (['$submission', 'hasPaid', 'waiverDiscount', 'needsRefundReview', 'resolveObligation', "'paid'", "'unpaid'", "'refund_review'", "'refunded'", 'waiverStatus', 'waiverPercent', 'waiverHistory'] as $forbidden) {
        knowledgePaymentCheck(!str_contains($policySource, $forbidden), "CorePaymentKnowledgeProvider must never reference \"{$forbidden}\" — it reads policy only, never obligation state");
    }

    $adapterMethodSource = '';
    foreach (token_get_all((string) file_get_contents($root . '/classes/v2/Compatibility/Ojs35CompatibilityAdapter.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $adapterMethodSource .= is_array($token) ? $token[1] : $token;
    }
    $policyStart = strpos($adapterMethodSource, 'function getAirixSubmissionFeePolicy');
    $policyEnd = strpos($adapterMethodSource, 'function detectAirixSubmissionFeePlugin');
    knowledgePaymentCheck($policyStart !== false && $policyEnd !== false && $policyEnd > $policyStart, 'getAirixSubmissionFeePolicy() must exist as its own method');
    $policyMethodBody = substr($adapterMethodSource, $policyStart, $policyEnd - $policyStart);
    foreach (['hasPaid', 'waiverDiscount', 'needsRefundReview', 'AirixSubmissionFeeProvider('] as $forbidden) {
        knowledgePaymentCheck(!str_contains($policyMethodBody, $forbidden), "getAirixSubmissionFeePolicy() must never touch \"{$forbidden}\" — that is the private obligation path (getAirixSubmissionFeeProvider), a different trust contract");
    }

    $waiverPolicyStart = strpos($adapterMethodSource, 'function getAirixRequestWaiverPolicy');
    knowledgePaymentCheck($waiverPolicyStart !== false, 'getAirixRequestWaiverPolicy() must exist as its own method');
    $waiverPolicyBody = substr($adapterMethodSource, $waiverPolicyStart, 1200);
    foreach (['getWaiverDiscount', 'waiverStatus', 'waiverPercent', '$submissionId'] as $forbidden) {
        knowledgePaymentCheck(!str_contains($waiverPolicyBody, $forbidden), "getAirixRequestWaiverPolicy() must never touch \"{$forbidden}\" — that is a submission-specific waiver decision, not journal-level policy text");
    }

    fwrite(STDOUT, "Knowledge payment tests passed\n");
}
