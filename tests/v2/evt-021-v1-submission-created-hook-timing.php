<?php

declare(strict_types=1);

// ================================================================
// EVT-021: real live acceptance testing (EVT-017's Section 12 sequence,
// triggering real Submission::add hooks via real QuickSubmit-created
// articles on dell) surfaced a previously-undocumented, real defect:
// v1's synchronous handleSubmissionCreated() -> safeGetPrimaryAuthor()
// -> $submission->getCurrentPublication() ALWAYS returns null for the
// exact $submission object real pkp-lib hands to the real
// `Submission::add` hook — meaning v1's `eventSubmissionCreated`
// auto-note has never actually delivered (or even enqueued into the
// legacy apiQueue) for a real submission created through EITHER the
// real QuickSubmit plugin OR the real REST API submission-creation
// endpoint (`PKPSubmissionController.php`, the same path the modern
// OJS 3.5 author submission wizard uses internally) -- both call the
// exact same `Repo::submission()->add()`.
//
// Root cause, confirmed against the real pkp-lib stable_3_5_0 source
// (lib/pkp/classes/submission/Repository.php):
//
//   $submission = $this->dao->insert($submission);          // (596)
//   $submission = Repo::submission()->get($submissionId);   // (597) <- re-fetched here
//   ...
//   $publicationId = Repo::publication()->add($publication);// (605)
//   $this->edit($submission, ['currentPublicationId' => $publicationId]); // (607)
//   Hook::call('Submission::add', [$submission]);           // (609) <- but $submission here is still the (597) object
//
// `edit()` builds an internal `$newSubmission` object and saves THAT
// to the DB -- it never reassigns the outer `$submission` variable
// `add()` still holds. So the exact object instance handed to every
// `Submission::add` hook callback (including this plugin's
// `handleSubmissionCreated()`) has `currentPublicationId === null`,
// and `PKPSubmission::getCurrentPublication()` (lib/pkp/classes/
// submission/PKPSubmission.php:84-96) returns null whenever
// `currentPublicationId` is falsy -- confirmed by direct source read,
// not inferred. `safeGetPrimaryAuthor()`'s very first step
// (`getCurrentPublication()`) therefore always returns null here, so
// `handleSubmissionCreated()` hits its `if (!$author...) return false`
// guard on every real invocation and NEVER calls dispatchEvent() at
// all -- no delivery attempt, no legacy apiQueue enqueue, no error,
// no log line. Confirmed live on dell: two real QuickSubmit-created
// test articles (submission IDs 22, 23) produced zero legacy apiQueue
// jobs and zero Chatwoot contacts for their real, distinct author
// emails.
//
// v2's SubmissionCreatedEventAdapter::fromSubmission() has NO such
// defect: it needs only the submission ID/context ID at enqueue time,
// and defers author resolution entirely to delivery time (the real
// scheduled `DeliverQueuedSupportEventsTask`, which re-loads the
// submission from the database -- by then genuinely holding
// `currentPublicationId`). This is real, confirmed evidence that v2's
// async/deferred design isn't merely cleaner architecture, but
// structurally necessary: v1's assumption of synchronous
// publication/author availability at `Submission::add` hook time does
// not hold against real pkp-lib's own hook-firing sequence. Confirmed
// live: the same two real test submissions produced two real v2 queue
// rows with status=delivered.
//
// This test asserts, against the real source tree, that v1's known
// -broken assumption is documented in code (not silently left to
// rediscover) and that v2's adapter genuinely does not share the same
// dependency.
// ================================================================

function evt021Check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$v1Source = (string) file_get_contents("{$root}/ChatwootIntegrationBasePlugin.php");
$adapterSource = (string) file_get_contents("{$root}/classes/v2/Event/SubmissionCreatedEventAdapter.php");

evt021Check(
    str_contains($v1Source, 'EVT-021'),
    'ChatwootIntegrationBasePlugin.php must document the real EVT-021 finding near safeGetPrimaryAuthor()/handleSubmissionCreated() so this known-broken v1 assumption is not silently rediscovered'
);

evt021Check(
    str_contains($adapterSource, 'getCurrentPublication') === false,
    'SubmissionCreatedEventAdapter::fromSubmission() must NOT call getCurrentPublication()/resolve the author at enqueue time -- doing so would reintroduce the exact EVT-021 defect (the real Submission::add hook object never has currentPublicationId set)'
);

evt021Check(
    str_contains($adapterSource, 'function fromSubmission') && str_contains($adapterSource, 'getId()'),
    'the v2 adapter must still genuinely resolve a real submission id at enqueue time, deferring only the author/publication lookup to delivery time'
);

fwrite(STDOUT, "PASS: evt-021-v1-submission-created-hook-timing\n");
