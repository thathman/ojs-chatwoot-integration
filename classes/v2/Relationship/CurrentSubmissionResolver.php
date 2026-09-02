<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Relationship;

/**
 * Best-effort resolution of "the submission the current page is about",
 * for use only as an input to privacy/masking policy — never as an
 * authorization decision on its own, and never trusted on its own to prove
 * a relationship. A wrong/coincidental submission id here cannot cause an
 * incorrect unmask: ReviewerMaskingPolicy only relaxes masking when
 * SubmissionRelationshipResolver finds *real* OJS workflow-stage/review
 * -assignment evidence for the exact resolved submission, so a mismatch
 * here only ever fails closed (stays masked), never leaks.
 *
 * Returns null whenever no positive, real submission id can be determined
 * — callers must treat null as "no resource context".
 */
final class CurrentSubmissionResolver
{
    /**
     * Pages where OJS core exposes a submission id in the URL path (first
     * requested arg), matching the real page-router convention
     * SubmissionRequiredPolicy/DataObjectRequiredPolicy use in pkp-lib.
     * Restricting the positional-arg fallback to these avoids
     * misinterpreting an unrelated page's numeric first arg (e.g. an
     * announcement or issue id) as a submission id.
     *
     * @var string[]
     */
    private const SUBMISSION_SCOPED_PAGES = ['workflow', 'reviewer', 'authorDashboard', 'submission', 'editor'];

    public function resolve($request): ?object
    {
        $candidate = $request->getUserVar('submissionId');

        if (($candidate === null || $candidate === '') && method_exists($request, 'getRequestedPage')) {
            $page = (string) $request->getRequestedPage();
            if (in_array($page, self::SUBMISSION_SCOPED_PAGES, true) && method_exists($request, 'getRequestedArgs')) {
                $args = $request->getRequestedArgs();
                $candidate = $args[0] ?? null;
            }
        }

        if ($candidate === null || $candidate === '' || !ctype_digit((string) $candidate)) {
            return null;
        }

        $submissionId = (int) $candidate;
        if ($submissionId <= 0) {
            return null;
        }

        try {
            $submission = \APP\facades\Repo::submission()->get($submissionId);
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($submission) ? $submission : null;
    }
}
