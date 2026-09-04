<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Migration;

use APP\plugins\generic\chatwootIntegration\classes\v2\Verification\VerificationEmailTemplateKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings Console item G / HAR-014 remainder: seeds
 * `email_templates_default_data` for the two real verification email
 * keys (`VerificationEmailTemplateKeys`) so they become real, first-
 * class OJS EmailTemplates — visible and editable per-journal under
 * Settings > Workflow > Emails, with real locale fallback via
 * `EmailTemplate::getLocalizedData()` — instead of the fixed-English
 * hand-built HTML `VerificationEmailContentBuilder` previously produced
 * directly.
 *
 * Modeled on the real core installer (`PKP\migration\upgrade\v3_5_0\
 * InstallEmailTemplates`, verified against the real deployed lib/pkp
 * source on dell): a default-data row per (email_key, locale) is
 * enough — `EmailTemplate\Collector::getDefaultQueryBuilder()` unions
 * these virtual rows into every context's real getByKey()/getCollector()
 * results without requiring a materialized `email_templates` row per
 * journal. Editing a template through the real OJS UI creates that
 * per-context override row itself; this migration never needs to.
 *
 * Additive per HAR-016/MIG-003: a new file appended to
 * `SupportGatewayMigrationRunner`'s step list, never a rewrite of an
 * earlier step. Idempotent: skips any locale/key pair that already has
 * a real row (a second install/upgrade run, or a previously-partial
 * run, never duplicates or clobbers an admin's already-customized
 * default).
 */
final class AddVerificationEmailTemplatesMigration extends Migration
{
    public function up(): void
    {
        if (!DB::getSchemaBuilder()->hasTable('email_templates_default_data')) {
            return;
        }

        $installedLocales = $this->installedLocales();

        foreach ($this->defaultContent() as $key => $content) {
            foreach ($installedLocales as $locale) {
                if (DB::table('email_templates_default_data')->where('email_key', $key)->where('locale', $locale)->exists()) {
                    continue;
                }

                DB::table('email_templates_default_data')->insert([
                    'email_key' => $key,
                    'locale' => $locale,
                    'name' => $content['name'],
                    'subject' => $content['subject'],
                    'body' => $content['body'],
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('email_templates_default_data')->whereIn('email_key', VerificationEmailTemplateKeys::all())->delete();
    }

    /** @return string[] Real installed site locales — never invented; falls back to 'en' alone if the site table/column is unavailable (a fresh install mid-migration). */
    private function installedLocales(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('site')) {
            return ['en'];
        }
        $raw = DB::table('site')->value('installed_locales');
        $locales = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($locales) && $locales !== [] ? $locales : ['en'];
    }

    /**
     * English defaults for every installed locale (matching this
     * codebase's existing single-locale convention — see
     * VerificationEmailContentBuilder's own note on full localization
     * being a future improvement, docs/v2/TASKLIST.md IDN-007). A
     * journal manager can translate/customize per-locale through the
     * real OJS Email Templates UI once this seeds the row.
     *
     * @return array<string,array{name:string,subject:string,body:string}>
     */
    private function defaultContent(): array
    {
        return [
            VerificationEmailTemplateKeys::PIN => [
                'name' => 'Support Verification (PIN)',
                'subject' => 'Support verification for {$journalName}',
                'body' => '<p>A verification request was made for support with {$journalName}.</p>'
                    . '<p>Your verification code is: <strong>{$pinCode}</strong></p>'
                    . '<p>This code expires in {$expiryMinutes} minutes. If you did not request this, you can safely ignore this email.</p>',
            ],
            VerificationEmailTemplateKeys::LINK => [
                'name' => 'Support Verification (Link)',
                'subject' => 'Support verification for {$journalName}',
                'body' => '<p>A verification request was made for support with {$journalName}.</p>'
                    . '<p><a href="{$verificationLink}">Click here to verify</a></p>'
                    . '<p>This link expires in {$expiryMinutes} minutes. If you did not request this, you can safely ignore this email.</p>',
            ],
        ];
    }
}
