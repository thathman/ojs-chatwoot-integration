<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\SubmissionRelationshipEvidenceProviderInterface;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;

function relationshipCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeSubmission
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

final class FakeEvidenceProvider implements SubmissionRelationshipEvidenceProviderInterface
{
    public function __construct(private array $evidence)
    {
    }
    public function evidence(SupportContext $context, $submission): array
    {
        return $this->evidence;
    }
}

$multiRoleContext = new SupportContext(7, 'journal-a', 42, [16, 4096], 'workflow', 'index', 'en');
$submission = new FakeSubmission(101, 7);

// The journal-level Reviewer role does not create a reviewer relationship.
$authorOnly = (new SubmissionRelationshipResolver(new FakeEvidenceProvider([
    'author' => true,
    'reviewer' => false,
    'editorial' => false,
    'manager' => false,
    'site_admin' => false,
])))->resolve($multiRoleContext, $submission);
relationshipCheck($authorOnly !== null, 'authenticated in-context relationship should resolve');
relationshipCheck($authorOnly->types() === ['author'], 'reviewer role without assignment must not create reviewer relationship');
relationshipCheck(!$authorOnly->has('reviewer'), 'reviewer relationship must require evidence');

$reviewerOnly = (new SubmissionRelationshipResolver(new FakeEvidenceProvider([
    'author' => false,
    'reviewer' => true,
    'editorial' => false,
    'manager' => false,
    'site_admin' => false,
])))->resolve($multiRoleContext, $submission);
relationshipCheck($reviewerOnly?->types() === ['reviewer'], 'actual review evidence should create reviewer relationship');

$both = (new SubmissionRelationshipResolver(new FakeEvidenceProvider([
    'author' => true,
    'reviewer' => true,
    'editorial' => false,
    'manager' => false,
    'site_admin' => false,
])))->resolve($multiRoleContext, $submission);
relationshipCheck($both?->types() === ['author', 'reviewer'], 'multiple proven relationships must be retained rather than collapsed');

$editorial = (new SubmissionRelationshipResolver(new FakeEvidenceProvider([
    'author' => false,
    'reviewer' => false,
    'editorial' => true,
    'manager' => true,
    'site_admin' => false,
])))->resolve($multiRoleContext, $submission);
relationshipCheck($editorial?->types() === ['editorial', 'manager'], 'editorial and manager evidence should coexist');

$crossJournal = (new SubmissionRelationshipResolver(new FakeEvidenceProvider(['author' => true])))
    ->resolve($multiRoleContext, new FakeSubmission(101, 8));
relationshipCheck($crossJournal === null, 'cross-journal resource must fail closed before consulting relationship evidence');

$guest = new SupportContext(7, 'journal-a', null, [], 'workflow', 'index', 'en');
$guestRelationship = (new SubmissionRelationshipResolver(new FakeEvidenceProvider(['author' => true])))
    ->resolve($guest, $submission);
relationshipCheck($guestRelationship === null, 'guest must not receive an authenticated resource relationship');

$providerSource = file_get_contents($root . '/classes/v2/Relationship/OjsSubmissionRelationshipEvidenceProvider.php');
relationshipCheck(str_contains((string) $providerSource, 'getAccessibleWorkflowStages'), 'OJS provider must use workflow-stage evidence for author/editorial relationship');
relationshipCheck(str_contains((string) $providerSource, 'filterByReviewerIds'), 'OJS provider must require actual reviewer assignment evidence');
relationshipCheck(str_contains((string) $providerSource, 'filterBySubmissionIds'), 'review assignment evidence must be submission-scoped');

fwrite(STDOUT, "Relationship tests passed\n");
