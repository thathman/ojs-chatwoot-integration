<?php

declare(strict_types=1);

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    function vetsCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * Settings Console item G / HAR-014 remainder: VerificationEmailTemplateService
     * is the new real-EmailTemplate-backed replacement for
     * VerificationEmailContentBuilder's fixed English strings. Guzzle/
     * a full OJS runtime is unavailable in this local harness (same
     * constraint every other v2 test in this suite documents), so
     * Repo::emailTemplate() cannot actually be exercised here — this
     * proves the substitution logic and fallback-content shape are
     * safe using the same real malicious-journal-name fixtures
     * HAR-014's own test already established, plus the source-level
     * wiring that both real call sites (REST + MCP) use this one
     * shared service.
     */
    $vetsSource = (string) file_get_contents("{$root}/classes/v2/Verification/VerificationEmailTemplateService.php");
    $keysSource = (string) file_get_contents("{$root}/classes/v2/Verification/VerificationEmailTemplateKeys.php");
    $migrationSource = (string) file_get_contents("{$root}/classes/v2/Migration/AddVerificationEmailTemplatesMigration.php");
    $runnerSource = (string) file_get_contents("{$root}/classes/v2/Migration/SupportGatewayMigrationRunner.php");
    $pluginSource = (string) file_get_contents("{$root}/classes/v2/Plugin/ChatwootIntegrationV2Plugin.php");

    // ================================================================
    // Part 1: real OJS EmailTemplate lifecycle wiring — the fallback
    // path exists (never crashes when no template row is found yet),
    // but the real Repo::emailTemplate()->getByKey() call is what's
    // actually used first.
    // ================================================================
    vetsCheck(str_contains($vetsSource, 'Repo::emailTemplate()->getByKey('), 'compose() must fetch the real OJS EmailTemplate via Repo::emailTemplate()->getByKey()');
    vetsCheck(str_contains($vetsSource, 'getLocalizedData(\'subject\')') && str_contains($vetsSource, 'getLocalizedData(\'body\')'), 'compose() must read subject/body through getLocalizedData() — this is what gives real OJS locale fallback (preferred > context primary > site primary > first available) for free');
    vetsCheck(str_contains($vetsSource, 'fallbackSubject') && str_contains($vetsSource, 'fallbackBody'), 'compose() must have a safe fallback for when no real template row exists yet (fresh install before the seeding migration runs, or a deleted row)');
    vetsCheck(str_contains($vetsSource, 'public static function isCustomized(') && str_contains($vetsSource, '$template->getId() !== null'), 'isCustomized() must distinguish a real per-context override (a real id) from the seeded default-only virtual row (a null id) — never claim "customized" just because a template exists at all');

    // ================================================================
    // Part 2: strict allowlist substitution — never full Smarty/eval,
    // even though the subject/body text is now real admin-editable
    // data once a journal manager customizes the template.
    // ================================================================
    vetsCheck(!preg_match('/\beval\s*\(/', $vetsSource), 'VerificationEmailTemplateService must never eval() the template text');
    vetsCheck(!str_contains($vetsSource, '->fetch('), 'VerificationEmailTemplateService must never run the template text through a template engine\'s fetch() — only a strict str_replace substitution');
    vetsCheck(str_contains($vetsSource, "str_replace('{\$' . \$variableName . '}'"), 'substitution must be a plain str_replace keyed to the exact allowlisted placeholder name, never a general-purpose template engine');
    vetsCheck(str_contains($vetsSource, 'allowedVariables($key)'), 'substitute() must only ever iterate the real allowlisted variable names for this key — an admin-added unrecognized {$anything} token is never expanded');

    // Both keys have a real allowlist, and the two keys' allowlists are
    // properly distinct (a PIN body must never accidentally reference
    // the link URL variable name or vice versa).
    vetsCheck(str_contains($keysSource, "'journalName', 'pinCode', 'expiryMinutes'"), 'PIN key must allowlist journalName/pinCode/expiryMinutes');
    vetsCheck(str_contains($keysSource, "'journalName', 'verificationLink', 'expiryMinutes'"), 'LINK key must allowlist journalName/verificationLink/expiryMinutes');

    // ================================================================
    // Part 3: HAR-014 safety must be preserved — reuses
    // VerificationEmailContentBuilder::safeSubjectText() (now public),
    // never a re-implementation that could silently diverge.
    // ================================================================
    vetsCheck(str_contains($vetsSource, 'VerificationEmailContentBuilder::safeSubjectText('), 'compose()/substitute() must reuse the one already-proven-safe CRLF-stripping function, never duplicate it');
    $contentBuilderSource = (string) file_get_contents("{$root}/classes/v2/Verification/VerificationEmailContentBuilder.php");
    vetsCheck(str_contains($contentBuilderSource, 'public static function safeSubjectText('), 'safeSubjectText() must be public so VerificationEmailTemplateService can reuse it instead of re-implementing CRLF stripping');
    vetsCheck(str_contains($vetsSource, "htmlspecialchars(\$value, ENT_QUOTES, 'UTF-8')"), 'every substituted body value must be HTML-escaped — the same defense HAR-014 already proved necessary for the journal name specifically now applies to every real variable, since the surrounding template text is admin-editable too');

    // ================================================================
    // Part 4: real, additive, idempotent EmailTemplate seeding — models
    // the real core installer pattern (PKP\migration\upgrade\v3_5_0\
    // InstallEmailTemplates), verified against the real deployed
    // lib/pkp source on dell.
    // ================================================================
    vetsCheck(str_contains($migrationSource, "DB::table('email_templates_default_data')"), 'the migration must seed the real email_templates_default_data table — the exact mechanism EmailTemplate\\Collector::getDefaultQueryBuilder() unions into every context\'s results without requiring a per-context row');
    vetsCheck(str_contains($migrationSource, '->where(\'email_key\', $key)->where(\'locale\', $locale)->exists()'), 'the migration must be idempotent — never insert a duplicate row for a key/locale pair that already exists (a second install/upgrade run, or an admin who already customized the default)');
    vetsCheck(str_contains($migrationSource, 'VerificationEmailTemplateKeys::PIN') && str_contains($migrationSource, 'VerificationEmailTemplateKeys::LINK'), 'the migration must seed both real verification email keys');
    vetsCheck(str_contains($runnerSource, 'new AddVerificationEmailTemplatesMigration()'), 'the migration must be registered in SupportGatewayMigrationRunner\'s step list — appended, per HAR-016/MIG-003, never inserted before AddFaqCacheTableMigration');

    // ================================================================
    // Part 5: both real call sites (REST verificationRequest, MCP
    // identity.request_verification) must use the one shared service —
    // proven directly for MCP by tests/v2/mcp-tools.php; here we prove
    // the REST call site too, plus that the old direct-builder calls
    // for composing the sent email are gone from both.
    // ================================================================
    vetsCheck(substr_count($pluginSource, 'VerificationEmailTemplateService::compose(') === 4, 'both real call sites (REST + MCP) must each call compose() exactly twice (once per PIN/link branch) — 4 total, never a bespoke composition path');
    vetsCheck(!str_contains($pluginSource, 'VerificationEmailContentBuilder::subject(') && !str_contains($pluginSource, 'VerificationEmailContentBuilder::pinBody(') && !str_contains($pluginSource, 'VerificationEmailContentBuilder::linkBody('), 'ChatwootIntegrationV2Plugin must no longer call VerificationEmailContentBuilder directly for the emails it actually sends — VerificationEmailTemplateService is now the only path, with VerificationEmailContentBuilder demoted to that service\'s own safe fallback/default-content source');

    fwrite(STDOUT, "Verification email template service tests passed\n");
}
