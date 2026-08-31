<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/v2/bootstrap.php';

use APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility\Ojs35CompatibilityAdapter;

function primarySubmissionAuthorCheck(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class FakeAuthorForPrimaryAuthorTest
{
    public function __construct(private int $id, private string $email, private string $name)
    {
    }
    public function getId(): int
    {
        return $this->id;
    }
    public function getEmail(): string
    {
        return $this->email;
    }
    public function getFullName(): string
    {
        return $this->name;
    }
}

final class FakePublicationForPrimaryAuthorTest
{
    public function __construct(private ?object $primaryAuthor, private array $authors = [])
    {
    }
    public function getPrimaryAuthor(): ?object
    {
        return $this->primaryAuthor;
    }
    public function getData(string $key): mixed
    {
        return $key === 'authors' ? $this->authors : null;
    }
}

final class FakeSubmissionForPrimaryAuthorTest
{
    public function __construct(private ?object $publication)
    {
    }
    public function getCurrentPublication(): ?object
    {
        return $this->publication;
    }
}

$adapter = new Ojs35CompatibilityAdapter();

// --- real primary author with an email ---
$author = new FakeAuthorForPrimaryAuthorTest(42, 'author@example.test', 'Ada Author');
$submission = new FakeSubmissionForPrimaryAuthorTest(new FakePublicationForPrimaryAuthorTest($author));
$result = $adapter->getPrimarySubmissionAuthor($submission);
primarySubmissionAuthorCheck($result === ['email' => 'author@example.test', 'name' => 'Ada Author', 'userId' => 42], 'a real primary author with an email must resolve exactly its email/name/userId');

// --- primary author present but with no getEmail() at all must fall back to the authors list ---
final class FakeAuthorNoEmailMethod
{
    public function getId(): int
    {
        return 99;
    }
}
$fallbackAuthor = new FakeAuthorForPrimaryAuthorTest(43, 'fallback@example.test', 'Backup Author');
$submissionWithFallback = new FakeSubmissionForPrimaryAuthorTest(
    new FakePublicationForPrimaryAuthorTest(new FakeAuthorNoEmailMethod(), [$fallbackAuthor])
);
$fallbackResult = $adapter->getPrimarySubmissionAuthor($submissionWithFallback);
primarySubmissionAuthorCheck($fallbackResult['email'] === 'fallback@example.test', 'when the primary author has no usable getEmail(), the first authors-list entry with one must be used, mirroring v1\'s real fallback chain');

// --- no publication at all ---
$noPublication = new FakeSubmissionForPrimaryAuthorTest(null);
primarySubmissionAuthorCheck($adapter->getPrimarySubmissionAuthor($noPublication) === null, 'a submission with no current publication must resolve to null, never a fabricated author');

// --- publication with no author anywhere ---
$noAuthor = new FakeSubmissionForPrimaryAuthorTest(new FakePublicationForPrimaryAuthorTest(null, []));
primarySubmissionAuthorCheck($adapter->getPrimarySubmissionAuthor($noAuthor) === null, 'a publication with no primary author and an empty authors list must resolve to null');

// --- an author with a blank email must never resolve as a valid contact target ---
$blankEmailAuthor = new FakeAuthorForPrimaryAuthorTest(44, '  ', 'Blank Email Author');
$blankEmailSubmission = new FakeSubmissionForPrimaryAuthorTest(new FakePublicationForPrimaryAuthorTest($blankEmailAuthor));
primarySubmissionAuthorCheck($adapter->getPrimarySubmissionAuthor($blankEmailSubmission) === null, 'a blank/whitespace-only email must never resolve as a usable delivery target');

// --- invalid input degrades safely ---
primarySubmissionAuthorCheck($adapter->getPrimarySubmissionAuthor(null) === null, 'a non-object submission must return null');
primarySubmissionAuthorCheck($adapter->getPrimarySubmissionAuthor('not an object') === null, 'a non-object submission must return null');

fwrite(STDOUT, "Primary submission author tests passed\n");
