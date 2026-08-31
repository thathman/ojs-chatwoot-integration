<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SubmissionDiagnosticEngine;

function uploadLimitDiagnosticCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// --- Ojs35CompatibilityAdapter's PHP ini shorthand byte parsing.
// upload_max_filesize/post_max_size are PHP_INI_PERDIR — not mutable via
// ini_set() at runtime — so the shorthand-notation parsing itself is
// exercised directly via Reflection on the private static parser, and
// getUploadLimits() is separately checked only for real ini_get() wiring
// (whatever this environment's actual configured values are). ---
$adapter = new Ojs35CompatibilityAdapter();
$parseIniBytes = new ReflectionMethod(Ojs35CompatibilityAdapter::class, 'parseIniBytes');

uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '8M') === 8 * 1024 * 1024, '"8M" must parse to 8 * 1024 * 1024 bytes');
uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '512K') === 512 * 1024, '"512K" must parse to 512 * 1024 bytes');
uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '1G') === 1024 * 1024 * 1024, '"1G" must parse to 1024^3 bytes');
uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '2097152') === 2097152, 'a plain byte count with no unit suffix must parse as-is');
uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '0') === 0, '"0" (PHP\'s "unlimited") must parse to 0, not be misinterpreted');
uploadLimitDiagnosticCheck($parseIniBytes->invoke(null, '') === 0, 'an empty ini value must parse to 0 rather than throw');

// getUploadLimits() must actually read real ini_get() values, wired
// through both bytes fields with the real key names.
$limits = $adapter->getUploadLimits();
uploadLimitDiagnosticCheck(
    array_keys($limits) === ['uploadMaxFilesizeBytes', 'postMaxSizeBytes'] && $limits['uploadMaxFilesizeBytes'] > 0,
    'getUploadLimits() must return both real ini-derived byte fields'
);

// ================================================================
// SubmissionDiagnosticEngine::diagnoseUploadLimit()
// ================================================================
uploadLimitDiagnosticCheck(in_array(SubmissionDiagnosticEngine::SCOPE_UPLOAD_LIMIT, SubmissionDiagnosticEngine::SCOPES, true), 'upload_limit must be a real registered scope');

$normal = SubmissionDiagnosticEngine::diagnoseUploadLimit(8 * 1024 * 1024, 32 * 1024 * 1024);
uploadLimitDiagnosticCheck($normal->status() === DiagnosticResult::STATUS_CONFIRMED && $normal->code() === 'UPLOAD_LIMIT_NORMAL', 'post_max_size >= upload_max_filesize must confirm UPLOAD_LIMIT_NORMAL');

$misconfigured = SubmissionDiagnosticEngine::diagnoseUploadLimit(32 * 1024 * 1024, 8 * 1024 * 1024);
uploadLimitDiagnosticCheck(
    $misconfigured->status() === DiagnosticResult::STATUS_CONFIRMED
        && $misconfigured->code() === 'UPLOAD_LIMIT_MISCONFIGURED'
        && in_array('contact_editorial_office', $misconfigured->nextActions(), true),
    'post_max_size < upload_max_filesize must confirm UPLOAD_LIMIT_MISCONFIGURED and suggest contacting the editorial office'
);

// A post_max_size of 0 conventionally means "unlimited" in PHP — must
// never be treated as "smaller than upload_max_filesize".
$unlimitedPost = SubmissionDiagnosticEngine::diagnoseUploadLimit(8 * 1024 * 1024, 0);
uploadLimitDiagnosticCheck($unlimitedPost->code() === 'UPLOAD_LIMIT_NORMAL', 'a post_max_size of 0 (PHP\'s "unlimited") must never be flagged as misconfigured');

// Equal values are a normal, non-misconfigured boundary.
$equal = SubmissionDiagnosticEngine::diagnoseUploadLimit(8 * 1024 * 1024, 8 * 1024 * 1024);
uploadLimitDiagnosticCheck($equal->code() === 'UPLOAD_LIMIT_NORMAL', 'equal upload_max_filesize and post_max_size must not be flagged as misconfigured');

fwrite(STDOUT, "Upload limit diagnostic tests passed\n");
