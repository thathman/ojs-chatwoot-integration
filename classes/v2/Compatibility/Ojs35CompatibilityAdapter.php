<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Compatibility;

use APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\OjsCompatibilityAdapterInterface;

final class Ojs35CompatibilityAdapter implements OjsCompatibilityAdapterInterface
{
    public function versionFamily(): string
    {
        return '3.5';
    }

    public function supportsVersion(string $version): bool
    {
        return preg_match('/^3\.5\./', trim($version)) === 1;
    }

    public function getContext($request)
    {
        return is_object($request) && method_exists($request, 'getContext')
            ? $request->getContext()
            : null;
    }

    public function getUser($request)
    {
        return is_object($request) && method_exists($request, 'getUser')
            ? $request->getUser()
            : null;
    }

    public function getRoleIds($user, int $contextId): array
    {
        if (!is_object($user) || !method_exists($user, 'getRoles')) {
            return [];
        }

        $ids = [];
        foreach ($user->getRoles($contextId) as $role) {
            if (is_object($role) && method_exists($role, 'getId')) {
                $ids[] = (int) $role->getId();
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /**
     * Re-derives a live user by ID, independent of any request/session.
     *
     * Used to refresh a support session's roles at call time instead of
     * trusting whatever roles a user held at bind time.
     */
    public function getUserById(int $userId)
    {
        if ($userId <= 0 || !class_exists('\PKP\user\Repo')) {
            return null;
        }

        return \PKP\user\Repo::user()->get($userId);
    }

    /**
     * Loads a submission by ID only — never trusts a journal/context claim
     * that came with the ID. Callers must independently confirm the
     * submission's own contextId matches the caller's journal (see
     * SubmissionRelationshipResolver::resolve()).
     */
    public function getSubmissionById(int $submissionId)
    {
        if ($submissionId <= 0 || !class_exists('\APP\facades\Repo')) {
            return null;
        }

        return \APP\facades\Repo::submission()->get($submissionId);
    }

    /**
     * Candidate discovery only — mirrors the same collector call PKP core
     * itself uses for "my assignments" (PKPBackendSubmissionsController::
     * assigned() / reviews() / bulkDeleteIncompleteSubmissions(), pkp-lib
     * stable-3_5_0): assignedTo() with no role restriction matches any
     * stage assignment (author, editorial, ...) or non-declined/cancelled
     * review assignment for that user. This is deliberately broad; the
     * caller's relationship resolver is what actually decides author vs.
     * reviewer vs. "not really theirs".
     */
    public function listCandidateSubmissions(int $contextId, int $userId, int $candidateCap): array
    {
        if ($contextId <= 0 || $userId <= 0 || $candidateCap <= 0 || !class_exists('\APP\facades\Repo')) {
            return [];
        }

        try {
            $collector = \APP\facades\Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->assignedTo([$userId])
                ->limit($candidateCap)
                ->offset(0);

            // ->all(), not ->toArray(): the latter would recursively convert
            // each Submission object into a plain array via Arrayable.
            return $collector->getMany()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Verified against pkp-lib stable-3_5_0: title lives on the current Publication, not Submission. */
    public function getSubmissionTitle($submission): string
    {
        if (!is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
            return '';
        }

        try {
            $publication = $submission->getCurrentPublication();
            if (!is_object($publication) || !method_exists($publication, 'getLocalizedTitle')) {
                return '';
            }
            return trim((string) $publication->getLocalizedTitle());
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 classes/publication/PKPPublication.php
     * (getDoi()) and ojs classes/publication/Publication.php (getIssueId()).
     *
     * @return array{doi:?string,issueId:?int}
     */
    public function getPublicationFields($submission): array
    {
        if (!is_object($submission) || !method_exists($submission, 'getCurrentPublication')) {
            return ['doi' => null, 'issueId' => null];
        }

        try {
            $publication = $submission->getCurrentPublication();
            if (!is_object($publication)) {
                return ['doi' => null, 'issueId' => null];
            }
            $doi = method_exists($publication, 'getDoi') ? $publication->getDoi() : null;
            $issueId = method_exists($publication, 'getIssueId') ? $publication->getIssueId() : null;
            return [
                'doi' => is_string($doi) && $doi !== '' ? $doi : null,
                'issueId' => is_numeric($issueId) ? (int) $issueId : null,
            ];
        } catch (\Throwable $e) {
            return ['doi' => null, 'issueId' => null];
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 ojs classes/issue/Issue.php
     * (getVolume/getNumber/getYear/getPublished) and Repository.php (get()).
     *
     * @return array{volume:?int,number:?int,year:?int,published:bool}|null
     */
    public function getIssueInfo(int $issueId): ?array
    {
        if ($issueId <= 0 || !class_exists('\APP\facades\Repo')) {
            return null;
        }

        try {
            $issue = \APP\facades\Repo::issue()->get($issueId);
            if (!is_object($issue)) {
                return null;
            }
            $volume = method_exists($issue, 'getVolume') ? $issue->getVolume() : null;
            $number = method_exists($issue, 'getNumber') ? $issue->getNumber() : null;
            $year = method_exists($issue, 'getYear') ? $issue->getYear() : null;
            return [
                'volume' => is_numeric($volume) ? (int) $volume : null,
                'number' => is_numeric($number) ? (int) $number : null,
                'year' => is_numeric($year) ? (int) $year : null,
                'published' => method_exists($issue, 'getPublished') ? (bool) $issue->getPublished() : false,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 ojs pages/article/ArticleHandler.php,
     * which builds the public article URL the same way:
     * $request->getDispatcher()->url($request, ROUTE_PAGE, null, 'article', 'view', [$submission->getBestId()]).
     * Never called unless the caller has already confirmed the submission is
     * actually published (see supportPublicationStatusRequest()) — this
     * method itself does not check status, since it has no state fields
     * to check without an extra query the caller already has cheaper access to.
     */
    public function getPublicSubmissionUrl($request, $submission): ?string
    {
        if (!is_object($request) || !is_object($submission) || !method_exists($submission, 'getBestId')) {
            return null;
        }

        try {
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'url')) {
                return null;
            }
            $url = $dispatcher->url($request, \PKP\core\PKPApplication::ROUTE_PAGE, null, 'article', 'view', [$submission->getBestId()]);
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // Verified against pkp-lib stable-3_5_0 classes/core/PKPApplication.php
    private const WORKFLOW_STAGE_ID_INTERNAL_REVIEW = 2;
    private const WORKFLOW_STAGE_ID_EXTERNAL_REVIEW = 3;

    public function getSubmissionStateFields($submission): array
    {
        if (!is_object($submission) || !method_exists($submission, 'getData')) {
            return ['status' => null, 'stageId' => null, 'reviewRoundStatus' => null, 'submissionProgress' => null];
        }

        try {
            $status = $submission->getData('status');
            $stageId = $submission->getData('stageId');
            $stageId = is_numeric($stageId) ? (int) $stageId : null;
            $submissionProgress = $submission->getData('submissionProgress');

            return [
                'status' => is_numeric($status) ? (int) $status : null,
                'stageId' => $stageId,
                'reviewRoundStatus' => $this->getCurrentReviewRoundStatus($submission, $stageId),
                'submissionProgress' => is_string($submissionProgress) ? $submissionProgress : null,
            ];
        } catch (\Throwable $e) {
            return ['status' => null, 'stageId' => null, 'reviewRoundStatus' => null, 'submissionProgress' => null];
        }
    }

    /**
     * Only meaningful inside an active review stage; the round's `status`
     * column is maintained live by ReviewRoundDAO on every relevant event
     * (decisions, revision uploads, assignment changes) — read-only here,
     * this never recomputes it independently.
     */
    private function getCurrentReviewRoundStatus($submission, ?int $stageId): ?int
    {
        if ($stageId !== self::WORKFLOW_STAGE_ID_INTERNAL_REVIEW && $stageId !== self::WORKFLOW_STAGE_ID_EXTERNAL_REVIEW) {
            return null;
        }
        if (!method_exists($submission, 'getId') || !class_exists('\PKP\db\DAORegistry')) {
            return null;
        }

        try {
            $reviewRoundDao = \PKP\db\DAORegistry::getDAO('ReviewRoundDAO');
            $reviewRound = $reviewRoundDao->getLastReviewRoundBySubmissionId((int) $submission->getId(), $stageId);
            if (!is_object($reviewRound) || !method_exists($reviewRound, 'getStatus')) {
                return null;
            }
            $status = $reviewRound->getStatus();
            return is_numeric($status) ? (int) $status : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Every ReviewAssignment this user holds for this submission, across all
     * rounds — status only, via PKP's own computed getStatus() (never
     * re-derives its overdue-date/decline/resend logic independently, see
     * classes/v2/State/RequiredActionMapper.php for how these are used).
     *
     * @return int[]
     */
    public function getReviewAssignmentStatuses(int $submissionId, int $userId): array
    {
        if ($submissionId <= 0 || $userId <= 0 || !class_exists('\APP\facades\Repo')) {
            return [];
        }

        try {
            $assignments = \APP\facades\Repo::reviewAssignment()
                ->getCollector()
                ->filterBySubmissionIds([$submissionId])
                ->filterByReviewerIds([$userId])
                ->getMany();

            $statuses = [];
            foreach ($assignments as $assignment) {
                if (!is_object($assignment) || !method_exists($assignment, 'getStatus')) {
                    continue;
                }
                $status = $assignment->getStatus();
                if (is_numeric($status)) {
                    $statuses[] = (int) $status;
                }
            }
            return $statuses;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getRequestedPage($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedPage')
            ? trim((string) $request->getRequestedPage())
            : '';
    }

    public function getRequestedOperation($request): string
    {
        return is_object($request) && method_exists($request, 'getRequestedOp')
            ? trim((string) $request->getRequestedOp())
            : '';
    }

    /**
     * Reads the same fields OJS's own OJSPaymentManager reads (publicationEnabled(),
     * isConfigured()) rather than re-deriving them — a configured payment
     * gateway plugin AND a positive publicationFee AND paymentsEnabled must
     * all be true, verified against pkp-lib/ojs stable-3_5_0
     * classes/payment/ojs/OJSPaymentManager.php. amount/currency are only
     * ever non-null when the fee is actually enabled.
     *
     * @return array{enabled:bool,amount:?float,currency:?string}
     */
    public function getPaymentFeeInfo($context): array
    {
        if (!is_object($context) || !class_exists('\APP\payment\ojs\OJSPaymentManager')) {
            return ['enabled' => false, 'amount' => null, 'currency' => null];
        }

        try {
            $paymentManager = new \APP\payment\ojs\OJSPaymentManager($context);
            $enabled = $paymentManager->isConfigured() && $paymentManager->publicationEnabled();
            if (!$enabled) {
                return ['enabled' => false, 'amount' => null, 'currency' => null];
            }
            $amount = $context->getData('publicationFee');
            $currency = $context->getData('currency');
            return [
                'enabled' => true,
                'amount' => is_numeric($amount) ? (float) $amount : null,
                'currency' => is_string($currency) && $currency !== '' ? $currency : null,
            ];
        } catch (\Throwable $e) {
            return ['enabled' => false, 'amount' => null, 'currency' => null];
        }
    }

    /**
     * Verified against pkp-lib/ojs stable-3_5_0
     * classes/payment/ojs/OJSCompletedPaymentDAO.php hasPaidPublication() —
     * looks for a completed PAYMENT_TYPE_PUBLICATION payment for this
     * user+submission. Never re-derives payment-completion logic itself.
     */
    public function hasPaidPublicationFee(int $userId, int $submissionId): bool
    {
        if ($userId <= 0 || $submissionId <= 0 || !class_exists('\PKP\db\DAORegistry')) {
            return false;
        }

        try {
            $dao = \PKP\db\DAORegistry::getDAO('OJSCompletedPaymentDAO');
            return (bool) $dao->hasPaidPublication($userId, $submissionId);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Verified against pkp-lib stable-3_5_0 classes/user/User.php
     * (getDisabled()/getDateValidated()). Never reads getDisabledReason() —
     * that is free-form admin-entered text, unsafe to surface.
     *
     * @return array{disabled:?bool,dateValidated:?string}
     */
    public function getUserAccountFields(int $userId): array
    {
        $fallback = ['disabled' => null, 'dateValidated' => null];
        $user = $this->getUserById($userId);
        if (!is_object($user)) {
            return $fallback;
        }

        try {
            $disabled = method_exists($user, 'getDisabled') ? $user->getDisabled() : null;
            $dateValidated = method_exists($user, 'getDateValidated') ? $user->getDateValidated() : null;
            return [
                'disabled' => is_bool($disabled) ? $disabled : ($disabled === null ? null : (bool) $disabled),
                'dateValidated' => is_string($dateValidated) && $dateValidated !== '' ? $dateValidated : null,
            ];
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Builds the secure verification link URL. Verified against pkp-lib
     * stable-3_5_0 (the same $request->getDispatcher()->url() call
     * getPublicSubmissionUrl() uses). The query string carries only the
     * opaque challenge reference and the high-entropy token — never a user
     * ID, email, capability, role, or submission ID.
     */
    public function getVerificationLinkUrl($request, string $publicReference, string $token): ?string
    {
        if (!is_object($request)) {
            return null;
        }

        try {
            $dispatcher = method_exists($request, 'getDispatcher') ? $request->getDispatcher() : null;
            if (!is_object($dispatcher) || !method_exists($dispatcher, 'url')) {
                return null;
            }
            $url = $dispatcher->url(
                $request,
                \PKP\core\PKPApplication::ROUTE_PAGE,
                null,
                'ojsSupportGateway',
                'verify',
                null,
                ['challenge' => $publicReference, 'token' => $token]
            );
            return is_string($url) && $url !== '' ? $url : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The claimed email is a lookup key only, never identity — the caller
     * must treat a null return as generically "verification requested"
     * anyway (anti-enumeration), never surface "no such account." Verified
     * against pkp-lib stable-3_5_0 classes/user/Repository.php
     * getByEmail(): `allowDisabled` defaults false, so a disabled account's
     * email correctly resolves to null here too — indistinguishable from a
     * nonexistent one, with no extra logic needed in this codebase.
     */
    public function getUserByEmail(string $email): ?object
    {
        if (trim($email) === '' || !class_exists('\PKP\user\Repo')) {
            return null;
        }

        try {
            return \PKP\user\Repo::user()->getByEmail(trim($email));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Detects the Airix Submission Fee sibling plugin (docs/v2/AIRIX360_INTEGRATIONS.md
     * §5.2) and, when present/enabled/compatible, wraps it in a
     * AirixSubmissionFeeProvider. Returns null on absence, disablement, or
     * an unrecognized major version — the caller must treat that exactly
     * like "no such provider," never as an error, per the Airix provider
     * SDK's fail-closed/degrade-gracefully rule.
     *
     * Uses the same `PluginRegistry::getPlugin('generic', strtolower(ClassName))`
     * lookup convention that PaymentHelper::waiverDiscount() already relies
     * on for the Request Waiver plugin.
     */
    public function getAirixSubmissionFeeProvider($context): ?\APP\plugins\generic\chatwootIntegration\classes\v2\Contracts\PaymentSupportProviderInterface
    {
        $detected = $this->detectAirixSubmissionFeePlugin();
        if ($detected === null) {
            return null;
        }

        try {
            return new \APP\plugins\generic\chatwootIntegration\classes\v2\Provider\AirixSubmissionFeeProvider(
                $detected['plugin'],
                $detected['helper'],
                $detected['version']
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Public *policy* facts only (docs/v2/AIRIX360_INTEGRATIONS.md §5.2) —
     * the configured submission fee amount/currency/enabled flag a journal
     * publishes, never a specific submission's paid/unpaid/waived
     * obligation. Deliberately not the same accessor/interface as
     * getAirixSubmissionFeeProvider(): `PaymentSupportProviderInterface`
     * answers "what does this submission owe", this answers "what does
     * this journal publicly charge" — different trust contracts, kept
     * separate even though both read the same sibling plugin.
     *
     * @return array{enabled:bool,amount:?float,currency:?string}|null
     */
    public function getAirixSubmissionFeePolicy($context): ?array
    {
        $detected = $this->detectAirixSubmissionFeePlugin();
        if ($detected === null || !is_object($context)) {
            return null;
        }

        try {
            $helper = $detected['helper'];
            if (!method_exists($helper, 'feeEnabled')) {
                return null;
            }
            $enabled = (bool) $helper->feeEnabled($context);
            return [
                'enabled' => $enabled,
                'amount' => $enabled && method_exists($helper, 'amount') ? (float) $helper->amount($context) : null,
                'currency' => $enabled && method_exists($helper, 'currency') ? (string) $helper->currency($context) : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{plugin:object,helper:object,version:string}|null
     */
    private function detectAirixSubmissionFeePlugin(): ?array
    {
        if (!class_exists('\PKP\plugins\PluginRegistry')) {
            return null;
        }

        try {
            \PKP\plugins\PluginRegistry::loadCategory('generic');
            $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'submissionfeeplugin');
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_object($plugin) || !method_exists($plugin, 'getEnabled') || !$plugin->getEnabled()) {
            return null;
        }

        $helperClass = '\APP\plugins\generic\submissionFee\PaymentHelper';
        if (!class_exists($helperClass)) {
            return null;
        }

        try {
            $version = '0.0.0.0';
            if (method_exists($plugin, 'getCurrentVersion')) {
                $versionObject = $plugin->getCurrentVersion();
                if (is_object($versionObject) && method_exists($versionObject, 'getVersionString')) {
                    $version = (string) $versionObject->getVersionString();
                }
            }
            return ['plugin' => $plugin, 'helper' => new $helperClass($plugin), 'version' => $version];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Official, journal-manager-authored public pages from OJS core's own
     * Static Pages plugin — verified against a real local checkout of
     * `pkp/staticPages` @ `0a84bbe738b3356ac57fe99c66f2792f0d7016bb`
     * (`classes/StaticPage.php`, `classes/StaticPagesDAO.php`). This is
     * the one OJS-managed "explicitly made public" page surface used —
     * never a domain crawl of arbitrary journal-website URLs.
     *
     * @return array<int,array{path:string,title:string,content:string}>
     */
    public function getOfficialPublicPages($context, string $locale): array
    {
        if (!is_object($context) || !method_exists($context, 'getId')) {
            return [];
        }
        if (!class_exists('\PKP\plugins\PluginRegistry') || !class_exists('\PKP\db\DAORegistry')) {
            return [];
        }

        try {
            \PKP\plugins\PluginRegistry::loadCategory('generic');
            $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'staticpagesplugin');
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_object($plugin) || !method_exists($plugin, 'getEnabled') || !$plugin->getEnabled()) {
            return [];
        }

        try {
            $dao = \PKP\db\DAORegistry::getDAO('StaticPagesDAO');
            $result = $dao->getByContextId((int) $context->getId());
            $pages = method_exists($result, 'toArray') ? $result->toArray() : [];
        } catch (\Throwable $e) {
            return [];
        }

        $officialPages = [];
        foreach ($pages as $page) {
            if (!is_object($page) || !method_exists($page, 'getPath') || !method_exists($page, 'getTitle') || !method_exists($page, 'getContent')) {
                continue;
            }
            try {
                $path = (string) $page->getPath();
                $title = (string) $page->getTitle($locale);
                $content = (string) $page->getContent($locale);
            } catch (\Throwable $e) {
                continue;
            }
            if ($path === '' || ($title === '' && $content === '')) {
                continue;
            }
            $officialPages[] = ['path' => $path, 'title' => $title, 'content' => $content];
        }

        return $officialPages;
    }

    /**
     * Public *policy* text only (docs/v2/AIRIX360_INTEGRATIONS.md §5.3) —
     * the journal's own configured waiver-request box title/body, never a
     * specific submission's waiver decision/history. Verified against a
     * real local checkout of `Airix360/ojs-request-waiver`
     * (`RequestWaiverPlugin::activeFeeType()`, `SettingsForm`'s
     * `boxTitle`/`boxBody` settings — plain, non-localized strings: its
     * own `readInputData()` calls `readUserVars()`, not
     * `readLocalizedUserVars()`, so there is no per-locale value to
     * request here). Deliberately not the same method a future
     * obligation-reading accessor would use — this only ever reads the
     * plugin's own journal-level configured text, never a submission's
     * `waiverStatus`/`waiverPercent`.
     *
     * @return array{enabled:bool,title:?string,body:?string}|null
     */
    public function getAirixRequestWaiverPolicy($context): ?array
    {
        if (!is_object($context) || !method_exists($context, 'getId') || !class_exists('\PKP\plugins\PluginRegistry')) {
            return null;
        }

        try {
            \PKP\plugins\PluginRegistry::loadCategory('generic');
            $plugin = \PKP\plugins\PluginRegistry::getPlugin('generic', 'requestwaiverplugin');
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_object($plugin) || !method_exists($plugin, 'getEnabled') || !$plugin->getEnabled((int) $context->getId())) {
            return null;
        }

        try {
            $activeFeeType = method_exists($plugin, 'activeFeeType') ? $plugin->activeFeeType($context) : null;
            if ($activeFeeType === null) {
                return ['enabled' => false, 'title' => null, 'body' => null];
            }

            $title = method_exists($plugin, 'getSetting') ? trim((string) $plugin->getSetting($context->getId(), 'boxTitle')) : '';
            $body = method_exists($plugin, 'getSetting') ? trim((string) $plugin->getSetting($context->getId(), 'boxBody')) : '';

            return [
                'enabled' => true,
                'title' => $title !== '' ? $title : null,
                'body' => $body !== '' ? $body : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
