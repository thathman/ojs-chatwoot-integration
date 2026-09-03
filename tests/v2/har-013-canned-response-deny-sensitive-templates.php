<?php

declare(strict_types=1);

namespace PKP\plugins {
    /** Same minimal in-memory GenericPlugin double established by tests/v2/settings-small-002-export-import-completeness.php. */
    class GenericPlugin
    {
        /** @var array<int,array<string,mixed>> */
        public array $settings = [];

        public function getSetting($contextId, $key)
        {
            return $this->settings[(int) $contextId][(string) $key] ?? null;
        }

        public function updateSetting($contextId, $key, $value, $type = null)
        {
            $this->settings[(int) $contextId][(string) $key] = $value;
        }

        public function getEnabled($contextId = null)
        {
            return true;
        }
    }
}

namespace {
    if (!defined('PKP_STRICT_MODE')) {
        define('PKP_STRICT_MODE', true);
    }

    $root = dirname(__DIR__, 2);
    require_once $root . '/classes/v2/bootstrap.php';
    require_once $root . '/ChatwootIntegrationBasePlugin.php';

    use APP\plugins\generic\chatwootIntegration\ChatwootIntegrationBasePlugin;

    function har013Check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /**
     * HAR-013: syncEmailTemplates() promoted every non-empty OJS
     * EmailTemplate body to a Chatwoot canned response with no
     * support-safe allowlist/denylist. Live-checked against this real
     * installation's actual templates (via Repo::emailTemplate()):
     * PASSWORD_RESET_CONFIRM, MAGIC_LOGIN_LINK, USER_VALIDATE_CONTEXT,
     * and USER_VALIDATE_SITE all had non-empty bodies and would have
     * been synced as plaintext canned responses visible to every
     * support agent — real account-recovery material, not editorial
     * content. isCannedResponseSafe() proves the real hard-deny keyword
     * match against exactly those real template keys, plus common
     * non-sensitive editorial keys that must still be allowed through.
     */
    $plugin = new ChatwootIntegrationBasePlugin();
    $isSafe = new \ReflectionMethod($plugin, 'isCannedResponseSafe');

    $mustDeny = [
        'PASSWORD_RESET_CONFIRM',
        'MAGIC_LOGIN_LINK',
        'USER_VALIDATE_CONTEXT',
        'USER_VALIDATE_SITE',
        'USER_REGISTER',
        'REVIEWER_REGISTER',
        'SOME_FUTURE_PLUGIN_LOGIN_LINK',
        'password_reset_confirm',
    ];
    foreach ($mustDeny as $key) {
        har013Check($isSafe->invoke($plugin, $key) === false, "\"{$key}\" must be denied — it is real account-recovery/security material this session found would otherwise be promoted as-is");
    }

    $mustAllow = [
        'EDITOR_ASSIGN_REVIEW',
        'COPYEDIT_REQUEST',
        'LAYOUT_REQUEST',
        'REVIEW_CONFIRM',
    ];
    foreach ($mustAllow as $key) {
        har013Check($isSafe->invoke($plugin, $key) === true, "\"{$key}\" is real ordinary editorial-workflow content and must not be denied by an overbroad keyword match");
    }

    // ================================================================
    // Real wiring: syncEmailTemplates() must actually consult the
    // shared decision before ever calling createCannedResponse() or
    // enqueueApiJob(), never just for display/reporting after the fact.
    // ================================================================
    $source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
    $syncStart = strpos($source, 'function syncEmailTemplates(');
    har013Check($syncStart !== false, 'syncEmailTemplates() must exist');
    $syncBody = substr($source, $syncStart, (int) strpos($source, "\n    }\n", $syncStart) - $syncStart);
    har013Check(str_contains($syncBody, '$this->isCannedResponseSafe($shortCode)'), 'syncEmailTemplates() must consult isCannedResponseSafe() for every template key');

    $safetyCheckPos = strpos($syncBody, 'isCannedResponseSafe(');
    $createCallPos = strpos($syncBody, 'createCannedResponse(');
    har013Check($safetyCheckPos !== false && $createCallPos !== false && $safetyCheckPos < $createCallPos, 'the safety check must run before createCannedResponse() is ever called, not after');

    fwrite(STDOUT, "HAR-013 canned-response deny-sensitive-templates tests passed\n");
}
