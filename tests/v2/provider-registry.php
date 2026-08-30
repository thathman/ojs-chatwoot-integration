<?php

declare(strict_types=1);

namespace PKP\plugins {
    final class PluginRegistry
    {
        /** @var array<string,array<string,object>> */
        public static array $plugins = [];
        public static bool $loadCategoryCalled = false;

        public static function loadCategory(string $category, bool $enabledOnly = false): array
        {
            self::$loadCategoryCalled = true;
            return self::$plugins[$category] ?? [];
        }

        public static function getPlugin(string $category, string $name): ?object
        {
            return self::$plugins[$category][$name] ?? null;
        }
    }

    final class Hook
    {
        /** @var array<string,array<int,callable>> */
        public static array $callbacks = [];

        public static function add(string $name, callable $callback): void
        {
            self::$callbacks[$name][] = $callback;
        }

        public static function call(string $name, array $args = []): void
        {
            foreach (self::$callbacks[$name] ?? [] as $callback) {
                $callback($name, $args);
            }
        }
    }
}

namespace APP\plugins\generic\submissionFee {
    /**
     * Mirrors only the SubmissionFeePlugin/PaymentHelper public surface
     * AirixSubmissionFeeProvider actually calls — verified against
     * Airix360/submissionFee-OJS 1.7.0.0 source directly, not re-tested
     * here; these fakes only prove our adapter/provider wire to that
     * surface correctly.
     */
    final class SubmissionFeePlugin
    {
        public function __construct(private bool $enabled, private string $version) {}
        public function getEnabled(): bool { return $this->enabled; }
        public function getCurrentVersion(): object
        {
            return new class($this->version) {
                public function __construct(private string $v) {}
                public function getVersionString(): string { return $this->v; }
            };
        }
    }

    final class PaymentHelper
    {
        public bool $feeEnabled = true;
        public bool $hasPaid = false;
        public ?array $waiverDiscount = null;
        public bool $needsRefundReview = false;
        public float $amountValue = 100.0;
        public float $payableAmountValue = 100.0;
        public string $currencyValue = 'USD';
        public string $payUrlValue = 'https://journal.example.com/pay/1';

        public function __construct(private SubmissionFeePlugin $plugin) {}

        public function feeEnabled($context): bool { return $this->feeEnabled; }
        public function amount($context): float { return $this->amountValue; }
        public function payableAmount($submission, $context): float { return $this->payableAmountValue; }
        public function currency($context): string { return $this->currencyValue; }
        public function hasPaid($submission, $context): bool { return $this->hasPaid; }
        public function waiverDiscount($submission): ?array { return $this->waiverDiscount; }
        public function needsRefundReview($submission, $context): bool { return $this->needsRefundReview; }
        public function payUrl($submission, $context): string { return $this->payUrlValue; }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\AirixSubmissionFeeProvider;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\ProviderHealth;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Provider\SupportProviderRegistry;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface;

    function providerCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    final class FakeSubmission
    {
        public array $data = [];
        public function getData(string $key): mixed { return $this->data[$key] ?? null; }
    }

    final class ThrowingProvider implements PaymentSupportProviderInterface
    {
        public function providerId(): string { return 'test.throwing'; }
        public function health($context): string { return ProviderHealth::AVAILABLE; }
        public function resolveObligation($context, $submission, int $userId): ?array
        {
            throw new \RuntimeException('boom');
        }
    }

    final class UnavailableProvider implements PaymentSupportProviderInterface
    {
        public function providerId(): string { return 'test.unavailable'; }
        public function health($context): string { return ProviderHealth::DISABLED; }
        public function resolveObligation($context, $submission, int $userId): ?array
        {
            providerCheck(false, 'registry must never call resolveObligation() on a provider that is not AVAILABLE');
            return null;
        }
    }

    final class InertProvider implements PaymentSupportProviderInterface
    {
        public function providerId(): string { return 'test.inert'; }
        public function health($context): string { return ProviderHealth::AVAILABLE; }
        public function resolveObligation($context, $submission, int $userId): ?array { return null; }
    }

    final class RealObligationProvider implements PaymentSupportProviderInterface
    {
        public function providerId(): string { return 'test.real'; }
        public function health($context): string { return ProviderHealth::AVAILABLE; }
        public function resolveObligation($context, $submission, int $userId): ?array
        {
            return ['producer' => 'test.real', 'feeKey' => 'x', 'status' => 'unpaid', 'amount' => 1.0, 'payableAmount' => 1.0, 'currency' => 'USD', 'payUrl' => null];
        }
    }

    // ================================================================
    // Part 1: SupportProviderRegistry — isolation, filtering, discovery.
    // ================================================================
    $registry = new SupportProviderRegistry();
    providerCheck($registry->resolveObligations(new \stdClass(), new FakeSubmission(), 1) === [], 'registry with no providers must resolve to an empty array, never null/throw');

    $registry->registerPaymentProvider(new ThrowingProvider());
    $registry->registerPaymentProvider(new UnavailableProvider());
    $registry->registerPaymentProvider(new InertProvider());
    $registry->registerPaymentProvider(new RealObligationProvider());
    $obligations = $registry->resolveObligations(new \stdClass(), new FakeSubmission(), 1);
    providerCheck(count($obligations) === 1, 'a throwing/unavailable/inert provider must never break resolution of an unrelated real provider');
    providerCheck($obligations[0]['producer'] === 'test.real', 'the one real obligation must survive the mixed provider set');

    $hookRegistry = new SupportProviderRegistry();
    \PKP\plugins\Hook::add('ChatwootIntegration::SupportProviders', function ($hookName, $args) {
        $args[0]->registerPaymentProvider(new RealObligationProvider());
    });
    $hookRegistry->discoverPaymentProviders();
    providerCheck(count($hookRegistry->discoverPaymentProviders()) === 1, 'a third-party provider registered only through the SupportProviders hook must be discoverable without a hard-coded adapter');

    // ================================================================
    // Part 2: AirixSubmissionFeeProvider — status normalization.
    // ================================================================
    $plugin = new \APP\plugins\generic\submissionFee\SubmissionFeePlugin(true, '1.7.0.0');
    $helper = new \APP\plugins\generic\submissionFee\PaymentHelper($plugin);
    $provider = new AirixSubmissionFeeProvider($plugin, $helper, '1.7.0.0');
    $context = new \stdClass();

    providerCheck($provider->providerId() === 'airix.submission_fee', 'provider id must be stable');
    providerCheck($provider->health($context) === ProviderHealth::AVAILABLE, 'enabled compatible plugin must report AVAILABLE');

    $disabledPlugin = new \APP\plugins\generic\submissionFee\SubmissionFeePlugin(false, '1.7.0.0');
    $disabledProvider = new AirixSubmissionFeeProvider($disabledPlugin, new \APP\plugins\generic\submissionFee\PaymentHelper($disabledPlugin), '1.7.0.0');
    providerCheck($disabledProvider->health($context) === ProviderHealth::DISABLED, 'disabled sibling plugin must report DISABLED, never AVAILABLE');

    $futurePlugin = new \APP\plugins\generic\submissionFee\SubmissionFeePlugin(true, '2.0.0.0');
    $futureProvider = new AirixSubmissionFeeProvider($futurePlugin, new \APP\plugins\generic\submissionFee\PaymentHelper($futurePlugin), '2.0.0.0');
    providerCheck($futureProvider->health($context) === ProviderHealth::INCOMPATIBLE_VERSION, 'an unverified future major version must report INCOMPATIBLE_VERSION, never be trusted as AVAILABLE');

    $submission = new FakeSubmission();
    $helper->feeEnabled = false;
    providerCheck($provider->resolveObligation($context, $submission, 1) === null, 'fee-disabled journal must resolve to null obligation, not a fabricated unpaid state');

    $helper->feeEnabled = true;
    $obligation = $provider->resolveObligation($context, $submission, 1);
    providerCheck($obligation['status'] === 'unpaid', 'no payment/waiver/refund evidence must normalize to unpaid');
    providerCheck($obligation['payUrl'] === $helper->payUrlValue, 'unpaid obligation must expose a pay URL');

    $helper->hasPaid = true;
    providerCheck($provider->resolveObligation($context, $submission, 1)['status'] === 'paid', 'a completed payment must normalize to paid');
    providerCheck($provider->resolveObligation($context, $submission, 1)['payUrl'] === null, 'a paid obligation must never expose a pay URL');

    $helper->hasPaid = false;
    $helper->waiverDiscount = ['type' => 'full', 'percent' => null, 'amount' => null];
    providerCheck($provider->resolveObligation($context, $submission, 1)['status'] === 'waived', 'an approved full waiver must normalize to waived');

    $helper->waiverDiscount = ['type' => 'partial', 'percent' => 50.0, 'amount' => null];
    $partial = $provider->resolveObligation($context, $submission, 1);
    providerCheck($partial['status'] === 'partially_waived', 'an approved partial waiver must normalize to partially_waived, not waived');
    providerCheck($partial['payUrl'] === $helper->payUrlValue, 'a partially-waived obligation must still expose a pay URL for the remaining balance');

    $helper->waiverDiscount = null;
    $submission->data['submissionFeeNeedsRefundReview'] = true;
    providerCheck($provider->resolveObligation($context, $submission, 1)['status'] === 'refund_review', 'a flagged refund-review submission must normalize to refund_review');

    $submission->data['submissionFeeNeedsRefundReview'] = false;
    $submission->data['submissionFeeRefunded'] = true;
    providerCheck($provider->resolveObligation($context, $submission, 1)['status'] === 'refunded', 'a refunded submission must normalize to refunded');

    // ================================================================
    // Part 3: Ojs35CompatibilityAdapter::getAirixSubmissionFeeProvider() —
    // detection, absence, disablement, health isolation.
    // ================================================================
    $adapter = new Ojs35CompatibilityAdapter();
    \PKP\plugins\PluginRegistry::$plugins = [];
    providerCheck($adapter->getAirixSubmissionFeeProvider($context) === null, 'absent sibling plugin must resolve to null, never throw');

    \PKP\plugins\PluginRegistry::$plugins['generic']['submissionfeeplugin'] = $disabledPlugin;
    providerCheck($adapter->getAirixSubmissionFeeProvider($context) === null, 'disabled sibling plugin must resolve to null through the adapter too');

    \PKP\plugins\PluginRegistry::$plugins['generic']['submissionfeeplugin'] = $plugin;
    $detected = $adapter->getAirixSubmissionFeeProvider($context);
    providerCheck($detected instanceof PaymentSupportProviderInterface, 'enabled compatible sibling plugin must resolve to a real provider');
    providerCheck($detected->providerId() === 'airix.submission_fee', 'adapter-detected provider must report the documented provider id');
    providerCheck($detected->health($context) === ProviderHealth::AVAILABLE, 'adapter-detected provider health must reflect the live plugin state');

    fwrite(STDOUT, "Provider registry tests passed\n");
}
