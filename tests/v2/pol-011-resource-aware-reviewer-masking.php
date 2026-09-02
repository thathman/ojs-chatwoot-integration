<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SubmissionRelationshipEvidenceProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ReviewerMaskingPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;

function pol011Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ================================================================
// POL-011 / CWO-016: the legacy widget-injection masking check
// (ChatwootIntegrationBasePlugin::addChatwootWidget()) used only the
// journal-level Reviewer role — an author on Submission A who also
// reviews Submission B was masked everywhere in the journal, including
// on pages about their own Submission A. ReviewerMaskingPolicy replaces
// that with resource/relationship-aware masking: it only relaxes
// masking when a real, resource-scoped, proven-by-OJS-evidence
// relationship (author/editorial/manager/site_admin) exists to the
// exact current submission. Reuses the same Fake* harness pattern as
// tests/v2/relationship.php.
// ================================================================

final class Pol011FakeSubmission
{
    public function __construct(private int $id, private int $contextId)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getData(string $key): mixed
    {
        return $key === 'contextId' ? $this->contextId : null;
    }
}

final class Pol011FakeEvidenceProvider implements SubmissionRelationshipEvidenceProviderInterface
{
    /** @param array<int,array<string,bool>> $evidenceBySubmissionId */
    public function __construct(private array $evidenceBySubmissionId)
    {
    }
    public function evidence(SupportContext $context, $submission): array
    {
        $id = is_object($submission) && method_exists($submission, 'getId') ? (int) $submission->getId() : 0;
        return $this->evidenceBySubmissionId[$id] ?? ['author' => false, 'reviewer' => false, 'editorial' => false, 'manager' => false, 'site_admin' => false];
    }
}

// Multi-role viewer: holds the journal-wide Reviewer role (roleId 4096),
// is a real proven author of Submission A (101), and a real proven
// reviewer of Submission B (102) — the exact scenario the directive names.
$viewer = new SupportContext(7, 'journal-a', 42, [4096], 'workflow', 'access', 'en');
$submissionA = new Pol011FakeSubmission(101, 7);
$submissionB = new Pol011FakeSubmission(102, 7);

$evidenceProvider = new Pol011FakeEvidenceProvider([
    101 => ['author' => true, 'reviewer' => false, 'editorial' => false, 'manager' => false, 'site_admin' => false],
    102 => ['author' => false, 'reviewer' => true, 'editorial' => false, 'manager' => false, 'site_admin' => false],
]);
$policy = new ReviewerMaskingPolicy(new SubmissionRelationshipResolver($evidenceProvider));

pol011Check(
    $policy->shouldMask($viewer, true, $submissionA) === false,
    'an author on Submission A must NOT be masked while viewing Submission A, even though they hold the Reviewer role elsewhere in the journal — the exact regression named by the directive'
);
pol011Check(
    $policy->shouldMask($viewer, true, $submissionB) === true,
    'a proven reviewer of Submission B must remain masked while viewing Submission B'
);
pol011Check(
    $policy->shouldMask($viewer, true, null) === true,
    'with no resolvable current resource (dashboard, generic pages), masking must fail closed to the original conservative journal-wide behavior'
);
pol011Check(
    $policy->shouldMask($viewer, false, $submissionA) === false,
    'a viewer who does not hold the Reviewer role anywhere in the journal must never be masked, regardless of resource context'
);

// A submission the viewer has NO proven relationship to at all (not
// author, not reviewer) must still fail closed to masked=true — absence
// of evidence is not evidence of a safe-to-unmask relationship.
$submissionC = new Pol011FakeSubmission(103, 7);
$noRelationProvider = new Pol011FakeEvidenceProvider([]);
$noRelationPolicy = new ReviewerMaskingPolicy(new SubmissionRelationshipResolver($noRelationProvider));
pol011Check(
    $noRelationPolicy->shouldMask($viewer, true, $submissionC) === true,
    'a submission with no proven relationship at all must stay masked (fail closed), not be treated as safe to unmask'
);

// Cross-journal resource must fail closed exactly as
// SubmissionRelationshipResolver already guarantees (relationship.php),
// re-asserted here through the policy's own public contract.
$crossJournalSubmission = new Pol011FakeSubmission(101, 9);
pol011Check(
    $policy->shouldMask($viewer, true, $crossJournalSubmission) === true,
    'a cross-journal resource must fail closed to masked=true through the policy, matching SubmissionRelationshipResolver'
);

// Guest/unauthenticated context (defensive — the real caller never
// invokes this without $user, but the policy itself must not misbehave).
$guest = new SupportContext(7, 'journal-a', null, [], 'workflow', 'access', 'en');
pol011Check(
    $policy->shouldMask($guest, true, $submissionA) === true,
    'an unauthenticated context must fail closed to masked=true'
);

// Real source wiring: the widget hook must actually use the new policy,
// not merely define it unused.
$pluginSource = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
pol011Check(str_contains($pluginSource, 'new ReviewerMaskingPolicy('), 'addChatwootWidget() must actually construct and use ReviewerMaskingPolicy, not just the legacy role-wide check');
pol011Check(str_contains($pluginSource, 'CurrentSubmissionResolver'), 'addChatwootWidget() must attempt to resolve the current resource before falling back to journal-wide masking');

fwrite(STDOUT, "PASS: pol-011-resource-aware-reviewer-masking\n");
