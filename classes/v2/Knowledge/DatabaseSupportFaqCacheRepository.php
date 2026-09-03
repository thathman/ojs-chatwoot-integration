<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Knowledge;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SupportFaqCacheRepositoryInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Migration\InstallSupportGatewayMigration;
use Illuminate\Support\Facades\DB;

final class DatabaseSupportFaqCacheRepository implements SupportFaqCacheRepositoryInterface
{
    // Deferred to a method (not a class const) so merely loading/constructing
    // this repository never forces autoload of the Illuminate-based migration
    // class outside a real OJS runtime.
    private static function table(): string
    {
        return InstallSupportGatewayMigration::FAQ_CACHE_TABLE;
    }

    public function replaceAll(int $contextId, string $locale, array $faqs, int $now): void
    {
        $nowDb = gmdate('Y-m-d H:i:s', $now);

        DB::transaction(function () use ($contextId, $locale, $faqs, $nowDb): void {
            DB::table(self::table())
                ->where('context_id', $contextId)
                ->where('locale', $locale)
                ->delete();

            foreach ($faqs as $faq) {
                $externalId = trim((string) ($faq['externalId'] ?? ''));
                $question = trim((string) ($faq['question'] ?? ''));
                $answer = trim((string) ($faq['answer'] ?? ''));
                if ($externalId === '' || $question === '' || $answer === '') {
                    continue;
                }

                DB::table(self::table())->insert([
                    'context_id' => $contextId,
                    'locale' => $locale,
                    'external_id' => $externalId,
                    'question' => $question,
                    'answer' => $answer,
                    'synced_at' => $nowDb,
                ]);
            }
        });
    }

    public function listApproved(int $contextId, string $locale): array
    {
        $rows = DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('locale', $locale)
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'externalId' => (string) $row->external_id,
                'question' => (string) $row->question,
                'answer' => (string) $row->answer,
                'syncedAt' => (string) $row->synced_at,
            ];
        }
        return $result;
    }

    public function lastSyncedAt(int $contextId, string $locale): ?int
    {
        $syncedAt = DB::table(self::table())
            ->where('context_id', $contextId)
            ->where('locale', $locale)
            ->orderByDesc('synced_at')
            ->value('synced_at');

        return $syncedAt ? strtotime((string) $syncedAt) : null;
    }
}
