<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\CapabilityProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\AvailableActionMapper;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityPolicyEngine;
use APP\plugins\generic\chatwootIntegration\classes\v2\Policy\CapabilityRequest;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ResourceRelationship;

function capabilityCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$engine = new CapabilityPolicyEngine();
$mapper = new AvailableActionMapper();

$guestContext = new SupportContext(7, 'journal-a', null, [], 'index', 'index', 'en');
$guest = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v0',
    $guestContext
));
capabilityCheck($guest->allows('journal.read_public_info'), 'guest should receive public journal information');
capabilityCheck($guest->allows('support.escalate'), 'guest should be able to escalate support');
capabilityCheck(!$guest->allows('account.read_own_support_state'), 'guest must not receive account capability');
capabilityCheck(!$guest->allows('submission.list_own'), 'guest must not list private submissions');

$multiRoleContext = new SupportContext(7, 'journal-a', 42, [16, 4096], 'workflow', 'index', 'en');
$authorRelationship = new ResourceRelationship('submission', 101, ['author'], ['author' => true, 'reviewer' => false]);
$reviewerRelationship = new ResourceRelationship('submission', 101, ['reviewer'], ['author' => false, 'reviewer' => true]);
$bothRelationship = new ResourceRelationship('submission', 101, ['author', 'reviewer'], ['author' => true, 'reviewer' => true]);

$v2Author = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v2',
    $multiRoleContext,
    $authorRelationship
));
capabilityCheck($v2Author->allows('account.read_own_support_state'), 'V2 authenticated identity should receive own account support state');
capabilityCheck($v2Author->allows('submission.list_own'), 'V2 identity should be able to list only relationship-filtered own submissions');
capabilityCheck(!$v2Author->allows('submission.read_own_support_status'), 'V2 must not receive V3 resource capability');
capabilityCheck($v2Author->denialReason('submission.read_own_support_status') === 'verification_required', 'V2 resource denial should require stronger verification');

$author = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v3',
    $multiRoleContext,
    $authorRelationship
));
capabilityCheck($author->allows('submission.read_own_support_status'), 'proven author should read safe support status');
capabilityCheck($author->allows('submission.read_own_required_actions'), 'proven author should read own required actions');
capabilityCheck($author->allows('submission.read_own_publication_status'), 'proven author should read own publication status');
capabilityCheck(!$author->allows('review.read_own_assignment'), 'journal reviewer role alone must not create review capability');
capabilityCheck(!$author->allows('submission.read_own_payment_status'), 'payment capability must be feature/policy gated');
capabilityCheck(!$author->allows('submission.read_author_visible_files'), 'file capability must be feature/policy gated');

$authorWithFeatures = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_HUMAN_SUPPORT,
    'v3',
    $multiRoleContext,
    $authorRelationship,
    ['payment_status' => true, 'author_visible_files' => true],
    ['payment_support' => true, 'file_support' => true]
));
capabilityCheck($authorWithFeatures->allows('submission.read_own_payment_status'), 'payment capability should require feature and journal policy');
capabilityCheck($authorWithFeatures->allows('submission.read_author_visible_files'), 'author-visible file capability should require feature and journal policy');

$reviewer = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_MCP_PUBLIC_SUPPORT,
    'v3',
    $multiRoleContext,
    $reviewerRelationship
));
capabilityCheck($reviewer->allows('review.read_own_assignment'), 'actual reviewer relationship should grant own assignment capability');
capabilityCheck($reviewer->allows('submission.read_own_support_status'), 'reviewer should receive safe submission support status');
capabilityCheck(!$reviewer->allows('submission.read_own_payment_status'), 'reviewer must not receive author payment capability');
capabilityCheck(!$reviewer->allows('submission.read_author_visible_files'), 'reviewer must not receive author-visible file capability');

$both = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v3',
    $multiRoleContext,
    $bothRelationship,
    ['payment_status' => true],
    ['payment_support' => true]
));
capabilityCheck($both->allows('review.read_own_assignment'), 'proven multi-relationship should retain reviewer capability');
capabilityCheck($both->allows('submission.read_own_payment_status'), 'proven multi-relationship should retain author capability');

$actions = $mapper->map($both);
capabilityCheck(in_array('view_status', $actions, true), 'available actions should derive from allowed capabilities');
capabilityCheck(in_array('view_review_assignment', $actions, true), 'review action should be capability-derived');
capabilityCheck(in_array('view_payment_status', $actions, true), 'payment action should be capability-derived');

$policyDenied = $engine->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v3',
    $multiRoleContext,
    $authorRelationship,
    [],
    ['submission_support' => false]
));
capabilityCheck(!$policyDenied->allows('submission.read_own_support_status'), 'journal policy must be able to disable submission support');
capabilityCheck($policyDenied->denialReason('submission.read_own_support_status') === 'journal_policy_denied', 'policy denial reason should be auditable');

final class OverreachingProvider implements CapabilityProviderInterface
{
    public function providerId(): string
    {
        return 'overreach';
    }
    public function declaredCapabilities(): array
    {
        return ['journal.read_public_info'];
    }
    public function candidateCapabilities(CapabilityRequest $request): array
    {
        return ['journal.read_public_info', 'payment.mark_paid', 'submission.read_own_payment_status'];
    }
}

$overreach = (new CapabilityPolicyEngine([new OverreachingProvider()]))->evaluate(new CapabilityRequest(
    CapabilityRequest::CONSUMER_CHATWOOT_CAPTAIN_PUBLIC,
    'v0',
    $guestContext
));
capabilityCheck($overreach->allows('journal.read_public_info'), 'declared known provider capability should remain eligible');
capabilityCheck(in_array('overreach:payment.mark_paid', $overreach->rejectedProviderCapabilities(), true), 'unknown staff mutation must be rejected');
capabilityCheck(in_array('overreach:submission.read_own_payment_status', $overreach->rejectedProviderCapabilities(), true), 'undeclared known capability must be rejected');
capabilityCheck(!$overreach->allows('submission.read_own_payment_status'), 'provider must not bypass central policy');

$invalidConsumerRejected = false;
try {
    new CapabilityRequest('browser_claimed_admin', 'v4', $multiRoleContext, $bothRelationship);
} catch (InvalidArgumentException $e) {
    $invalidConsumerRejected = true;
}
capabilityCheck($invalidConsumerRejected, 'unknown consumer plane must be rejected');

fwrite(STDOUT, "Capability policy tests passed\n");
