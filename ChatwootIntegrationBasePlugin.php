<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\chatwootIntegration\classes\v2\Api\CorrelationId;
use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\DatabaseSupportApiAuditLogger;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Diagnostics\SafeExceptionMessage;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\CurrentSubmissionResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ReviewerMaskingPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Settings\SettingsRegistry;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\decision\Decision;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\submission\PKPSubmission;

class ChatwootIntegrationBasePlugin extends GenericPlugin {
    private const QUEUE_KEY = 'apiQueue';

    /**
     * SETTINGS-SMALL-002/UX-024: the canonical key list for import,
     * Save Global Profile, and Apply Global Profile (importSettings()/
     * saveGlobalProfile()/applyGlobalProfile() below are inherited by
     * v2 unchanged), sourced from `SettingsRegistry::exportableKeys()`
     * (a class const can't call a static method, so this is a method,
     * not a const). It must stay in sync with the v2 plugin's own
     * `legacyExportKeys()` (which drives the export side, filtered
     * through `ExportPolicy`) so an export→import round-trip never
     * silently drops a real, non-secret setting —
     * `tests/v2/settings-registry.php` is the automated drift guard
     * for that. `mcpServiceToken` is `exportable: false` in the
     * registry, so it can never appear here —
     * `tests/v2/settings-form-mcp-secret-masking.php` asserts this
     * (ADR-021: structurally impossible to export or import via the
     * settings backup path).
     */
    private static function exportKeys(): array {
        return SettingsRegistry::exportableKeys();
    }

    public function register($category, $path, $mainContextId = null) {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            Hook::add('TemplateManager::display', [$this, 'addChatwootWidget']);
            Hook::add('TemplateManager::fetch', [$this, 'addChatwootWidget']);
            Hook::add('Templates::Common::Footer::PageFooter', [$this, 'addChatwootWidgetFromFooterHook']);
            Hook::add('Decision::add', [$this, 'handleEditorDecision']);
            Hook::add('Submission::add', [$this, 'handleSubmissionCreated']);
            Hook::add('Submission::updateStatus', [$this, 'handleSubmissionStatusUpdated']);
            Hook::add('Publication::publish', [$this, 'handlePublicationPublished']);
        }
        return $success;
    }

    public function getDisplayName() { return __('plugins.generic.chatwootIntegration.displayName'); }
    public function getDescription() { return __('plugins.generic.chatwootIntegration.description'); }

    public function getActions($request, $verb) {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) { return $actions; }
        $router = $request->getRouter();
        $settingsUrl = $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']);
        array_unshift($actions, new LinkAction('settings', new AjaxModal($settingsUrl, $this->getDisplayName()), __('manager.plugins.settings')));
        $syncUrl = $router->url($request, null, null, 'manage', null, ['verb' => 'syncTemplates', 'plugin' => $this->getName(), 'category' => 'generic']);
        array_unshift($actions, new LinkAction('syncTemplates', new AjaxModal($syncUrl, __('plugins.generic.chatwootIntegration.syncTemplates')), __('plugins.generic.chatwootIntegration.syncTemplates')));
        return $actions;
    }

    public function manage($args, $request) {
        $verb = $request->getUserVar('verb');
        if ($verb === 'syncTemplates') return $this->syncEmailTemplates($request);
        if ($verb === 'healthCheck') return $this->runHealthCheck($request);
        if ($verb === 'testMessage') return $this->sendTestMessage($request);
        if ($verb === 'exportSettings') return $this->exportSettings($request);
        if ($verb === 'importSettings') return $this->importSettings($request);
        if ($verb === 'saveGlobalProfile') return $this->saveGlobalProfile($request);
        if ($verb === 'applyGlobalProfile') return $this->applyGlobalProfile($request);

        if ($verb !== 'settings') return parent::manage($args, $request);

        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));

        $form = new ChatwootSettingsForm($this, $context->getId());
        if (!$request->getUserVar('save')) {
            $form->initData();
            return new JSONMessage(true, $form->fetch($request));
        }

        $form->readInputData();
        if (!$form->validate()) return new JSONMessage(true, $form->fetch($request));
        $form->execute();
        return new JSONMessage(true);
    }

    public function runHealthCheck($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();

        $baseUrl = $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $websiteToken = (string) $this->getEffectiveSetting($contextId, 'chatwootWebsiteToken', '');
        $apiToken = (string) $this->getEffectiveSetting($contextId, 'chatwootApiAccessToken', '');
        $identitySecret = (string) $this->getEffectiveSetting($contextId, 'chatwootIdentityValidationSecret', '');

        $checks = ['sdkReachable' => false, 'apiTokenValid' => null, 'identityHmacValid' => null, 'configured' => [
            'baseUrl' => $baseUrl !== '', 'websiteToken' => $websiteToken !== '', 'apiAccessToken' => $apiToken !== '', 'identitySecret' => $identitySecret !== ''
        ]];

        if ($baseUrl !== '') {
            $service = new ChatwootApiService($baseUrl, $apiToken ?: 'invalid');
            $checks['sdkReachable'] = $service->checkSdkReachable();
            if ($apiToken !== '') $checks['apiTokenValid'] = $service->validateApiToken();
        }
        if ($identitySecret !== '') {
            $h = hash_hmac('sha256', 'health-check', $identitySecret);
            $checks['identityHmacValid'] = is_string($h) && strlen($h) === 64;
        }
        return new JSONMessage(true, $checks);
    }

    public function sendTestMessage($request): JSONMessage {
        $context = $request->getContext();
        $user = $request->getUser();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        if (!$user || !$user->getEmail()) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noUserForTest'));

        $contextId = (int) $context->getId();
        $ok = $this->dispatchEvent($contextId, [
            'email' => $user->getEmail(),
            'name' => $user->getFullName(),
            'identifier' => (string) $user->getId(),
            'message' => __('plugins.generic.chatwootIntegration.testMessageBody', ['date' => date('c')]),
            'attributes' => ['context_type' => 'plugin_test', 'journal_id' => $contextId, 'journal_name' => $context->getLocalizedName()],
            'eventAction' => (string) $this->getEffectiveSetting($contextId, 'eventSyncMode', 'note'),
        ], true);

        return $ok ? new JSONMessage(true, __('plugins.generic.chatwootIntegration.testMessageSuccess')) : new JSONMessage(false, __('plugins.generic.chatwootIntegration.testMessageFailed'));
    }

    public function exportSettings($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();
        $out = [];
        foreach (self::exportKeys() as $k) $out[$k] = $this->getSetting($contextId, $k);
        return new JSONMessage(true, ['contextId' => $contextId, 'exportedAt' => date('c'), 'settings' => $out]);
    }

    public function importSettings($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $payload = json_decode((string) $request->getUserVar('importPayload'), true);
        if (!is_array($payload) || !isset($payload['settings']) || !is_array($payload['settings'])) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.import.invalidJson'));
        $contextId = (int) $context->getId();
        foreach ($payload['settings'] as $k => $v) {
            if (!in_array($k, self::exportKeys(), true)) continue;
            if (!$this->isImportValueSafe((string) $k, $v)) continue;
            $this->updateSetting($contextId, $k, $v, $this->guessSettingType($k));
        }
        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.import.success'));
    }

    /**
     * HAR-008: trust-plane credentials (chatwootApiAccessToken,
     * chatwootIdentityValidationSecret, chatwootSupportApiToken —
     * SettingsRegistry::nonGlobalEligibleKeys()) must never silently
     * become shared across journals just because "Use Global Defaults"
     * is enabled. Journal A's Chatwoot/Support API credential must
     * never end up authorizing Journal B by global-fallback accident.
     */
    public function saveGlobalProfile($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();
        $nonGlobalEligible = SettingsRegistry::nonGlobalEligibleKeys();
        foreach (self::exportKeys() as $k) {
            if ($k === 'enableGlobalDefaults' || in_array($k, $nonGlobalEligible, true)) continue;
            $this->updateSetting(0, $k, $this->getSetting($contextId, $k), $this->guessSettingType($k));
        }
        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.globalProfile.saved'));
    }

    public function applyGlobalProfile($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();
        $nonGlobalEligible = SettingsRegistry::nonGlobalEligibleKeys();
        foreach (self::exportKeys() as $k) {
            if ($k === 'enableGlobalDefaults' || in_array($k, $nonGlobalEligible, true)) continue;
            $v = $this->getSetting(0, $k);
            if ($v !== null) $this->updateSetting($contextId, $k, $v, $this->guessSettingType($k));
        }
        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.globalProfile.applied'));
    }

    public function syncEmailTemplates($request) {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();

        $baseUrl = $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $token = (string) $this->getEffectiveSetting($contextId, 'chatwootApiAccessToken', '');
        if ($baseUrl === '' || $token === '') return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.missingSettings'));

        // HAR-012/HAR-013: this used to also opportunistically drain the
        // shared legacy queue before running its own sync — but that
        // queue mixes job types (canned_response_sync from this action,
        // and conversation_event from Send Test Message), so a Sync
        // Email Templates click could as a side effect redeliver an
        // unrelated queued Test Message. ProcessLegacyRetryQueueScheduledTask
        // is now the sole drain path; this action only ever enqueues its
        // own jobs, never drains someone else's.
        $api = new ChatwootApiService($baseUrl, $token);
        $templates = Repo::emailTemplate()->getCollector($contextId)->getMany();
        $count = 0; $queued = 0; $denied = 0;

        foreach ($templates as $template) {
            $shortCode = (string) $template->getData('key');
            if (!$this->isCannedResponseSafe($shortCode)) { $denied++; continue; }
            $content = (string) $template->getLocalizedData('body');
            if ($content === '') continue;
            $result = $api->createCannedResponse($shortCode, $content);
            if (is_array($result) && isset($result['success']) && $result['success']) { 
                $count++; 
                continue; 
            }
            if ($this->isRetryQueueEnabled($contextId)) {
                $this->enqueueApiJob($contextId, 'canned_response_sync', ['shortCode' => $shortCode, 'content' => $content]);
                $queued++;
            }
        }

        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.syncSuccess', ['count' => $count]) . ' ' . __('plugins.generic.chatwootIntegration.syncQueued', ['count' => $queued]) . ' ' . __('plugins.generic.chatwootIntegration.syncDenied', ['count' => $denied]));
    }

    /**
     * HAR-013: security/verification/password-reset/registration/
     * login-recovery templates must never be promoted to a Chatwoot
     * canned response merely because this button exists — that content
     * is plaintext account-recovery material (reset links, magic-login
     * links, validation links), and a canned response is visible to
     * every support agent with Chatwoot access, not just this journal's
     * editorial staff. Live-verified against this real installation's
     * actual templates: PASSWORD_RESET_CONFIRM, MAGIC_LOGIN_LINK,
     * USER_VALIDATE_CONTEXT, and USER_VALIDATE_SITE would otherwise
     * have been synced as-is. Keyword-matched rather than an exact-key
     * denylist so a similarly-named template added later by this or
     * any other installed plugin is denied by default (fail closed),
     * not only the specific keys discovered this session.
     */
    private const CANNED_RESPONSE_DENY_KEYWORDS = [
        'PASSWORD', 'RESET', 'VALIDATE', 'VERIF', 'LOGIN', 'REGISTER',
        'ACTIVATE', 'TOKEN', 'SECRET', 'OTP', 'PIN', 'MAGIC', 'CREDENTIAL',
    ];

    private function isCannedResponseSafe(string $templateKey): bool {
        $key = strtoupper($templateKey);
        foreach (self::CANNED_RESPONSE_DENY_KEYWORDS as $keyword) {
            if (str_contains($key, $keyword)) return false;
        }
        return true;
    }

    public function handleEditorDecision($hookName, $args) {
        try {
            $decision = $args[0] ?? null;
            if (!$decision) return false;
            
            $submissionId = (int) $decision->getData('submissionId');
            if (!$submissionId) return false;
            
            $submission = Repo::submission()->get($submissionId);
            if (!$submission) return false;
            
            $contextId = (int) $submission->getData('contextId');
            if (!$this->isEventEnabled($contextId, 'eventDecisionRecorded')) return false;

            $decisionCode = (int) $decision->getData('decision');
            $eventKey = $this->mapDecisionEventKey($decisionCode);
            if ($eventKey && !$this->isEventEnabled($contextId, $eventKey)) return false;
            // EVT-020: the ownership-transfer check must key on the SAME
            // specific event type v2's DecisionRecordedEventAdapter::
            // mapDecisionEventType() would actually enqueue this decision
            // code as (eventAccepted/eventRejected/eventRevisionRequested
            // for their respective codes, eventDecisionRecorded only for
            // the fallback/default case) — never the bare
            // 'eventDecisionRecorded' key unconditionally. A real
            // regression this exact bug caused once, live: it blocked
            // v1's delivery for a real "Accept Submission" decision
            // (which maps to eventAccepted, still v1-owned) while v2 also
            // never delivered it (SUBMISSION_ACCEPTED not yet
            // allowlisted) — a real silent notification gap.
            if ($this->isLiveDeliveryOwnedByV2($eventKey ?? 'eventDecisionRecorded')) return false;

            $author = $this->safeGetPrimaryAuthor($submission);
            if (!$author || !$author->getEmail()) return false;

            $attrs = $this->buildSubmissionAttributes($submission, $contextId);
            $attrs['decision_code'] = $decisionCode;
            $attrs['workflow_stage'] = (int) ($decision->getData('stageId') ?? $submission->getData('stageId'));

            $submissionTitle = $this->safeGetLocalizedTitle($submission);
            
            $this->dispatchEvent($contextId, [
                'email' => $author->getEmail(), 'name' => $author->getFullName(), 'identifier' => (string) $author->getId(),
                'message' => __('plugins.generic.chatwootIntegration.note.editorDecision', ['submissionId' => $submission->getId(), 'title' => $submissionTitle, 'decisionCode' => $decisionCode]),
                'attributes' => $attrs,
                'eventAction' => (string) $this->getEffectiveSetting($contextId, 'eventSyncMode', 'note'),
            ]);
        } catch (\Exception $e) {
            // Log safe error message without exposing sensitive data
            error_log('Chatwoot event sync failed (editor decision): ' . SafeExceptionMessage::describe($e));
        }
        return false;
    }

    /**
     * EVT-017: per-event-type live-delivery ownership switch. Base v1 is
     * the live deliverer for every event type by default (`false`); v2
     * overrides this to return `true` for a type only once that type's
     * ownership has been atomically transferred — never leaving a window
     * where both v1 and v2 attempt live delivery, or neither does. Keyed
     * by the same `event*` setting name each handler already checks via
     * `isEventEnabled()`, so the transfer point is explicit and auditable
     * per event family.
     */
    protected function isLiveDeliveryOwnedByV2(string $eventSettingKey): bool {
        return false;
    }

    public function handleSubmissionCreated($hookName, $args) {
        try {
            $submission = $args[0] ?? null;
            if (!$submission) return false;
            $contextId = (int) $submission->getData('contextId');
            if (!$this->isEventEnabled($contextId, 'eventSubmissionCreated')) return false;
            if ($this->isLiveDeliveryOwnedByV2('eventSubmissionCreated')) return false;
            $author = $this->safeGetPrimaryAuthor($submission);
            if (!$author || !$author->getEmail()) return false;

            $submissionTitle = $this->safeGetLocalizedTitle($submission);
            
            $this->dispatchEvent($contextId, [
                'email' => $author->getEmail(), 'name' => $author->getFullName(), 'identifier' => (string) $author->getId(),
                'message' => __('plugins.generic.chatwootIntegration.note.submissionCreated', ['submissionId' => $submission->getId(), 'title' => $submissionTitle]),
                'attributes' => $this->buildSubmissionAttributes($submission, $contextId),
                'eventAction' => (string) $this->getEffectiveSetting($contextId, 'eventSyncMode', 'note'),
            ]);
        } catch (\Exception $e) {
            error_log('Chatwoot event sync failed (submission created): ' . SafeExceptionMessage::describe($e));
        }
        return false;
    }

    public function handleSubmissionStatusUpdated($hookName, $args) {
        try {
            $newStatus = (int) ($args[0] ?? 0); $oldStatus = (int) ($args[1] ?? 0); $submission = $args[2] ?? null;
            if (!$submission || $newStatus === $oldStatus) return false;
            $contextId = (int) $submission->getData('contextId');
            $eventKey = $newStatus === PKPSubmission::STATUS_DECLINED ? 'eventRejected' : ($newStatus === PKPSubmission::STATUS_PUBLISHED ? 'eventAccepted' : null);
            if (!$eventKey || !$this->isEventEnabled($contextId, $eventKey)) return false;
            // EVT-020 (CRITICAL, found by post-merge security review):
            // 'eventAccepted'/'eventRejected' are the SAME ownership
            // setting keys handleEditorDecision() gates on, but this is a
            // completely separate real hook (a status change, not a
            // decision) that produces the same SupportEventType via
            // SubmissionStatusChangedEventAdapter. Transferring
            // eventAccepted/eventRejected to v2 (adding
            // SUBMISSION_ACCEPTED/SUBMISSION_REJECTED to
            // LIVE_DELIVERY_ALLOWLIST) without this guard left this path
            // still unconditionally calling dispatchEvent() — meaning a
            // real submission published/declined via a status change
            // (not only via an editorial decision) would have both v1
            // (here) and v2 (the queue's own allowlist gate) deliver live
            // for the exact same real occurrence. Never shipped past this
            // fix — caught before any real double-post could occur.
            if ($this->isLiveDeliveryOwnedByV2($eventKey)) return false;
            $author = $this->safeGetPrimaryAuthor($submission);
            if (!$author || !$author->getEmail()) return false;

            $attrs = $this->buildSubmissionAttributes($submission, $contextId);
            $attrs['old_status'] = $oldStatus; $attrs['new_status'] = $newStatus;

            $this->dispatchEvent($contextId, [
                'email' => $author->getEmail(), 'name' => $author->getFullName(), 'identifier' => (string) $author->getId(),
                'message' => __('plugins.generic.chatwootIntegration.note.statusChanged', ['submissionId' => $submission->getId(), 'status' => $newStatus]),
                'attributes' => $attrs,
                'eventAction' => (string) $this->getEffectiveSetting($contextId, 'eventSyncMode', 'note'),
            ]);
        } catch (\Exception $e) {
            error_log('Chatwoot event sync failed (status updated): ' . SafeExceptionMessage::describe($e));
        }
        return false;
    }

    public function handlePublicationPublished($hookName, $args) {
        try {
            $publication = $args[0] ?? null; $submission = $args[2] ?? null;
            if (!$publication || !$submission) return false;
            $contextId = (int) $submission->getData('contextId');
            $status = (int) $publication->getData('status');
            $eventKey = $status === PKPSubmission::STATUS_SCHEDULED ? 'eventPublicationScheduled' : ($status === PKPSubmission::STATUS_PUBLISHED ? 'eventPublicationPublished' : null);
            if (!$eventKey || !$this->isEventEnabled($contextId, $eventKey)) return false;
            // EVT-020: defensive guard added proactively (neither key is
            // transferred yet) after the handleSubmissionStatusUpdated()
            // double-delivery finding — whichever of these two types is
            // transferred next must not repeat that mistake here.
            if ($this->isLiveDeliveryOwnedByV2($eventKey)) return false;
            $author = $this->safeGetPrimaryAuthor($submission);
            if (!$author || !$author->getEmail()) return false;

            $attrs = $this->buildSubmissionAttributes($submission, $contextId);
            $attrs['publication_status'] = $status;

            $this->dispatchEvent($contextId, [
                'email' => $author->getEmail(), 'name' => $author->getFullName(), 'identifier' => (string) $author->getId(),
                'message' => __('plugins.generic.chatwootIntegration.note.publicationEvent', ['submissionId' => $submission->getId(), 'status' => $status]),
                'attributes' => $attrs,
                'eventAction' => (string) $this->getEffectiveSetting($contextId, 'eventSyncMode', 'note'),
            ]);
        } catch (\Exception $e) {
            error_log('Chatwoot event sync failed (publication published): ' . SafeExceptionMessage::describe($e));
        }
        return false;
    }

    private function mapDecisionEventKey(int $code): ?string {
        if (in_array($code, [Decision::PENDING_REVISIONS, Decision::RESUBMIT, Decision::RECOMMEND_PENDING_REVISIONS, Decision::RECOMMEND_RESUBMIT], true)) return 'eventRevisionRequested';
        if (in_array($code, [Decision::ACCEPT, Decision::RECOMMEND_ACCEPT], true)) return 'eventAccepted';
        if (in_array($code, [Decision::DECLINE, Decision::INITIAL_DECLINE, Decision::RECOMMEND_DECLINE], true)) return 'eventRejected';
        return null;
    }

    /**
     * HAR-012: all eight real event types are now live-owned by v2
     * (ChatwootIntegrationV2Plugin::isLiveDeliveryOwnedByV2() returns
     * true for every one of them), so this method's only remaining
     * live caller is sendTestMessage() — a deliberate, rare admin
     * action, never a real event occurrence. It used to also
     * opportunistically drain the legacy queue on every call
     * (`processApiQueue($contextId, 4)`), which the audit named as one
     * of exactly two sanctioned drain sites; since real events no
     * longer reach this method at all, that drain no longer belongs
     * here — ProcessLegacyRetryQueueScheduledTask is now the sole
     * reliable drain path for anything this enqueues.
     */
    private function dispatchEvent(int $contextId, array $payload, bool $forceQueue = false): bool {
        if (!$forceQueue && $this->sendChatwootEvent($contextId, $payload)) return true;
        if ($this->isRetryQueueEnabled($contextId)) { $this->enqueueApiJob($contextId, 'conversation_event', $payload); return true; }
        return false;
    }

    private function sendChatwootEvent(int $contextId, array $payload): bool {
        $baseUrl = $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $apiToken = (string) $this->getEffectiveSetting($contextId, 'chatwootApiAccessToken', '');
        $inboxId = (int) $this->getEffectiveSetting($contextId, 'chatwootInboxId', 0);
        if ($baseUrl === '' || $apiToken === '' || empty($payload['email'])) return false;

        $api = new ChatwootApiService($baseUrl, $apiToken);
        $contact = $api->findContactByEmail((string) $payload['email'], (string) ($payload['identifier'] ?? ''));
        $mode = (string) ($payload['eventAction'] ?? 'note');
        if (!$contact && $mode === 'open_update') $contact = $api->createContact((string) $payload['email'], (string) ($payload['name'] ?? ''), (string) ($payload['identifier'] ?? ''));
        if (!$contact || empty($contact['id'])) return false;

        $message = (string) ($payload['message'] ?? '');
        $attrs = $payload['attributes'] ?? [];
        if (is_array($attrs) && !empty($attrs)) $message .= "\n\nContext:\n" . json_encode($attrs);

        $conversations = $api->getContactConversations((int) $contact['id']);
        $conversation = $this->selectConversationForInbox($conversations, $inboxId);
        if ($conversation !== null) return $api->createConversationNote((int) $conversation['id'], $message);
        if ($mode === 'open_update' && $inboxId > 0) return (bool) $api->createConversation((int) $contact['id'], $inboxId, $message);
        return false;
    }

    /**
     * HAR-003: a contact can have conversations in unrelated
     * inboxes/channels (WhatsApp, email, other websites) — the first
     * entry Chatwoot returns is not necessarily one the configured OJS
     * inbox owns. Never post an OJS-originated note/message into a
     * conversation without first proving inbox_id === the configured
     * chatwootInboxId; if $inboxId is unconfigured (<= 0) there is
     * nothing to prove membership against, so no conversation ever
     * matches — fail closed rather than trusting whichever entry the
     * API happened to return first.
     */
    protected function selectConversationForInbox(array $conversations, int $inboxId): ?array {
        if ($inboxId <= 0) return null;
        foreach ($conversations as $conversation) {
            if (is_array($conversation) && !empty($conversation['id']) && (int) ($conversation['inbox_id'] ?? 0) === $inboxId) {
                return $conversation;
            }
        }
        return null;
    }

    private function isRetryQueueEnabled(int $contextId): bool { return $this->toBool($this->getEffectiveSetting($contextId, 'retryQueueEnabled', true)) !== false; }
    private function maxAttempts(int $contextId): int { $n = (int) $this->getEffectiveSetting($contextId, 'maxRetryAttempts', 5); return max(1, min(10, $n)); }

    private function enqueueApiJob(int $contextId, string $type, array $payload): void {
        $q = $this->getApiQueue($contextId);
        $q[] = ['id' => uniqid('cwq_', true), 'type' => $type, 'payload' => $payload, 'attempts' => 0, 'runAfter' => time()];
        if (count($q) > 500) $q = array_slice($q, -500);
        $this->saveApiQueue($contextId, $q);
    }

    /**
     * EVT-018/HAR-012: public entry point for the scheduled retry-queue
     * consumer (ProcessLegacyRetryQueueScheduledTask) — this is now the
     * legacy `apiQueue`'s sole reliable drain path. dispatchEvent() no
     * longer opportunistically drains on every call (removed, HAR-012:
     * all real event occurrences are v2-owned, so dispatchEvent() is
     * only ever reached via the deliberate, rare Send Test Message
     * admin action); the explicit "Sync Email Templates" admin action
     * still drains a small batch itself, which remains acceptable
     * since it is an equally deliberate admin-initiated action, not an
     * incidental side effect of unrelated work.
     */
    public function processQueuedApiJobsForContext(int $contextId, int $limit = 20): void {
        $this->processApiQueue($contextId, $limit);
    }

    private function processApiQueue(int $contextId, int $limit = 5): void {
        if (!$this->isRetryQueueEnabled($contextId)) return;
        $queue = $this->getApiQueue($contextId);
        if (!$queue) return;

        $now = time(); $processed = 0; $remaining = []; $max = $this->maxAttempts($contextId);
        foreach ($queue as $job) {
            if ($processed >= $limit || (int) ($job['runAfter'] ?? 0) > $now) { $remaining[] = $job; continue; }
            $processed++;
            $ok = $this->executeApiJob($contextId, $job);
            if ($ok) continue;
            $attempts = (int) ($job['attempts'] ?? 0) + 1;
            if ($attempts >= $max) {
                // EVT-017: a legacy job that exhausts its retries used to
                // simply vanish with zero trace -- no dead-letter record,
                // no audit entry, nothing. Record a safe give-up entry
                // (never the job's real payload -- no email/name/message/
                // submission content) so an abandoned delivery is at least
                // observable, matching the real dead-letter visibility the
                // v2 durable queue already has.
                //
                // AUD-013: this used to go only to error_log() via
                // ErrorLogSupportApiAuditLogger, the AUD-001 placeholder
                // sink every other call site retired once the real
                // persisted audit table landed -- this legacy dead-letter
                // path was the one caller left behind, so it never showed
                // up in the same queryable audit trail as every other
                // delivery outcome (event queue, REST, MCP). Switched to
                // the real DatabaseSupportApiAuditLogger, and a legacy job
                // never had a correlation ID to begin with (v1's apiQueue
                // predates AUD-013), so one is generated fresh here --
                // the same "no prior ID to reuse" pattern
                // v2AuditEventDelivery() already uses for pre-AUD-013
                // queue rows.
                (new DatabaseSupportApiAuditLogger())->record([
                    'correlationId' => CorrelationId::generate(),
                    'contextId' => $contextId,
                    'endpoint' => 'legacy_queue:' . (string) ($job['type'] ?? ''),
                    'decision' => 'deny',
                    'reason' => sprintf('give_up:job=%s:attempts=%d', (string) ($job['id'] ?? ''), $attempts),
                ]);
                continue;
            }
            $job['attempts'] = $attempts;
            $job['runAfter'] = $now + min(3600, (int) pow(2, $attempts) * 30);
            $remaining[] = $job;
        }
        $this->saveApiQueue($contextId, $remaining);
    }

    private function executeApiJob(int $contextId, array $job): bool {
        $type = (string) ($job['type'] ?? ''); $payload = $job['payload'] ?? [];
        if (!is_array($payload)) return true;
        if ($type === 'conversation_event') return $this->sendChatwootEvent($contextId, $payload);
        if ($type === 'canned_response_sync') {
            $baseUrl = $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', ''));
            $token = (string) $this->getEffectiveSetting($contextId, 'chatwootApiAccessToken', '');
            if ($baseUrl === '' || $token === '') return false;
            $api = new ChatwootApiService($baseUrl, $token);
            $result = $api->createCannedResponse((string) ($payload['shortCode'] ?? ''), (string) ($payload['content'] ?? ''));
            return is_array($result) && isset($result['success']) && $result['success'];
        }
        return true;
    }

    private function getApiQueue(int $contextId): array {
        $raw = $this->getSetting($contextId, self::QUEUE_KEY);
        if (is_array($raw)) return $raw;
        if (!is_string($raw) || trim($raw) === '') return [];
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function saveApiQueue(int $contextId, array $queue): void { $this->updateSetting($contextId, self::QUEUE_KEY, json_encode(array_values($queue)), 'string'); }

    private function buildSubmissionAttributes($submission, int $contextId): array {
        $request = Application::get()->getRequest();
        $context = app()->get('context')->get($contextId);
        $attrs = [
            'journal_id' => $contextId,
            'journal_name' => $context ? $context->getLocalizedName() : '',
            'workflow_stage' => (int) $submission->getData('stageId'),
            'submission_id' => (int) $submission->getId(),
            'submission_status' => (int) $submission->getData('status'),
            'submission_title' => $this->safeGetLocalizedTitle($submission),
            'submission_url' => $request->getRouter()->url($request, $context ? $context->getPath() : null, 'workflow', 'index', [$submission->getId()]),
        ];

        $publication = $submission->getCurrentPublication();
        $doi = $this->safeGetDoi($publication);
        if ($doi !== '') {
            $attrs['submission_doi'] = $doi;
        }

        $attrs = array_merge($attrs, $this->getPriorityFlags($contextId, $submission));
        return $attrs;
    }

    private function getPriorityFlags(int $contextId, $submission): array {
        $overdue = false;
        $assignments = Repo::reviewAssignment()->getCollector()->filterBySubmissionIds([$submission->getId()])->getMany();
        foreach ($assignments as $a) {
            $status = (int) $a->getStatus();
            if (in_array($status, [\PKP\submission\reviewAssignment\ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_REVIEW_OVERDUE, \PKP\submission\reviewAssignment\ReviewAssignment::REVIEW_ASSIGNMENT_STATUS_RESPONSE_OVERDUE], true)) {
                $overdue = true; break;
            }
            $dateDue = $a->getDateDue();
            if ($dateDue && strtotime((string) $dateDue) < time()) { $overdue = true; break; }
        }

        $request = Application::get()->getRequest();
        $context = app()->get('context')->get($contextId);
        // TST-020: same PKPPageRouter-only guard as addChatwootWidget() —
        // this helper is reachable from that same hook.
        $isPageRequest = $request->getRouter() instanceof \PKP\core\PKPPageRouter;
        $paymentPending = $isPageRequest && $context && $context->getData('paymentsEnabled') && $request->getRequestedPage() === 'payment';

        return ['priority_overdue_revision' => $overdue, 'priority_payment_pending' => $paymentPending];
    }

    public function addChatwootWidget($hookName, $args) {
        $templateMgr = $args[0];
        $request = Application::get()->getRequest();
        $context = $this->resolveWidgetContext($request, $templateMgr);
        if (!$context) return false;

        $contextId = (int) $context->getId();
        if (!$this->getEnabled($contextId) && !$this->getEnabled()) return false;
        // EVT-018/HAR-012 (CRITICAL): this hook fires on every
        // TemplateManager::display/fetch site-wide — it must never
        // perform a network/queue side effect during template
        // rendering. Retry-queue processing belongs solely to
        // ProcessLegacyRetryQueueScheduledTask (scheduler-only), the
        // deliberate Send Test Message admin action (dispatchEvent()'s
        // only remaining live caller), and the explicit "Sync Email
        // Templates" admin action — never an arbitrary page render.

        $baseUrl = $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', ''));
        $token = trim((string) $this->getEffectiveSetting($contextId, 'chatwootWebsiteToken', ''));
        $enabled = $this->toBool($this->getEffectiveSetting($contextId, 'enableWidget', false));

        if (!$enabled || $baseUrl === '' || $token === '') return false;

        // TST-020: this hook fires on every TemplateManager::fetch site-wide,
        // including component-routed AJAX calls (any plugin's own settings
        // modal, grid cell renders, etc.) where $request->getRouter() is a
        // PKPComponentRouter, not a PKPPageRouter — getRequestedPage()/
        // getRequestedOp() only exist on the page router. Calling either
        // unconditionally fataled on every such request (confirmed live on
        // ojs-demo.airixmedia.com: this exact plugin's own settings-form
        // AJAX fetch recursively triggers this same hook and crashed
        // rendering its own admin UI). Resolved once, safely, and reused
        // below instead of calling those two request methods directly —
        // outside a real page view there is no requested page to check, so
        // excluded-pages/article-context logic simply does not apply.
        $isPageRequest = $request->getRouter() instanceof \PKP\core\PKPPageRouter;
        $requestedPage = $isPageRequest ? $request->getRequestedPage() : '';
        $requestedOp = $isPageRequest ? $request->getRequestedOp() : '';

        if ($isPageRequest) {
            $excluded = array_filter(array_map('trim', explode(',', (string) $this->getEffectiveSetting($contextId, 'excludedPages', ''))));
            if (in_array($requestedPage, $excluded, true)) return false;

            // HAR-018: skipBackendPages was previously saved but never
            // read anywhere — a real placebo setting. Wired to the
            // same isBackendPage() list an admin would otherwise have
            // to type out by hand into excludedPages above.
            if ($this->toBool($this->getEffectiveSetting($contextId, 'skipBackendPages', false)) && $this->isBackendPage($requestedPage)) {
                return false;
            }
        }
        $user = $request->getUser();
        $isReviewer = false;
        $roleIds = [];
        if ($user) {
            foreach ($user->getRoles($contextId) as $role) {
                $roleIds[] = (int) $role->getId();
                if ((int) $role->getId() === Role::ROLE_ID_REVIEWER) $isReviewer = true;
                if ($this->toBool($this->getSetting($contextId, 'hideForRole_' . $role->getId())) === true) return false;
            }
        } else {
            if ($this->toBool($this->getSetting($contextId, 'hideForGuests')) === true) return false;
        }
        $chatLocale = substr(Locale::getLocale(), 0, 2);
        $attrs = ['journal_id' => $contextId, 'journal_name' => $context->getLocalizedName(), 'requested_page' => $requestedPage, 'requested_op' => $requestedOp];
        $identifier = ''; $userHash = ''; $email = ''; $name = '';
        $privacy = $this->toBool($this->getEffectiveSetting($contextId, 'enablePrivacyMode', false)) === true;

        if ($user) {
            // HAR-006: shared resource-aware masking — see
            // resolveReviewerMasking()'s own docblock. Never masks
            // someone who isn't a reviewer anywhere in the journal;
            // fails closed (stays masked) whenever no specific
            // submission relationship evidence is available.
            $supportContext = new SupportContext($contextId, (string) $context->getPath(), (int) $user->getId(), $roleIds, $requestedPage, $requestedOp, (string) Locale::getLocale());
            $shouldMask = $privacy && $this->resolveReviewerMasking($request, $supportContext, $isReviewer);
            if ($shouldMask) {
                $identifier = 'reviewer_' . hash('sha256', $user->getId() . $contextId);
                $name = 'Reviewer (Masked)';
                $email = 'reviewer_' . substr(md5($user->getEmail()), 0, 8) . '@masked.local';
                $attrs['is_masked'] = true;
            } else {
                $identifier = (string) $user->getId();
                $name = (string) $user->getFullName();
                $email = (string) $user->getEmail();
                $attrs['orcid'] = $user->getOrcid();
                $attrs['affiliation'] = $user->getLocalizedAffiliation();
            }
            $secret = (string) $this->getEffectiveSetting($contextId, 'chatwootIdentityValidationSecret', '');
            if ($secret !== '') $userHash = hash_hmac('sha256', $identifier, $secret);
            $attrs['roles'] = implode(',', $roleIds);
            $attrs['active_submissions'] = Repo::submission()->getCollector()->filterByContextIds([$contextId])->assignedTo([$user->getId()])->getCount();
        }

        if ($requestedPage === 'article' && $requestedOp === 'view') {
            $article = $templateMgr->getTemplateVars('article');
            if ($article && method_exists($article, 'getCurrentPublication')) {
                $attrs['context_type'] = 'article';
                $attrs['article_title'] = $article->getCurrentPublication()->getLocalizedTitle();
                $attrs['article_doi'] = $article->getCurrentPublication()->getDoi();
                $attrs['article_id'] = $article->getId();
                
                $section = Repo::section()->get($article->getSectionId());
                if ($section) $attrs['section_title'] = $section->getLocalizedTitle();
            }
        }

        $customAttributesJson = json_encode($attrs);
        $debug = '';
        if ($this->toBool($this->getEffectiveSetting($contextId, 'enableDebugMode', false))) {
            $debug = "console.log('Chatwoot Debug:', " . json_encode(['identifier' => $identifier, 'hash' => $userHash, 'attrs' => $attrs]) . ");";
        }

        $baseUrlJson = json_encode($baseUrl);
        $tokenJson = json_encode($token);
        $nonce = $this->toBool($this->getEffectiveSetting($contextId, 'cspSafeMode', false)) ? (string) ($templateMgr->getTemplateVars('cspNonce') ?? '') : '';
        $lazy = $this->toBool($this->getEffectiveSetting($contextId, 'lazyLoadWidget', true)) !== false;
        $trigger = (string) $this->getEffectiveSetting($contextId, 'lazyLoadTrigger', 'idle');
        $widgetSettingsJsonRaw = (string) $this->getEffectiveSetting($contextId, 'widgetSettingsJson', '');
        $widgetSettingsFromConfig = [];
        if ($widgetSettingsJsonRaw !== '') {
            $decodedWidgetSettings = json_decode($widgetSettingsJsonRaw, true);
            if (is_array($decodedWidgetSettings)) {
                $widgetSettingsFromConfig = $decodedWidgetSettings;
            }
        }
        $widgetSettingsFromConfigJson = json_encode($widgetSettingsFromConfig);

        $script = "
            <script" . ($nonce !== '' ? " nonce=\"" . htmlspecialchars($nonce) . "\"" : "") . ">
            window.chatwootSettings = Object.assign(
                { locale:" . json_encode($chatLocale) . ", position:'right', type:'standard', launcherTitle:'Chat with us' },
                $widgetSettingsFromConfigJson,
                window.chatwootSettings || {}
            );
            window.addEventListener('chatwoot:ready',function(){
                $debug
                " . ($user ? "window.\$chatwoot.setUser(" . json_encode((string) $identifier) . ",{email:" . json_encode((string) $email) . ",name:" . json_encode((string) $name) . ",identifier_hash:" . json_encode((string) $userHash) . "});" : '') . "
                window.\$chatwoot.setCustomAttributes($customAttributesJson);
            });
            (function(d,t){
                var BASE_URL=$baseUrlJson;
                function boot(){
                    if(window.__chatwootLoaded)return;
                    window.__chatwootLoaded=true;
                    var g=d.createElement(t),s=d.getElementsByTagName(t)[0];
                    g.src=BASE_URL+'/packs/js/sdk.js';g.defer=true;g.async=true;
                    " . ($nonce !== '' ? "g.setAttribute('nonce'," . json_encode($nonce) . ");" : '') . "
                    s.parentNode.insertBefore(g,s);
                    g.onload=function(){window.chatwootSDK.run({websiteToken:$tokenJson,baseUrl:BASE_URL});};
                }
                if(!" . ($lazy ? 'true' : 'false') . "){
                    if (document.readyState === 'complete') { setTimeout(boot, 1500); } 
                    else { window.addEventListener('load', function() { setTimeout(boot, 1500); }); }
                    return;
                }
                if(" . json_encode($trigger) . "==='interaction'){
                    var f=false;var go=function(){if(f)return;f=true;boot();};
                    window.addEventListener('scroll',go,{passive:true,once:true});
                    window.addEventListener('mousemove',go,{once:true});
                    window.addEventListener('keydown',go,{once:true});
                    window.addEventListener('touchstart',go,{once:true});
                }else{
                    var delayBoot = function() {
                        if('requestIdleCallback' in window){requestIdleCallback(function(){boot();},{timeout:3500});}else{setTimeout(boot,2500);} 
                    };
                    if (document.readyState === 'complete') { delayBoot(); }
                    else { window.addEventListener('load', delayBoot); }
                }
            })(document,'script');
            </script>
        ";

        $templateMgr->addHeader('chatwootWidgetFrontend', $script, ['contexts' => ['frontend']]);
        $templateMgr->addHeader('chatwootWidgetBackend', $script, ['contexts' => ['backend']]);
        if (isset($args[2]) && is_string($args[2]) && stripos($args[2], 'chatwootSDK.run') === false) {
            if (stripos($args[2], '</body>') !== false) {
                $args[2] = preg_replace('/<\/body>/i', $script . "\n</body>", $args[2], 1);
            } else {
                $args[2] .= $script;
            }
        }
        return false;
    }

    public function addChatwootWidgetFromFooterHook($hookName, $args) {
        $output = &$args[0];
        $templateMgr = $args[1] ?? null;
        if (!$templateMgr || !is_object($templateMgr)) {
            return false;
        }
        $bridgeArgs = [$templateMgr, null, &$output];
        return $this->addChatwootWidget('TemplateManager::display', $bridgeArgs);
    }

    private function resolveWidgetContext($request, $templateMgr) {
        $context = $request->getContext();
        if ($context) {
            return $context;
        }

        if (method_exists($request, 'getRequestedContextPath')) {
            $requestedContextPath = trim((string) $request->getRequestedContextPath());
            if ($requestedContextPath !== '') {
                $context = Application::getContextDAO()->getByPath($requestedContextPath);
                if ($context) {
                    return $context;
                }
            }
        }

        $requestedPage = trim((string) $request->getRequestedPage());
        if ($requestedPage !== '') {
            $context = Application::getContextDAO()->getByPath($requestedPage);
            if ($context) {
                return $context;
            }
        }

        if (is_object($templateMgr) && method_exists($templateMgr, 'getTemplateVars')) {
            foreach (['requestedContext', 'currentContext', 'context', 'journal'] as $contextVar) {
                $candidate = $templateMgr->getTemplateVars($contextVar);
                if (is_object($candidate) && method_exists($candidate, 'getId')) {
                    return $candidate;
                }
            }
        }

        // HAR-007: fail closed. A component/AJAX/site-admin request
        // with no real per-request journal-context evidence must never
        // invent one — the previous fallback here (a nonexistent
        // Repo facade method, which does not exist and fataled every
        // time this path was reached — silently logged and swallowed
        // by PKP\plugins\Hook::run()'s own plugin-exception handling,
        // never visibly breaking a page, but never rendering a widget
        // either) iterated every enabled journal and returned the
        // first one whose widget was configured, which could leak
        // Journal A's widget/identity into a Journal B or site-level
        // route. Returning null here means no widget renders on an
        // ambiguous request, which is the correct, safe outcome.
        return null;
    }

    private function normalizeBaseUrl(string $baseUrl): string {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') return '';
        if (!preg_match('#^https?://#i', $baseUrl)) $baseUrl = 'https://' . $baseUrl;
        return rtrim($baseUrl, '/');
    }

    private function getEffectiveSetting(int $contextId, string $key, $default = null) {
        $local = $this->getSetting($contextId, $key);
        if (!$this->isBlank($local)) return $local;
        if ($key !== 'enableGlobalDefaults' && $this->toBool($this->getSetting($contextId, 'enableGlobalDefaults'))) {
            $global = $this->getSetting(0, $key);
            if (!$this->isBlank($global)) return $global;
        }
        return $default;
    }

    private function isBlank($value): bool { return $value === null || (is_string($value) && trim($value) === ''); }

    private function toBool($value): ?bool {
        if ($value === null) return null;
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value === 1;
        if (is_string($value)) {
            $n = strtolower(trim($value));
            if (in_array($n, ['1','true','yes','on'], true)) return true;
            if (in_array($n, ['0','false','no','off',''], true)) return false;
        }
        return (bool) $value;
    }

    private function isEventEnabled(int $contextId, string $setting): bool { return $this->toBool($this->getEffectiveSetting($contextId, $setting, true)) !== false; }

    /**
     * Safely extract article context with compatibility checks
     */
    private function safeExtractArticleContext($templateMgr, array &$attrs): void {
        try {
            $article = $templateMgr->getTemplateVars('article');
            if (!$article || !is_object($article)) return;
            
            $attrs['context_type'] = 'article';
            
            // Safely get current publication
            $publication = null;
            if (method_exists($article, 'getCurrentPublication')) {
                $publication = $article->getCurrentPublication();
            } elseif (method_exists($article, 'getPublication')) {
                $publication = $article->getPublication();
            }
            
            if ($publication) {
                // Safely extract title
                $title = $this->safeGetLocalizedTitle($publication);
                if ($title !== '') {
                    $attrs['article_title'] = $title;
                }
                
                // Safely extract DOI
                $doi = $this->safeGetDoi($publication);
                if ($doi !== '') {
                    $attrs['article_doi'] = $doi;
                }
            }
            
            // Safely get article ID
            if (method_exists($article, 'getId')) {
                $attrs['article_id'] = $article->getId();
            }
            
            // Safely get section information
            $sectionId = null;
            if (method_exists($article, 'getSectionId')) {
                $sectionId = $article->getSectionId();
            } elseif (method_exists($article, 'getData')) {
                $sectionId = $article->getData('sectionId');
            }
            
            if ($sectionId) {
                try {
                    $section = Repo::section()->get($sectionId);
                    if ($section && method_exists($section, 'getLocalizedTitle')) {
                        $sectionTitle = $section->getLocalizedTitle();
                        if ($sectionTitle !== '') {
                            $attrs['section_title'] = $sectionTitle;
                        }
                    }
                } catch (\Exception $e) {
                    // Section not found or inaccessible, skip
                }
            }
        } catch (\Exception $e) {
            // Article context extraction failed, continue without it
        }
    }

    /**
     * Safely get localized title with fallback
     */
    private function safeGetLocalizedTitle($object): string {
        if (!is_object($object)) return '';
        
        $title = '';
        
        // Try getLocalizedTitle first
        if (method_exists($object, 'getLocalizedTitle')) {
            $title = $object->getLocalizedTitle();
        } elseif (method_exists($object, 'getTitle')) {
            // Fallback to getTitle with locale
            if (method_exists($object, 'getLocale')) {
                $locale = $object->getLocale();
                $titles = $object->getTitle();
                if (is_array($titles) && isset($titles[$locale])) {
                    $title = $titles[$locale];
                }
            }
        }
        
        return is_string($title) ? trim($title) : '';
    }

    /**
     * Safely get DOI with fallback
     */
    private function safeGetDoi($object): string {
        if (!is_object($object)) return '';
        
        $doi = '';
        
        // Try getDoi first
        if (method_exists($object, 'getDoi')) {
            $doi = $object->getDoi();
        } elseif (method_exists($object, 'getData')) {
            // Fallback to getData
            $doi = $object->getData('doi');
        }
        
        return is_string($doi) ? trim($doi) : '';
    }

    /**
     * Safely get user ORCID
     */
    private function safeGetUserOrcid($user): string {
        if (!is_object($user)) return '';
        
        $orcid = '';
        
        if (method_exists($user, 'getOrcid')) {
            $orcid = $user->getOrcid();
        } elseif (method_exists($user, 'getData')) {
            $orcid = $user->getData('orcid');
        }
        
        return is_string($orcid) ? trim($orcid) : '';
    }

    /**
     * Safely get user affiliation
     */
    private function safeGetUserAffiliation($user): string {
        if (!is_object($user)) return '';
        
        $affiliation = '';
        
        if (method_exists($user, 'getLocalizedAffiliation')) {
            $affiliation = $user->getLocalizedAffiliation();
        } elseif (method_exists($user, 'getAffiliation')) {
            // Fallback to getAffiliation with locale
            if (method_exists($user, 'getLocale')) {
                $locale = $user->getLocale();
                $affiliations = $user->getAffiliation();
                if (is_array($affiliations) && isset($affiliations[$locale])) {
                    $affiliation = $affiliations[$locale];
                }
            }
        } elseif (method_exists($user, 'getData')) {
            $affiliation = $user->getData('affiliation');
        }
        
        return is_string($affiliation) ? trim($affiliation) : '';
    }

    /**
     * HAR-018: takes the already-resolved requested-page string rather
     * than $request itself — addChatwootWidget() only knows a real
     * requested page on a real PKPPageRouter request (see TST-020's
     * own guard just above its call site); calling
     * $request->getRequestedPage() directly here would reintroduce
     * that exact crash outside a page-routed request.
     */
    private function isBackendPage(string $requestedPage): bool {
        $backendPages = ['management', 'admin', 'workflow', 'reviewer', 'submission', 'authorDashboard'];
        return in_array($requestedPage, $backendPages, true);
    }

    /**
     * HAR-006: the single shared masking decision — used by both the
     * widget injection path (addChatwootWidget()) and the v2 bind
     * handshake (ChatwootIntegrationV2Plugin::bindSupportSessionRequest())
     * — so the two can never disagree about whether a given user's
     * identity is masked for a given request. Before this, bind
     * computed its expected identifier from $hasJournalWideReviewerRole
     * alone (any Reviewer role anywhere in the journal), a second,
     * independently-maintained copy of the widget's old conservative
     * logic; the widget had already moved to resource-aware masking
     * (POL-011/CWO-016), so a multi-role user (e.g. an Author on
     * Submission A who also Reviews Submission B) could see an
     * unmasked widget while viewing A but have bind compute a masked
     * expected identifier for that same real request — a real
     * identity-projection mismatch, not just a style inconsistency.
     *
     * Never masks someone who isn't a reviewer anywhere in the
     * journal; relaxes masking only when CurrentSubmissionResolver
     * resolves a specific submission from the real request AND
     * SubmissionRelationshipResolver finds real OJS evidence of a
     * non-reviewer relationship to that exact submission — fails
     * closed (stays masked) whenever either is unavailable.
     */
    protected function resolveReviewerMasking($request, SupportContext $supportContext, bool $hasJournalWideReviewerRole): bool {
        if (!$hasJournalWideReviewerRole) {
            return false;
        }
        $currentSubmission = (new CurrentSubmissionResolver())->resolve($request);
        $maskingPolicy = new ReviewerMaskingPolicy(new SubmissionRelationshipResolver(new OjsSubmissionRelationshipEvidenceProvider()));
        return $maskingPolicy->shouldMask($supportContext, $hasJournalWideReviewerRole, $currentSubmission);
    }

    /**
     * Safely get primary author with fallback
     *
     * EVT-021 (real, confirmed live on dell): when called from
     * handleSubmissionCreated() during the real Submission::add hook,
     * $submission->getCurrentPublication() always returns null. Real
     * pkp-lib's Repository::add() (classes/submission/Repository.php)
     * fires that hook with a $submission object re-fetched right after
     * insert but BEFORE currentPublicationId is ever set on it --
     * edit() builds and saves a separate internal object, never
     * reassigning the one the hook receives. This means
     * handleSubmissionCreated() -> dispatchEvent() never even runs for
     * a real submission created via QuickSubmit or the real REST API
     * submission-creation endpoint (both call this same
     * Repo::submission()->add()) -- no delivery, no legacy apiQueue
     * enqueue, no error, no log line. v2's own
     * SubmissionCreatedEventAdapter deliberately avoids this by never
     * resolving the author at enqueue time, only at scheduled delivery
     * time once the submission has been reloaded from the database.
     * See tests/v2/evt-021-v1-submission-created-hook-timing.php.
     *
     * EVT-022 (live Dell finding, 2026-09-02): independent of the timing
     * defect above, the `getData('authors')` fallback below used to gate
     * on `is_array($authors)`, which is always false on real OJS 3.5 —
     * it returns an `Illuminate\Support\LazyCollection`, never a plain
     * array (confirmed live). Fixed via `is_iterable()`. This means the
     * still-v1-owned event types (decision/status/publication — see
     * EVT-016B) were also silently failing to resolve a real author on
     * any publication without an explicit `primaryContactId` set, not
     * only on the `currentPublicationId` timing case above.
     */
    private function safeGetPrimaryAuthor($submission) {
        if (!is_object($submission)) return null;
        
        $publication = null;
        if (method_exists($submission, 'getCurrentPublication')) {
            $publication = $submission->getCurrentPublication();
        } elseif (method_exists($submission, 'getPublication')) {
            $publication = $submission->getPublication();
        }
        
        if (!$publication) return null;
        
        $author = null;
        if (method_exists($publication, 'getPrimaryAuthor')) {
            $author = $publication->getPrimaryAuthor();
            if (is_object($author) && method_exists($author, 'getEmail')) {
                return $author;
            }
        }
        if (method_exists($publication, 'getData')) {
            $authors = $publication->getData('authors');
            if (is_iterable($authors)) {
                foreach ($authors as $candidate) {
                    if (is_object($candidate) && method_exists($candidate, 'getEmail')) {
                        return $candidate;
                    }
                    break;
                }
            }
        }
        return null;
    }

    /** UX-024: delegates to the canonical SettingsRegistry — tests/v2/settings-registry.php proves this matches every key EXPORT_KEYS declares. */
    private function guessSettingType(string $key): string {
        return SettingsRegistry::type($key);
    }

    /**
     * SETTINGS-SMALL-002: an import payload is untrusted input. A
     * malformed value must never be silently accepted as if it were
     * the real, intentional setting — this validates the two keys with
     * real failure modes (a boolean that gates customer-visible
     * message delivery, and a JSON blob parsed elsewhere) before
     * import ever calls updateSetting() for them. Returns true if $v
     * is safe to import as-is for $key, false if the key must be
     * skipped (leaving the existing stored value untouched).
     */
    private function isImportValueSafe(string $key, $v): bool {
        if ($key === 'eventDeliveryCustomerMessageConsent') {
            // Must be a real JSON boolean, never a truthy string/number
            // coerced by a naive (bool) cast — that would let a
            // malformed or hand-edited import silently enable
            // customer-visible message delivery.
            return is_bool($v);
        }
        if ($key === 'eventDeliveryPerEventOverridesJson') {
            if (!is_string($v) || $v === '') return true; // empty is a safe, valid "no overrides" value
            json_decode($v);
            return json_last_error() === JSON_ERROR_NONE;
        }
        return true;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\\APP\\plugins\\generic\\chatwootIntegration\\ChatwootIntegrationBasePlugin', '\\ChatwootIntegrationBasePlugin');
}
