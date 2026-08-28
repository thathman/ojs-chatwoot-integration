<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\ChatwootContextProjector;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;

function projectorCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$projector = new ChatwootContextProjector();
$paymentContext = new SupportContext(7, 'journal-a', 42, [65536, 16], 'payment', 'index', 'en');
$attrs = $projector->project($paymentContext);

projectorCheck(($attrs['ojs_context_schema'] ?? '') === 'v2', 'projector should version its contract');
projectorCheck(($attrs['ojs_context_contract'] ?? '') === 'display_only', 'Chatwoot attributes must be explicitly non-authoritative');
projectorCheck(($attrs['ojs_context_id'] ?? null) === 7, 'journal id should be projected');
projectorCheck(($attrs['ojs_context_path'] ?? '') === 'journal-a', 'journal path should be projected');
projectorCheck(($attrs['ojs_authenticated'] ?? false) === true, 'authentication presence may be projected');
projectorCheck(($attrs['ojs_role_ids'] ?? '') === '16,65536', 'multiple role IDs should be normalized for display');
projectorCheck(($attrs['ojs_has_multiple_roles'] ?? false) === true, 'multiple-role hint should be projected');
projectorCheck(($attrs['ojs_support_intent'] ?? '') === 'payment_help', 'payment page should select payment support intent');

$forbiddenKeys = ['user_id', 'ojs_user_id', 'email', 'submission_id', 'relationship', 'capabilities', 'permission', 'verified_submission'];
foreach ($forbiddenKeys as $key) {
    projectorCheck(!array_key_exists($key, $attrs), "projector must not expose {$key}");
}
projectorCheck(!in_array(42, $attrs, true), 'raw OJS user ID must not appear as a projected value');

$authorDashboard = new SupportContext(7, 'journal-a', 42, [65536], 'authorDashboard', 'index', 'en');
projectorCheck(
    $projector->project($authorDashboard)['ojs_support_intent'] === 'manuscript_help',
    'author dashboard should map case-insensitively to manuscript help'
);

$guest = new SupportContext(7, 'journal-a', null, [], 'login', 'signIn', 'en');
$guestAttrs = $projector->project($guest);
projectorCheck($guestAttrs['ojs_authenticated'] === false, 'guest should stay unauthenticated');
projectorCheck($guestAttrs['ojs_support_intent'] === 'account_access', 'login page should select account-access help');

fwrite(STDOUT, "Context projector tests passed\n");
