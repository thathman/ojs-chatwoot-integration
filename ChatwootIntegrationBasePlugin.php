<?php

namespace APP\plugins\generic\chatwootIntegration;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\chatwootIntegration\classes\v2\Audit\ErrorLogSupportApiAuditLogger;
use APP\plugins\generic\chatwootIntegration\classes\v2\Context\SupportContext;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\CurrentSubmissionResolver;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\OjsSubmissionRelationshipEvidenceProvider;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\ReviewerMaskingPolicy;
use APP\plugins\generic\chatwootIntegration\classes\v2\Relationship\SubmissionRelationshipResolver;
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
    // SETTINGS-SMALL-002: this is the canonical key list for import,
    // Save Global Profile, and Apply Global Profile (importSettings()/
    // saveGlobalProfile()/applyGlobalProfile() below are inherited by
    // v2 unchanged). It must stay in sync with the v2 plugin's own
    // LEGACY_EXPORT_KEYS (which drives the export side, filtered
    // through ExportPolicy) so an export→import round-trip never
    // silently drops a real, non-secret setting. mcpServiceToken must
    // NEVER appear here — tests/v2/settings-form-mcp-secret-masking.php
    // asserts this (ADR-021: structurally impossible to export or
    // import via the settings backup path).
    private const EXPORT_KEYS = [
        'chatwootBaseUrl','chatwootWebsiteToken','chatwootIdentityValidationSecret','chatwootApiAccessToken','chatwootInboxId',
        'chatwootCaptainAssistantId','chatwootSupportApiToken',
        'enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests',
        'hideForRole_1','hideForRole_16','hideForRole_17','hideForRole_4097','hideForRole_65536','hideForRole_4096','hideForRole_1048576',
        'enableGlobalDefaults','retryQueueEnabled','maxRetryAttempts','eventSyncMode','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected',
        'eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','lazyLoadTrigger','excludedPages','cspSafeMode','skipBackendPages',
        'widgetSettingsJson','eventDeliveryGlobalMode','eventDeliveryCustomerMessageConsent','eventDeliveryPerEventOverridesJson',
    ];

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
        foreach (self::EXPORT_KEYS as $k) $out[$k] = $this->getSetting($contextId, $k);
        return new JSONMessage(true, ['contextId' => $contextId, 'exportedAt' => date('c'), 'settings' => $out]);
    }

    public function importSettings($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $payload = json_decode((string) $request->getUserVar('importPayload'), true);
        if (!is_array($payload) || !isset($payload['settings']) || !is_array($payload['settings'])) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.import.invalidJson'));
        $contextId = (int) $context->getId();
        foreach ($payload['settings'] as $k => $v) {
            if (!in_array($k, self::EXPORT_KEYS, true)) continue;
            if (!$this->isImportValueSafe((string) $k, $v)) continue;
            $this->updateSetting($contextId, $k, $v, $this->guessSettingType($k));
        }
        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.import.success'));
    }

    public function saveGlobalProfile($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();
        foreach (self::EXPORT_KEYS as $k) { if ($k !== 'enableGlobalDefaults') $this->updateSetting(0, $k, $this->getSetting($contextId, $k), $this->guessSettingType($k)); }
        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.globalProfile.saved'));
    }

    public function applyGlobalProfile($request): JSONMessage {
        $context = $request->getContext();
        if (!$context) return new JSONMessage(false, __('plugins.generic.chatwootIntegration.error.noContext'));
        $contextId = (int) $context->getId();
        foreach (self::EXPORT_KEYS as $k) {
            if ($k === 'enableGlobalDefaults') continue;
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

        $this->processApiQueue($contextId, 8);
        $api = new ChatwootApiService($baseUrl, $token);
        $templates = Repo::emailTemplate()->getCollector($contextId)->getMany();
        $count = 0; $queued = 0;

        foreach ($templates as $template) {
            $shortCode = (string) $template->getData('key');
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

        return new JSONMessage(true, __('plugins.generic.chatwootIntegration.syncSuccess', ['count' => $count]) . ' ' . __('plugins.generic.chatwootIntegration.syncQueued', ['count' => $queued]));
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
            error_log('Chatwoot event sync failed (editor decision): ' . $e->getMessage());
        }
        return false;
    }

    public function handleSubmissionCreated($hookName, $args) {
        try {
            $submission = $args[0] ?? null;
            if (!$submission) return false;
            $contextId = (int) $submission->getData('contextId');
            if (!$this->isEventEnabled($contextId, 'eventSubmissionCreated')) return false;
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
            error_log('Chatwoot event sync failed (submission created): ' . $e->getMessage());
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
            error_log('Chatwoot event sync failed (status updated): ' . $e->getMessage());
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
            error_log('Chatwoot event sync failed (publication published): ' . $e->getMessage());
        }
        return false;
    }

    private function mapDecisionEventKey(int $code): ?string {
        if (in_array($code, [Decision::PENDING_REVISIONS, Decision::RESUBMIT, Decision::RECOMMEND_PENDING_REVISIONS, Decision::RECOMMEND_RESUBMIT], true)) return 'eventRevisionRequested';
        if (in_array($code, [Decision::ACCEPT, Decision::RECOMMEND_ACCEPT], true)) return 'eventAccepted';
        if (in_array($code, [Decision::DECLINE, Decision::INITIAL_DECLINE, Decision::RECOMMEND_DECLINE], true)) return 'eventRejected';
        return null;
    }

    private function dispatchEvent(int $contextId, array $payload, bool $forceQueue = false): bool {
        $this->processApiQueue($contextId, 4);
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
        $contact = $api->findContactByEmail((string) $payload['email']);
        $mode = (string) ($payload['eventAction'] ?? 'note');
        if (!$contact && $mode === 'open_update') $contact = $api->createContact((string) $payload['email'], (string) ($payload['name'] ?? ''), (string) ($payload['identifier'] ?? ''));
        if (!$contact || empty($contact['id'])) return false;

        $message = (string) ($payload['message'] ?? '');
        $attrs = $payload['attributes'] ?? [];
        if (is_array($attrs) && !empty($attrs)) $message .= "\n\nContext:\n" . json_encode($attrs);

        $conversations = $api->getContactConversations((int) $contact['id']);
        if (!empty($conversations) && !empty($conversations[0]['id'])) return $api->createConversationNote((int) $conversations[0]['id'], $message);
        if ($mode === 'open_update' && $inboxId > 0) return (bool) $api->createConversation((int) $contact['id'], $inboxId, $message);
        return false;
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
     * EVT-018: public entry point for the scheduled retry-queue consumer
     * (ProcessLegacyRetryQueueScheduledTask) — the legacy `apiQueue`'s
     * only remaining opportunistic drain sites are real event occurrences
     * (dispatchEvent()) and the explicit "Sync Email Templates" admin
     * action; this scheduled task is now the reliable, bounded path so
     * retry delivery does not stall for low-traffic journals between
     * those events.
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
                (new ErrorLogSupportApiAuditLogger())->record([
                    'component' => 'legacy_api_queue',
                    'decision' => 'give_up',
                    'contextId' => $contextId,
                    'jobId' => (string) ($job['id'] ?? ''),
                    'jobType' => (string) ($job['type'] ?? ''),
                    'attempts' => $attempts,
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
        // EVT-018 (CRITICAL): this hook fires on every TemplateManager::
        // display/fetch site-wide — it must never perform a network/queue
        // side effect during template rendering. Retry-queue processing
        // now belongs solely to ProcessLegacyRetryQueueScheduledTask
        // (scheduler-only), dispatchEvent()'s own opportunistic drain on a
        // real event occurrence, and the explicit "Sync Email Templates"
        // admin action — never an arbitrary page render.

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
            // POL-011/CWO-016: resource-aware masking. $isReviewer alone (any
            // Reviewer role anywhere in the journal) is the original,
            // conservative fail-closed signal; ReviewerMaskingPolicy only
            // relaxes it when the current page resolves to a specific
            // submission AND real OJS evidence proves a non-reviewer
            // relationship (author/editorial/manager/site_admin) to THAT
            // submission specifically — e.g. an author on Submission A who
            // also reviews Submission B is no longer masked while viewing
            // Submission A.
            $shouldMask = $privacy && $isReviewer;
            if ($shouldMask) {
                $supportContext = new SupportContext($contextId, (string) $context->getPath(), (int) $user->getId(), $roleIds, $requestedPage, $requestedOp, (string) Locale::getLocale());
                $currentSubmission = (new CurrentSubmissionResolver())->resolve($request);
                $maskingPolicy = new ReviewerMaskingPolicy(new SubmissionRelationshipResolver(new OjsSubmissionRelationshipEvidenceProvider()));
                $shouldMask = $maskingPolicy->shouldMask($supportContext, $isReviewer, $currentSubmission);
            }
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
                $context = Repo::context()->getByPath($requestedContextPath);
                if ($context) {
                    return $context;
                }
            }
        }

        $requestedPage = trim((string) $request->getRequestedPage());
        if ($requestedPage !== '') {
            $context = Repo::context()->getByPath($requestedPage);
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

        return $this->fallbackWidgetContextFromSettings();
    }

    private function fallbackWidgetContextFromSettings() {
        if (!method_exists(Repo::context(), 'getCollector')) {
            return null;
        }

        $contexts = Repo::context()->getCollector()->getMany();
        foreach ($contexts as $candidate) {
            $contextId = (int) $candidate->getId();
            if (
                ($this->getEnabled($contextId) || $this->getEnabled())
                && $this->toBool($this->getEffectiveSetting($contextId, 'enableWidget', false))
                && $this->normalizeBaseUrl((string) $this->getEffectiveSetting($contextId, 'chatwootBaseUrl', '')) !== ''
                && trim((string) $this->getEffectiveSetting($contextId, 'chatwootWebsiteToken', '')) !== ''
            ) {
                return $candidate;
            }
        }

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
     * Check if current page is a backend page
     */
    private function isBackendPage($request): bool {
        $backendPages = ['management', 'admin', 'workflow', 'reviewer', 'submission', 'authorDashboard'];
        $requestedPage = $request->getRequestedPage();
        return in_array($requestedPage, $backendPages, true);
    }

    /**
     * Safely get primary author with fallback
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
            if (is_array($authors) && !empty($authors)) {
                $candidate = reset($authors);
                if (is_object($candidate) && method_exists($candidate, 'getEmail')) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private function guessSettingType(string $key): string {
        $bool = ['enableWidget','enableDebugMode','enablePrivacyMode','hideForGuests','hideForRole_1','hideForRole_16','hideForRole_17','hideForRole_4097','hideForRole_65536','hideForRole_4096','hideForRole_1048576','enableGlobalDefaults','retryQueueEnabled','eventSubmissionCreated','eventRevisionRequested','eventAccepted','eventRejected','eventPublicationScheduled','eventPublicationPublished','eventDecisionRecorded','lazyLoadWidget','cspSafeMode','skipBackendPages','eventDeliveryCustomerMessageConsent'];
        if (in_array($key, $bool, true)) return 'bool';
        if (in_array($key, ['maxRetryAttempts','chatwootInboxId','chatwootCaptainAssistantId'], true)) return 'int';
        return 'string';
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
