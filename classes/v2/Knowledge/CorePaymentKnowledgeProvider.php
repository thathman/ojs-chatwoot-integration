<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\KnowledgeProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

/**
 * Public fee *policy* knowledge (docs/v2/KNOWLEDGE_DIAGNOSTICS.md §3
 * "Fees/payments", docs/v2/AIRIX360_INTEGRATIONS.md §5.2) — what a journal
 * publicly charges, never what a specific submission owes.
 *
 * Deliberately reads through the compatibility adapter's
 * `getPaymentFeeInfo()`/`getAirixSubmissionFeePolicy()` rather than the
 * private `PaymentStatusSerializer`/`PaymentSupportProviderInterface`
 * path: those answer "what does THIS submission owe" (paid/unpaid/
 * waived/refund_review/refunded, transaction/reference IDs) and must
 * never reach a KnowledgeFact. This provider only ever asks "what is
 * configured" — no `$submission`, no `$userId` parameter exists anywhere
 * in its call chain.
 */
final class CorePaymentKnowledgeProvider implements KnowledgeProviderInterface
{
    public function __construct(private OjsCompatibilityAdapterInterface $adapter)
    {
    }

    public function providerId(): string
    {
        return 'core.payment_policy';
    }

    public function collect($context, $request, string $locale): array
    {
        if (!is_object($context)) {
            return [];
        }

        $facts = [];
        $this->addNativePublicationFee($facts, $context, $locale);
        $this->addAirixSubmissionFee($facts, $context, $locale);
        return $facts;
    }

    private function addNativePublicationFee(array &$facts, $context, string $locale): void
    {
        try {
            $feeInfo = $this->adapter->getPaymentFeeInfo($context);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($feeInfo)) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'fee.publicationEnabled',
            ($feeInfo['enabled'] ?? false) ? 'true' : 'false',
            KnowledgeClassification::PUBLIC,
            'ojs.payment_manager',
            $locale,
            $this->providerId(),
            'OJSPaymentManager::isConfigured()+publicationEnabled()'
        );

        if (($feeInfo['enabled'] ?? false) && is_numeric($feeInfo['amount'] ?? null) && (float) $feeInfo['amount'] > 0) {
            $facts[] = new KnowledgeFact(
                'fee.publicationAmount',
                sprintf('%.2f', (float) $feeInfo['amount']),
                KnowledgeClassification::PUBLIC,
                'ojs.payment_manager',
                $locale,
                $this->providerId(),
                'publicationFee'
            );
            if (!empty($feeInfo['currency'])) {
                $facts[] = new KnowledgeFact(
                    'fee.publicationCurrency',
                    (string) $feeInfo['currency'],
                    KnowledgeClassification::PUBLIC,
                    'ojs.payment_manager',
                    $locale,
                    $this->providerId(),
                    'currency'
                );
            }
        }
    }

    private function addAirixSubmissionFee(array &$facts, $context, string $locale): void
    {
        try {
            $policy = $this->adapter->getAirixSubmissionFeePolicy($context);
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($policy) || !($policy['enabled'] ?? false)) {
            return;
        }

        $facts[] = new KnowledgeFact(
            'fee.submissionEnabled',
            'true',
            KnowledgeClassification::PUBLIC,
            'airix.submission_fee_policy',
            $locale,
            $this->providerId(),
            'PaymentHelper::feeEnabled()'
        );

        if (is_numeric($policy['amount'] ?? null) && (float) $policy['amount'] > 0) {
            $facts[] = new KnowledgeFact(
                'fee.submissionAmount',
                sprintf('%.2f', (float) $policy['amount']),
                KnowledgeClassification::PUBLIC,
                'airix.submission_fee_policy',
                $locale,
                $this->providerId(),
                'PaymentHelper::amount()'
            );
            if (!empty($policy['currency'])) {
                $facts[] = new KnowledgeFact(
                    'fee.submissionCurrency',
                    (string) $policy['currency'],
                    KnowledgeClassification::PUBLIC,
                    'airix.submission_fee_policy',
                    $locale,
                    $this->providerId(),
                    'PaymentHelper::currency()'
                );
            }
        }
    }
}
