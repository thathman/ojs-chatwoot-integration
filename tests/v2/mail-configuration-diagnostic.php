<?php

declare(strict_types=1);

namespace PKP\config {
    /**
     * Fakes pkp-lib's real Config::getVar($section, $key, $default) surface
     * — the only method Ojs35CompatibilityAdapter::getMailTransportConfiguration()
     * calls — so DIA-011 can be exercised without a live OJS config.inc.php.
     */
    final class Config
    {
        /** @var array<string,array<string,mixed>> */
        public static array $vars = [];

        public static function getVar(string $section, string $key, mixed $default = null): mixed
        {
            return self::$vars[$section][$key] ?? $default;
        }
    }
}

namespace {
    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';

    use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\AccountDiagnosticEngine;
    use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\DiagnosticResult;
    use PKP\config\Config;

    function mailConfigurationDiagnosticCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    mailConfigurationDiagnosticCheck(in_array(AccountDiagnosticEngine::SCOPE_MAIL_CONFIGURATION, AccountDiagnosticEngine::SCOPES, true), 'mail_configuration must be a real registered scope');

    $adapter = new Ojs35CompatibilityAdapter();

    // ================================================================
    // Ojs35CompatibilityAdapter::getMailTransportConfiguration() — mirrors
    // pkp-lib's real PKPContainer::getDefaultMailer() transport-selection
    // logic exactly (sandbox forces log; smtp otherwise needs a host).
    // ================================================================
    Config::$vars = ['general' => ['sandbox' => false], 'email' => ['default' => 'sendmail', 'smtp_server' => '']];
    $sendmail = $adapter->getMailTransportConfiguration();
    mailConfigurationDiagnosticCheck($sendmail === ['driver' => 'sendmail', 'sandboxForced' => false, 'smtpHostConfigured' => false], 'a plain sendmail configuration must be read verbatim');

    Config::$vars = ['general' => ['sandbox' => true], 'email' => ['default' => 'smtp', 'smtp_server' => 'mail.example.com']];
    $sandboxed = $adapter->getMailTransportConfiguration();
    mailConfigurationDiagnosticCheck($sandboxed['driver'] === 'log' && $sandboxed['sandboxForced'] === true, 'sandbox mode must force the log driver regardless of the configured [email] default, exactly like pkp-lib\'s real getDefaultMailer()');

    Config::$vars = ['general' => ['sandbox' => false], 'email' => ['default' => 'smtp', 'smtp_server' => '  ']];
    $blankHost = $adapter->getMailTransportConfiguration();
    mailConfigurationDiagnosticCheck($blankHost['smtpHostConfigured'] === false, 'a whitespace-only smtp_server must not count as configured');

    Config::$vars = ['general' => ['sandbox' => false], 'email' => ['default' => 'smtp', 'smtp_server' => 'mail.example.com']];
    $realHost = $adapter->getMailTransportConfiguration();
    mailConfigurationDiagnosticCheck($realHost['smtpHostConfigured'] === true, 'a real smtp_server value must count as configured');

    // ================================================================
    // AccountDiagnosticEngine::diagnose('mail_configuration', ...)
    // ================================================================
    $confirmed = AccountDiagnosticEngine::diagnose('mail_configuration', false, null, $realHost);
    mailConfigurationDiagnosticCheck($confirmed->status() === DiagnosticResult::STATUS_CONFIRMED && $confirmed->code() === 'MAIL_CONFIGURED', 'a real adapter-shaped smtp+host config must confirm MAIL_CONFIGURED');

    $disabled = AccountDiagnosticEngine::diagnose('mail_configuration', false, null, $sandboxed);
    mailConfigurationDiagnosticCheck($disabled->status() === DiagnosticResult::STATUS_CONFIRMED && $disabled->code() === 'MAIL_SENDING_DISABLED', 'a real adapter-shaped sandboxed config must confirm MAIL_SENDING_DISABLED');

    fwrite(STDOUT, "Mail configuration diagnostic tests passed\n");
}
