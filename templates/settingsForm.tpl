{**
 * plugins/generic/chatwootIntegration/templates/settingsForm.tpl
 *
 * Chatwoot Integration plugin settings.
 *
 * ADM-008/ADM-009 (Settings Console Phase B, first slice): a real,
 * keyboard-accessible tabbed layout replacing the old single long
 * scroll. Tab membership for every real setting field is driven by
 * `SettingsRegistry`'s own `tab` classification (`classes/v2/Settings/SettingsRegistry.php`)
 * — this template's tab groupings must never drift from it;
 * `tests/v2/settings-form-tabs.php` is the automated drift guard.
 * Only tabs with real settings/content are rendered — no empty
 * placeholder tabs (HAR-018).
 *}
<style>
	.pkpc-chatwootIntegrationSettings { }
	.pkpc-chatwootIntegrationSettings [role="tablist"] {
		display: flex;
		flex-wrap: wrap;
		border-bottom: 1px solid #d1d5db;
		margin-bottom: 1em;
	}
	.pkpc-chatwootIntegrationSettings [role="tab"] {
		background: none;
		border: none;
		border-bottom: 3px solid transparent;
		padding: 0.6em 1em;
		font: inherit;
		cursor: pointer;
		color: #4b5563;
	}
	.pkpc-chatwootIntegrationSettings [role="tab"]:hover {
		color: #111827;
	}
	.pkpc-chatwootIntegrationSettings [role="tab"][aria-selected="true"] {
		color: #111827;
		border-bottom-color: #2563eb;
		font-weight: 600;
	}
	.pkpc-chatwootIntegrationSettings [role="tab"]:focus-visible {
		outline: 2px solid #2563eb;
		outline-offset: 2px;
	}
	.pkpc-chatwootIntegrationSettings [role="tabpanel"][hidden] {
		display: none;
	}
	.pkpc-chatwootIntegrationSettings .cwSectionDescription {
		color: #4b5563;
		margin-bottom: 0.75em;
	}
	.pkpc-chatwootIntegrationSettings .cwActionStatus {
		margin-top: 0.5em;
		padding: 0.5em 0.75em;
		border-radius: 4px;
		font-size: 0.9em;
		white-space: pre-line;
	}
	.pkpc-chatwootIntegrationSettings .cwActionStatus[hidden] {
		display: none;
	}
	.pkpc-chatwootIntegrationSettings .cwActionStatus.cwStatusOk {
		background: #ecfdf5;
		color: #065f46;
	}
	.pkpc-chatwootIntegrationSettings .cwActionStatus.cwStatusError {
		background: #fef2f2;
		color: #991b1b;
	}
	.pkpc-chatwootIntegrationSettings .cwWidgetAppearanceLayout {
		display: flex;
		gap: 2em;
		flex-wrap: wrap;
		align-items: flex-start;
	}
	.pkpc-chatwootIntegrationSettings .cwWidgetAppearanceControls {
		flex: 1 1 320px;
		min-width: 280px;
	}
	.pkpc-chatwootIntegrationSettings .cwWidgetPreviewWrap {
		flex: 0 0 260px;
	}
	.pkpc-chatwootIntegrationSettings #cwWidgetPreviewStage {
		position: relative;
		height: 220px;
		border: 1px dashed #cbd5e1;
		border-radius: 8px;
		background: #f8fafc;
		overflow: hidden;
	}
	.pkpc-chatwootIntegrationSettings #cwWidgetPreviewBubble {
		position: absolute;
		bottom: 16px;
		display: inline-flex;
		align-items: center;
		gap: 0.5em;
		padding: 0.6em 0.9em;
		border-radius: 999px;
		background: #1f2937;
		color: #fff;
		font-size: 0.85em;
		box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
		right: 16px;
	}
	.pkpc-chatwootIntegrationSettings #cwWidgetPreviewBubble.cwPreviewLeft {
		right: auto;
		left: 16px;
	}
	.pkpc-chatwootIntegrationSettings #cwWidgetPreviewBubble.cwPreviewDark {
		background: #111827;
		color: #f3f4f6;
	}
	.pkpc-chatwootIntegrationSettings #cwWidgetPreviewBubble.cwPreviewLight {
		background: #ffffff;
		color: #1f2937;
		border: 1px solid #e5e7eb;
	}
	.pkpc-chatwootIntegrationSettings .cwSecurityInvariant {
		background: #f0fdf4;
		border: 1px solid #bbf7d0;
		border-radius: 4px;
		padding: 0.75rem 1rem;
		margin-bottom: 1rem;
	}
	.pkpc-chatwootIntegrationSettings .cwSecurityInvariant strong {
		color: #166534;
	}
	.pkpc-chatwootIntegrationSettings .cwSecurityInvariant p {
		margin: 0.25rem 0 0;
	}
	.pkpc-chatwootIntegrationSettings .cwEventMatrix {
		width: 100%;
		border-collapse: collapse;
		margin: 0.5rem 0 1rem;
	}
	.pkpc-chatwootIntegrationSettings .cwEventMatrix th,
	.pkpc-chatwootIntegrationSettings .cwEventMatrix td {
		border: 1px solid #e5e7eb;
		padding: 0.5rem 0.75rem;
		text-align: left;
		vertical-align: top;
	}
	.pkpc-chatwootIntegrationSettings .cwEventMatrix th {
		background: #f9fafb;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewBanner {
		border-radius: 4px;
		padding: 0.6rem 0.9rem;
		margin: 0.5rem 0;
		font-weight: 600;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewBannerAttention {
		background: #fffbeb;
		border: 1px solid #fde68a;
		color: #92400e;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewBannerOk {
		background: #f0fdf4;
		border: 1px solid #bbf7d0;
		color: #166534;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewGrid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
		gap: 0.75rem;
		margin: 0.75rem 0 1.25rem;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewCard {
		border: 1px solid #e5e7eb;
		border-left-width: 4px;
		border-radius: 4px;
		padding: 0.6rem 0.75rem;
		background: #fff;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewCardLabel {
		font-weight: 600;
		font-size: 0.9em;
	}
	.pkpc-chatwootIntegrationSettings .cwOverviewCardState {
		font-size: 0.85em;
		margin-top: 0.15rem;
	}
	.pkpc-chatwootIntegrationSettings .cwState-healthy { border-left-color: #16a34a; }
	.pkpc-chatwootIntegrationSettings .cwState-configured { border-left-color: #2563eb; }
	.pkpc-chatwootIntegrationSettings .cwState-optional_off { border-left-color: #9ca3af; }
	.pkpc-chatwootIntegrationSettings .cwState-not_configured { border-left-color: #9ca3af; }
	.pkpc-chatwootIntegrationSettings .cwState-never_checked { border-left-color: #d97706; }
	.pkpc-chatwootIntegrationSettings .cwState-degraded { border-left-color: #d97706; }
	.pkpc-chatwootIntegrationSettings .cwState-failed { border-left-color: #dc2626; }
	.pkpc-chatwootIntegrationSettings .cwState-action_required { border-left-color: #dc2626; }
	.pkpc-chatwootIntegrationSettings .cwCredentialCard {
		max-width: 280px;
		margin-bottom: 0.5rem;
	}
	.pkpc-chatwootIntegrationSettings .cwMcpToolList {
		max-height: 260px;
		overflow-y: auto;
		border: 1px solid #e5e7eb;
		border-radius: 4px;
		padding: 0.5rem 1rem;
		font-size: 0.9em;
	}
	.pkpc-chatwootIntegrationSettings .cwCustomerVisibleWarning {
		color: #92400e;
		background: #fffbeb;
		border: 1px solid #fde68a;
		border-radius: 4px;
		padding: 0.35rem 0.5rem;
		margin: 0.35rem 0 0;
		font-size: 0.85em;
	}
</style>
<script>
	$(function() {ldelim}
		$('#chatwootIntegrationSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		// ADM-008: real WAI-ARIA tabs — arrow-key navigation between
		// tabs, Home/End to jump to first/last, no external library.
		var $tabs = $('.pkpc-chatwootIntegrationSettings [role="tab"]');
		var $panels = $('.pkpc-chatwootIntegrationSettings [role="tabpanel"]');

		function cwSelectTab($tab) {ldelim}
			$tabs.attr({ldelim}'aria-selected': 'false', tabindex: '-1'{rdelim});
			$panels.attr('hidden', 'hidden');
			$tab.attr({ldelim}'aria-selected': 'true', tabindex: '0'{rdelim}).trigger('focus');
			$('#' + $tab.attr('aria-controls')).removeAttr('hidden');
		{rdelim}

		$tabs.on('click', function() {ldelim}
			cwSelectTab($(this));
		{rdelim});

		$tabs.on('keydown', function(e) {ldelim}
			var idx = $tabs.index(this);
			var next = null;
			if (e.key === 'ArrowRight') next = $tabs.eq((idx + 1) % $tabs.length);
			else if (e.key === 'ArrowLeft') next = $tabs.eq((idx - 1 + $tabs.length) % $tabs.length);
			else if (e.key === 'Home') next = $tabs.first();
			else if (e.key === 'End') next = $tabs.last();
			if (next) {ldelim}
				e.preventDefault();
				cwSelectTab(next);
			{rdelim}
		{rdelim});

		function cwPost(url, data) {ldelim}
			return $.ajax({ldelim}
				url: url,
				type: 'POST',
				data: $.extend({ldelim}csrfToken: $('input[name="csrfToken"]').val(){rdelim}, data || {ldelim}{rdelim}),
			{rdelim});
		{rdelim}

		// ADM-008: real inline status, never a blocking alert() —
		// each action button has its own adjacent status element,
		// identified by "<button id>Status".
		function cwShowStatus($btn, message, isError) {ldelim}
			var $status = $('#' + $btn.attr('id') + 'Status');
			if (!$status.length) return;
			$status
				.text(message)
				.removeClass('cwStatusOk cwStatusError')
				.addClass(isError ? 'cwStatusError' : 'cwStatusOk')
				.removeAttr('hidden');
		{rdelim}

		function cwWireAction(btnId, buildRequest, formatSuccess) {ldelim}
			$('#' + btnId).on('click', function(e) {ldelim}
				e.preventDefault();
				var $btn = $(this);
				buildRequest().done(function(resp) {ldelim}
					cwShowStatus($btn, formatSuccess(resp), false);
				{rdelim}).fail(function(jqXHR) {ldelim}
					var message = (jqXHR.responseJSON && (jqXHR.responseJSON.content || jqXHR.responseJSON.errorMessage)) || 'Request failed.';
					cwShowStatus($btn, message, true);
				{rdelim});
			{rdelim});
		{rdelim}

		// Owner browser review 2026-09-04: Health Check/Captain Sync/Retry
		// Dead Letters must never show raw JSON in normal UI — they
		// already return clean structured data (booleans/counts, no raw
		// exceptions), this only translates that into human sentences.
		var cwYesNoLabels = {ldelim}
			reachable: {$healthLabelReachable|json_encode_html_attribute},
			notReachable: {$healthLabelNotReachable|json_encode_html_attribute},
			notConfigured: {$healthLabelNotConfigured|json_encode_html_attribute},
			verified: {$healthLabelVerified|json_encode_html_attribute},
			invalid: {$healthLabelInvalid|json_encode_html_attribute},
			notChecked: {$healthLabelNotChecked|json_encode_html_attribute},
			complete: {$healthLabelComplete|json_encode_html_attribute},
			incomplete: {$healthLabelIncomplete|json_encode_html_attribute},
			justNow: {$healthLabelJustNow|json_encode_html_attribute}
		{rdelim};
		function cwFormatHealthCheck(content) {ldelim}
			var lines = [];
			var configured = content.configured || {ldelim}{rdelim};
			var allConfigured = configured.baseUrl && configured.websiteToken && configured.apiAccessToken && configured.identitySecret;
			lines.push({$healthLineService|json_encode_html_attribute} + (configured.baseUrl ? (content.sdkReachable ? cwYesNoLabels.reachable : cwYesNoLabels.notReachable) : cwYesNoLabels.notConfigured));
			lines.push({$healthLineApi|json_encode_html_attribute} + (content.apiTokenValid === null ? cwYesNoLabels.notChecked : (content.apiTokenValid ? cwYesNoLabels.verified : cwYesNoLabels.invalid)));
			lines.push({$healthLineIdentity|json_encode_html_attribute} + (content.identityHmacValid === null ? cwYesNoLabels.notConfigured : (content.identityHmacValid ? cwYesNoLabels.verified : cwYesNoLabels.invalid)));
			lines.push({$healthLineSettings|json_encode_html_attribute} + (allConfigured ? cwYesNoLabels.complete : cwYesNoLabels.incomplete));
			lines.push({$healthLineLastChecked|json_encode_html_attribute} + cwYesNoLabels.justNow);
			return lines.join('\n');
		{rdelim}

		cwWireAction('chatwootHealthCheckBtn', function() {ldelim}
			return cwPost('{$healthCheckUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return cwFormatHealthCheck(resp.content || {ldelim}{rdelim});
		{rdelim});

		cwWireAction('chatwootTestMessageBtn', function() {ldelim}
			return cwPost('{$testMessageUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Done.';
		{rdelim});

		cwWireAction('chatwootExportBtn', function() {ldelim}
			return cwPost('{$exportSettingsUrl|escape:"javascript"}').done(function(resp) {ldelim}
				$('#chatwootImportExport').val(JSON.stringify(resp.content || {ldelim}{rdelim}, null, 2));
			{rdelim});
		{rdelim}, function() {ldelim}
			return 'Exported below.';
		{rdelim});

		cwWireAction('chatwootImportBtn', function() {ldelim}
			return cwPost('{$importSettingsUrl|escape:"javascript"}', {ldelim}importPayload: $('#chatwootImportExport').val(){rdelim});
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Imported.';
		{rdelim});

		cwWireAction('chatwootSaveGlobalBtn', function() {ldelim}
			return cwPost('{$saveGlobalProfileUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Saved.';
		{rdelim});

		cwWireAction('chatwootApplyGlobalBtn', function() {ldelim}
			return cwPost('{$applyGlobalProfileUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Applied.';
		{rdelim});

		var cwCaptainStatusLabels = {ldelim}
			synced: {$captainStatusSynced|json_encode_html_attribute},
			created: {$captainStatusCreated|json_encode_html_attribute},
			noop: {$captainStatusNoop|json_encode_html_attribute},
			conflict: {$captainStatusConflict|json_encode_html_attribute},
			failed: {$captainStatusFailed|json_encode_html_attribute},
			unavailable: {$captainStatusUnavailable|json_encode_html_attribute}
		{rdelim};
		function cwFormatCaptainCounts(counts) {ldelim}
			var parts = [];
			$.each(counts || {ldelim}{rdelim}, function(status, count) {ldelim}
				parts.push(count + ' ' + (cwCaptainStatusLabels[status] || status));
			{rdelim});
			return parts.length ? parts.join(', ') : {$captainNoneLabel|json_encode_html_attribute};
		{rdelim}
		function cwFormatCaptainSync(content) {ldelim}
			var hasIssues = content.document === 'failed'
				|| Object.prototype.hasOwnProperty.call(content.tools || {ldelim}{rdelim}, 'failed')
				|| Object.prototype.hasOwnProperty.call(content.scenarios || {ldelim}{rdelim}, 'failed');
			var allUpToDate = (content.document === 'synced' || content.document === 'noop')
				&& Object.keys(content.tools || {ldelim}{rdelim}).every(function(k) {ldelim} return k === 'noop'; {rdelim})
				&& Object.keys(content.scenarios || {ldelim}{rdelim}).every(function(k) {ldelim} return k === 'noop'; {rdelim});
			if (allUpToDate) return {$captainUpToDateLabel|json_encode_html_attribute};
			var lines = [hasIssues ? {$captainCompletedWithIssuesLabel|json_encode_html_attribute} : {$captainCompletedLabel|json_encode_html_attribute}];
			lines.push({$captainLineDocument|json_encode_html_attribute} + (cwCaptainStatusLabels[content.document] || content.document));
			lines.push({$captainLineTools|json_encode_html_attribute} + cwFormatCaptainCounts(content.tools));
			lines.push({$captainLineScenarios|json_encode_html_attribute} + cwFormatCaptainCounts(content.scenarios));
			return lines.join('\n');
		{rdelim}

		cwWireAction('chatwootSyncCaptainBtn', function() {ldelim}
			return cwPost('{$syncCaptainResourcesUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return cwFormatCaptainSync(resp.content || {ldelim}{rdelim});
		{rdelim});

		cwWireAction('chatwootRetryDeadLettersBtn', function() {ldelim}
			return cwPost('{$retryDeadLetterEventsUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			var retried = (resp.content || {ldelim}{rdelim}).retried || 0;
			return retried > 0
				? {$retryRetriedLabel|json_encode_html_attribute}.replace('%d', retried)
				: {$retryNoneLabel|json_encode_html_attribute};
		{rdelim});

		cwWireAction('chatwootSendMailTestBtn', function() {ldelim}
			return cwPost('{$sendSupportMailTestUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Done.';
		{rdelim});

		// API & MCP tab (owner directive 2026-09-04, item H): Generate/
		// Rotate persists the new value server-side immediately (see
		// generateServiceCredential()) and shows the real plaintext
		// exactly once here for the admin to copy now — the hidden field
		// is updated too so a normal Save Settings doesn't silently
		// revert it, but the value is never redisplayed on any later
		// page load.
		function cwWireCredentialGenerate(btnId, credentialKey, fieldId, isConfiguredAlready) {ldelim}
			if (isConfiguredAlready) {ldelim} $('#' + btnId).text({$apiMcpRotateLabel|json_encode_html_attribute}); {rdelim}
			cwWireAction(btnId, function() {ldelim}
				return cwPost('{$generateServiceCredentialUrl|escape:"javascript"}', {ldelim}credentialKey: credentialKey{rdelim});
			{rdelim}, function(resp) {ldelim}
				var value = (resp.content || {ldelim}{rdelim}).value || '';
				$('#' + fieldId).val(value);
				$('#' + btnId).text({$apiMcpRotateLabel|json_encode_html_attribute});
				return {$apiMcpGeneratedLabel|json_encode_html_attribute} + value;
			{rdelim});
		{rdelim}
		cwWireCredentialGenerate('chatwootGenerateSupportApiTokenBtn', 'chatwootSupportApiToken', 'chatwootSupportApiToken', {if $supportApiTokenConfigured}true{else}false{/if});
		cwWireCredentialGenerate('chatwootGenerateMcpTokenBtn', 'mcpServiceToken', 'mcpServiceToken', {if $mcpServiceTokenConfigured}true{else}false{/if});

		// Chatwoot tab console (owner directive 2026-09-04): discovery
		// populates the account/inbox/assistant selects from real
		// Chatwoot data instead of requiring a raw numeric ID. Re-run
		// automatically once an account is explicitly chosen from more
		// than one candidate.
		function cwPopulateSelect($select, items, placeholderKey, emptyKey) {ldelim}
			var currentValue = $select.data('current-value') || $select.val();
			$select.empty();
			if (!items.length) {ldelim}
				$select.append($('<option>').val('').text(emptyKey));
				return;
			{rdelim}
			$select.append($('<option>').val('').text(placeholderKey));
			var matched = false;
			items.forEach(function(item) {ldelim}
				var label = item.name || ('#' + item.id);
				if (item.websiteUrl) {ldelim} label += ' (' + item.websiteUrl + ')'; {rdelim}
				var $opt = $('<option>').val(item.id).text(label);
				if (String(item.id) === String(currentValue)) {ldelim} $opt.prop('selected', true); matched = true; {rdelim}
				$select.append($opt);
			{rdelim});
			if (!matched && currentValue) {ldelim}
				$select.prepend($('<option>').val(currentValue).text('Current: #' + currentValue).prop('selected', true));
			{rdelim}
		{rdelim}

		// Owner browser review 2026-09-04, finding #3: a discovered
		// resource's human name must survive save + reload, not reset to
		// "Not tested yet (ID X)" merely because the modal reopened.
		// Persisted into a real hidden setting field (see SettingsRegistry's
		// chatwootAccountName/chatwootInboxName/chatwootCaptainAssistantName/
		// chatwootDiscoveryVerifiedAt) every time a select's real selection
		// changes, not only right after a fresh discovery.
		function cwSyncSelectName($select, hiddenId) {ldelim}
			var $selected = $select.find('option:selected');
			$('#' + hiddenId).val($selected.length ? $selected.text() : '');
		{rdelim}
		$('#chatwootInboxId').on('change', function() {ldelim} cwSyncSelectName($(this), 'chatwootInboxName'); {rdelim});
		$('#chatwootCaptainAssistantId').on('change', function() {ldelim} cwSyncSelectName($(this), 'chatwootCaptainAssistantName'); {rdelim});

		function cwRunDiscovery(extraData) {ldelim}
			var $btn = $('#chatwootDiscoverBtn');
			return cwPost('{$discoverChatwootResourcesUrl|escape:"javascript"}', $.extend({ldelim}
				chatwootBaseUrl: $('#chatwootBaseUrl').val(),
				chatwootApiAccessToken: $('#chatwootApiAccessToken').val()
			{rdelim}, extraData || {ldelim}{rdelim})).done(function(resp) {ldelim}
				var data = resp.content || resp;
				if (!data || !data.connected) {ldelim}
					cwShowStatus($btn, (typeof data === 'string' && data) || 'Connection failed.', true);
					return;
				{rdelim}
				if (data.needsAccountSelection) {ldelim}
					$('#chatwootAccountSelectorWrap').removeAttr('hidden');
					var $accSelect = $('#chatwootAccountIdSelect');
					$accSelect.empty();
					(data.accounts || []).forEach(function(acc) {ldelim}
						$accSelect.append($('<option>').val(acc.id).text(acc.name || ('#' + acc.id)));
					{rdelim});
					cwShowStatus($btn, 'Multiple accounts found — choose one below.', false);
					return;
				{rdelim}
				$('#chatwootAccountSelectorWrap').attr('hidden', true);
				$('#chatwootAccountId').val(data.selectedAccountId);
				var accountName = (data.accounts || []).filter(function(a) {ldelim} return String(a.id) === String(data.selectedAccountId); {rdelim})[0];
				$('#chatwootAccountName').val(accountName ? accountName.name : '');
				$('#chatwootDiscoveryVerifiedAt').val(new Date().toISOString());
				$('#chatwootDiscoverSummary').text('Connected. Account: ' + (accountName ? accountName.name : data.selectedAccountId));
				cwPopulateSelect($('#chatwootInboxId'), data.inboxes || [], 'Select a Website Inbox…', 'No Website inboxes found.');
				cwPopulateSelect($('#chatwootCaptainAssistantId'), data.assistants || [], 'Select a Captain Assistant…', 'No Captain Assistants found.');
				cwSyncSelectName($('#chatwootInboxId'), 'chatwootInboxName');
				cwSyncSelectName($('#chatwootCaptainAssistantId'), 'chatwootCaptainAssistantName');
				cwShowStatus($btn, 'Connected.', false);
			{rdelim}).fail(function(jqXHR) {ldelim}
				var message = (jqXHR.responseJSON && (jqXHR.responseJSON.content || jqXHR.responseJSON.errorMessage)) || 'Connection failed.';
				cwShowStatus($btn, message, true);
			{rdelim});
		{rdelim}

		$('#chatwootDiscoverBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwRunDiscovery({ldelim}{rdelim});
		{rdelim});

		$('#chatwootUseAccountBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwRunDiscovery({ldelim}discoverAccountId: $('#chatwootAccountIdSelect').val(){rdelim});
		{rdelim});

		// Widget tab console (owner directive 2026-09-04): show only the
		// fields relevant to the current selection, and keep the local
		// preview in sync — a pure client-side approximation, never the
		// real Chatwoot iframe/SDK.
		function cwUpdateWidgetFieldVisibility() {ldelim}
			$('#widgetLauncherTitleWrap').attr('hidden', $('#widgetLauncherStyle').val() !== 'expanded_bubble');
			$('#widgetFixedLocaleWrap').attr('hidden', $('#widgetLanguageMode').val() !== 'fixed');
		{rdelim}

		function cwUpdateWidgetPreview() {ldelim}
			var $bubble = $('#cwWidgetPreviewBubble');
			var position = $('#widgetPosition').val();
			var style = $('#widgetLauncherStyle').val();
			var theme = $('#widgetTheme').val();
			var title = $('#widgetLauncherTitle').val() || 'Chat with us';

			$bubble.toggleClass('cwPreviewLeft', position === 'left');
			$bubble.toggleClass('cwPreviewDark', theme === 'dark');
			$bubble.toggleClass('cwPreviewLight', theme === 'light');

			$('#cwWidgetPreviewBubbleLabel').text(style === 'expanded_bubble' ? title : '');
		{rdelim}

		$('#widgetPosition, #widgetLauncherStyle, #widgetLauncherTitle, #widgetTheme, #widgetLanguageMode').on('change keyup', function() {ldelim}
			cwUpdateWidgetFieldVisibility();
			cwUpdateWidgetPreview();
		{rdelim});
		cwUpdateWidgetFieldVisibility();
		cwUpdateWidgetPreview();

		// Positive audience model: live "effective audience" summary —
		// owner directive 2026-09-04 item D ("show the effective audience").
		var cwAudienceLabels = {ldelim}
			guest: {$audienceLabelGuest|json_encode_html_attribute},
			author: {$audienceLabelAuthor|json_encode_html_attribute},
			reviewer: {$audienceLabelReviewer|json_encode_html_attribute},
			reader: {$audienceLabelReader|json_encode_html_attribute},
			manager: {$audienceLabelManager|json_encode_html_attribute},
			subEditor: {$audienceLabelSubEditor|json_encode_html_attribute},
			assistant: {$audienceLabelAssistant|json_encode_html_attribute},
			siteAdmin: {$audienceLabelSiteAdmin|json_encode_html_attribute}
		{rdelim};
		function cwUpdateEffectiveAudience() {ldelim}
			var allowed = [];
			$.each(cwAudienceLabels, function(key, label) {ldelim}
				if ($('#audienceAllow_' + key).is(':checked')) allowed.push(label);
			{rdelim});
			var summary = allowed.length ? allowed.join(', ') : {$audienceNoOneLabel|json_encode_html_attribute};
			$('#cwEffectiveAudience').text({$audienceEffectivePrefix|json_encode_html_attribute} + summary);
		{rdelim}
		$('[id^="audienceAllow_"]').on('change', cwUpdateEffectiveAudience);
		cwUpdateEffectiveAudience();

		// Automation/Event Bridge matrix (owner directive 2026-09-04,
		// item E): inline per-row customer-visible-message warning +
		// consent, instead of one detached global checkbox. Never rely
		// on the consent checkbox being seen if no row actually needs it.
		function cwUpdateEventConsentVisibility() {ldelim}
			var anyCustomerVisible = false;
			$('.cwEventActionSelect').each(function() {ldelim}
				var isCustomerVisible = $(this).val() === 'opt_in_customer_message';
				$('#cwCustomerVisibleWarning_' + $(this).attr('id').replace('eventAction_', '')).prop('hidden', !isCustomerVisible);
				if (isCustomerVisible) anyCustomerVisible = true;
			{rdelim});
			$('#cwEventConsentWrap').prop('hidden', !anyCustomerVisible);
		{rdelim}
		$('.cwEventActionSelect').on('change', cwUpdateEventConsentVisibility);
		cwUpdateEventConsentVisibility();
	{rdelim});
</script>

<form class="pkp_form pkpc-chatwootIntegrationSettings" id="chatwootIntegrationSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="chatwootIntegrationSettingsFormNotification"}

	<div id="chatwootSettingsDescription">{translate key="plugins.generic.chatwootIntegration.description"}</div>

	<div role="tablist" aria-label="{translate key="plugins.generic.chatwootIntegration.settings"}">
		<button type="button" role="tab" id="cwTab-overview" aria-controls="cwPanel-overview" aria-selected="true" tabindex="0">{translate key="plugins.generic.chatwootIntegration.settings.tab.overview"}</button>
		<button type="button" role="tab" id="cwTab-chatwoot" aria-controls="cwPanel-chatwoot" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.chatwoot"}</button>
		<button type="button" role="tab" id="cwTab-widget" aria-controls="cwPanel-widget" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.widget"}</button>
		<button type="button" role="tab" id="cwTab-automation" aria-controls="cwPanel-automation" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.automation"}</button>
		<button type="button" role="tab" id="cwTab-verification" aria-controls="cwPanel-verification" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.verification"}</button>
		<button type="button" role="tab" id="cwTab-aiKnowledge" aria-controls="cwPanel-aiKnowledge" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.aiKnowledge"}</button>
		<button type="button" role="tab" id="cwTab-apiMcp" aria-controls="cwPanel-apiMcp" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.apiMcp"}</button>
		<button type="button" role="tab" id="cwTab-integrations" aria-controls="cwPanel-integrations" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.integrations"}</button>
		<button type="button" role="tab" id="cwTab-advanced" aria-controls="cwPanel-advanced" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.advanced"}</button>
	</div>

	{fbvFormArea id="chatwootIntegrationSettingsFormArea"}

		{* ================================================================ *}
		{* Overview: real system status only — never placebo/decorative.    *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-overview" aria-labelledby="cwTab-overview">
			{if $supportGatewayHealth}
				<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.overview.description"}</p>

				{if $overviewNeedsAttentionLabels|@count}
					<div class="cwOverviewBanner cwOverviewBannerAttention">
						{translate key="plugins.generic.chatwootIntegration.settings.overview.banner.needsAttention" attentionCount=$overviewNeedsAttentionLabels|@count|escape}
						{$overviewNeedsAttentionLabels|@implode:", "|escape}
					</div>
				{else}
					<div class="cwOverviewBanner cwOverviewBannerOk">{translate key="plugins.generic.chatwootIntegration.settings.overview.banner.noIssues"}</div>
				{/if}

				<div class="cwOverviewGrid">
					{foreach from=$overviewCards item=card}
						<div class="cwOverviewCard cwState-{$card.state|escape}">
							<div class="cwOverviewCardLabel">{$card.label|escape}</div>
							<div class="cwOverviewCardState">{$overviewStateLabels[$card.state]|escape}</div>
						</div>
					{/foreach}
				</div>

				{if $supportGatewayHealth.deadLetterCount > 0}
					<div class="cwSectionDescription">
						{translate key="plugins.generic.chatwootIntegration.settings.health.deadLetterCount"} {$supportGatewayHealth.deadLetterCount|escape}
						{if $supportGatewayHealth.queueHealth}
							{foreach from=$supportGatewayHealth.queueHealth.deadLetterErrorCodes key=errorCode item=errorCodeCount}
								<br>{translate key="plugins.generic.chatwootIntegration.settings.health.deadLetterErrorCode" errorCode=$errorCode|escape deadLetterCount=$errorCodeCount|escape}
							{/foreach}
						{/if}
					</div>
					{fbvElement type="button" id="chatwootRetryDeadLettersBtn" label="plugins.generic.chatwootIntegration.settings.health.retryDeadLetters"}
					<div class="cwActionStatus" id="chatwootRetryDeadLettersBtnStatus" role="status" hidden></div>
				{/if}
			{else}
				<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.health.notChecked"}</p>
			{/if}
			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.healthCheck.description"}</div>
			{fbvElement type="button" id="chatwootHealthCheckBtn" label="plugins.generic.chatwootIntegration.settings.healthCheck"}
			<div class="cwActionStatus" id="chatwootHealthCheckBtnStatus" role="status" hidden></div>
			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.testMessage.description"}</div>
			{fbvElement type="button" id="chatwootTestMessageBtn" label="plugins.generic.chatwootIntegration.settings.testMessage"}
			<div class="cwActionStatus" id="chatwootTestMessageBtnStatus" role="status" hidden></div>
		</div>

		{* ================================================================ *}
		{* Chatwoot: connection fields — SettingsRegistry tab "chatwoot".   *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-chatwoot" aria-labelledby="cwTab-chatwoot" hidden>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootBaseUrl"}
				{fbvElement type="text" id="chatwootBaseUrl" value=$chatwootBaseUrl required=true label="plugins.generic.chatwootIntegration.settings.chatwootBaseUrl.description"}
			{/fbvFormSection}

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootWebsiteToken"}
				{fbvElement type="text" id="chatwootWebsiteToken" value=$chatwootWebsiteToken required=true label="plugins.generic.chatwootIntegration.settings.chatwootWebsiteToken.description"}
			{/fbvFormSection}

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootIdentityValidationSecret"}
				{fbvElement type="text" password=true id="chatwootIdentityValidationSecret" value=$chatwootIdentityValidationSecret label="plugins.generic.chatwootIntegration.settings.chatwootIdentityValidationSecret.description"}
			{/fbvFormSection}

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootApiAccessToken"}
				{fbvElement type="text" password=true id="chatwootApiAccessToken" value=$chatwootApiAccessToken label="plugins.generic.chatwootIntegration.settings.chatwootApiAccessToken.description"}
			{/fbvFormSection}

			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.discover.description"}</div>
			{fbvElement type="button" id="chatwootDiscoverBtn" label="plugins.generic.chatwootIntegration.settings.discoverBtn"}
			<div class="cwActionStatus" id="chatwootDiscoverBtnStatus" role="status" hidden></div>
			<p id="chatwootDiscoverSummary" class="cwSectionDescription">
				{if $chatwootAccountName}
					{translate key="plugins.generic.chatwootIntegration.settings.discover.connectedAccount"}{$chatwootAccountName|escape} — {translate key="plugins.generic.chatwootIntegration.settings.discover.lastVerifiedPrefix"}{$chatwootDiscoveryVerifiedAt|escape}
				{else}
					{translate key="plugins.generic.chatwootIntegration.settings.discover.notRunYet"}
				{/if}
			</p>

			<div id="chatwootAccountSelectorWrap" hidden>
				{fbvFormSection title="plugins.generic.chatwootIntegration.settings.accountSelector"}
					<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.accountSelector.multiple"}</p>
					<select id="chatwootAccountIdSelect" class="cwDiscoverySelect"></select>
					{fbvElement type="button" id="chatwootUseAccountBtn" label="common.select"}
				{/fbvFormSection}
			</div>
			<input type="hidden" id="chatwootAccountId" name="chatwootAccountId" value="{$chatwootAccountId|escape}">
			<input type="hidden" id="chatwootAccountName" name="chatwootAccountName" value="{$chatwootAccountName|escape}">
			<input type="hidden" id="chatwootInboxName" name="chatwootInboxName" value="{$chatwootInboxName|escape}">
			<input type="hidden" id="chatwootDiscoveryVerifiedAt" name="chatwootDiscoveryVerifiedAt" value="{$chatwootDiscoveryVerifiedAt|escape}">

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootInboxId"}
				<select id="chatwootInboxId" name="chatwootInboxId" class="cwDiscoverySelect" data-current-value="{$chatwootInboxId|escape}">
					{if $chatwootInboxId}
						<option value="{$chatwootInboxId|escape}" selected>
							{if $chatwootInboxName}{$chatwootInboxName|escape}{else}{translate key="plugins.generic.chatwootIntegration.settings.discover.savedNeedsVerify"} (ID {$chatwootInboxId|escape}){/if}
						</option>
					{/if}
				</select>
				{if $chatwootInboxName}<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.discover.lastVerifiedPrefix"}{$chatwootDiscoveryVerifiedAt|escape}</p>{/if}
			{/fbvFormSection}
		</div>

		{* ================================================================ *}
		{* Widget: visibility/appearance — SettingsRegistry tab "widget".   *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-widget" aria-labelledby="cwTab-widget" hidden>
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="enableWidget" value="1" checked=$enableWidget label="plugins.generic.chatwootIntegration.settings.enableWidget"}
			{/fbvFormSection}

			{* ============================================================ *}
			{* Blind-review protection: a frozen security invariant, never  *}
			{* an optional checkbox (owner directive 2026-09-04). Reviewer  *}
			{* identity masking is unconditional in resolveReviewerMasking()'s*}
			{* callers — this is status text, not a control.                *}
			{* ============================================================ *}
			<div class="cwSecurityInvariant">
				<strong>{translate key="plugins.generic.chatwootIntegration.settings.blindReview.title"}</strong>
				<p>{translate key="plugins.generic.chatwootIntegration.settings.blindReview.description"}</p>
			</div>

			{* ============================================================ *}
			{* Positive audience model: "who can see the widget?" replaces  *}
			{* the old negative Hide-for-X checkboxes. Each audienceAllow_* *}
			{* field is inverted to/from the legacy hideFor* settings by    *}
			{* ChatwootSettingsForm — see its own note.                     *}
			{* ============================================================ *}
			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.audience"}
				<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.audience.description"}</p>
				{fbvElement type="checkbox" id="audienceAllow_guest" value="1" checked=$audienceAllow_guest label="plugins.generic.chatwootIntegration.settings.audience.guest"}
				{fbvElement type="checkbox" id="audienceAllow_author" value="1" checked=$audienceAllow_author label="plugins.generic.chatwootIntegration.settings.audience.author"}
				{fbvElement type="checkbox" id="audienceAllow_reviewer" value="1" checked=$audienceAllow_reviewer label="plugins.generic.chatwootIntegration.settings.audience.reviewer"}
				{fbvElement type="checkbox" id="audienceAllow_reader" value="1" checked=$audienceAllow_reader label="plugins.generic.chatwootIntegration.settings.audience.reader"}
				{fbvElement type="checkbox" id="audienceAllow_manager" value="1" checked=$audienceAllow_manager label="plugins.generic.chatwootIntegration.settings.audience.manager"}
				{fbvElement type="checkbox" id="audienceAllow_subEditor" value="1" checked=$audienceAllow_subEditor label="plugins.generic.chatwootIntegration.settings.audience.subEditor"}
				{fbvElement type="checkbox" id="audienceAllow_assistant" value="1" checked=$audienceAllow_assistant label="plugins.generic.chatwootIntegration.settings.audience.assistant"}
				{fbvElement type="checkbox" id="audienceAllow_siteAdmin" value="1" checked=$audienceAllow_siteAdmin label="plugins.generic.chatwootIntegration.settings.audience.siteAdmin"}
				<p id="cwEffectiveAudience" class="description"></p>
			{/fbvFormSection}

			{* ============================================================ *}
			{* Structured Appearance — owner directive 2026-09-04, item C.  *}
			{* Every option here is a real window.chatwootSettings key the  *}
			{* deployed Chatwoot SDK actually reads (verified against the   *}
			{* real bundle) — no raw JSON for the ordinary case.            *}
			{* ============================================================ *}
			<h3>{translate key="plugins.generic.chatwootIntegration.settings.appearance"}</h3>
			<div class="cwWidgetAppearanceLayout">
				<div class="cwWidgetAppearanceControls">
					{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetPosition"}
						{fbvElement type="select" id="widgetPosition" from=$widgetPositionOptions selected=$widgetPosition label="plugins.generic.chatwootIntegration.settings.widgetPosition" translate=false}
					{/fbvFormSection}
					{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetLauncherStyle"}
						{fbvElement type="select" id="widgetLauncherStyle" from=$widgetLauncherStyleOptions selected=$widgetLauncherStyle label="plugins.generic.chatwootIntegration.settings.widgetLauncherStyle" translate=false}
					{/fbvFormSection}
					<div id="widgetLauncherTitleWrap">
						{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetLauncherTitle"}
							{fbvElement type="text" id="widgetLauncherTitle" value=$widgetLauncherTitle label="plugins.generic.chatwootIntegration.settings.widgetLauncherTitle.description"}
						{/fbvFormSection}
					</div>
					{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetLanguageMode"}
						{fbvElement type="select" id="widgetLanguageMode" from=$widgetLanguageModeOptions selected=$widgetLanguageMode label="plugins.generic.chatwootIntegration.settings.widgetLanguageMode" translate=false}
					{/fbvFormSection}
					<div id="widgetFixedLocaleWrap">
						{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetFixedLocale"}
							{fbvElement type="text" id="widgetFixedLocale" value=$widgetFixedLocale label="plugins.generic.chatwootIntegration.settings.widgetFixedLocale.description"}
						{/fbvFormSection}
					</div>
					{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetTheme"}
						{fbvElement type="select" id="widgetTheme" from=$widgetThemeOptions selected=$widgetTheme label="plugins.generic.chatwootIntegration.settings.widgetTheme" translate=false}
					{/fbvFormSection}
					{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.widgetOtherOptions"}
						{fbvElement type="checkbox" id="widgetShowPopoutButton" value="1" checked=$widgetShowPopoutButton label="plugins.generic.chatwootIntegration.settings.widgetShowPopoutButton"}
						{fbvElement type="checkbox" id="widgetShowUnreadDialog" value="1" checked=$widgetShowUnreadDialog label="plugins.generic.chatwootIntegration.settings.widgetShowUnreadDialog"}
						{fbvElement type="checkbox" id="widgetHideMessageBubble" value="1" checked=$widgetHideMessageBubble label="plugins.generic.chatwootIntegration.settings.widgetHideMessageBubble"}
					{/fbvFormSection}
				</div>

				{* Local-only visual approximation — never boots the real *}
				{* Chatwoot iframe/SDK, just reflects the controls above. *}
				<div class="cwWidgetPreviewWrap">
					<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.widgetPreview"}</p>
					<div id="cwWidgetPreviewStage">
						<div id="cwWidgetPreviewBubble"><span id="cwWidgetPreviewBubbleIcon">💬</span><span id="cwWidgetPreviewBubbleLabel"></span></div>
					</div>
				</div>
			</div>
		</div>

		{* ================================================================ *}
		{* Automation: Event Bridge — SettingsRegistry tab "automation".    *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-automation" aria-labelledby="cwTab-automation" hidden>
			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.description"}</div>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode"}
				{fbvElement type="select" id="eventDeliveryGlobalMode" from=$eventDeliveryGlobalModeOptions selected=$eventDeliveryGlobalMode label="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.help" translate=false}
			{/fbvFormSection}

			{* ============================================================ *}
			{* Event/action matrix — owner directive 2026-09-04, item E:    *}
			{* one understandable table replacing the old fragmented        *}
			{* checkboxes + raw per-event JSON. Enabled checkboxes are      *}
			{* real Automation eventXxx/eventReviewSubmitted settings that  *}
			{* now genuinely gate delivery (see v2EnqueueEvent()'s          *}
			{* EVENT_TYPE_ENABLED_SETTING). Action selects are encoded back *}
			{* into the same eventDeliveryPerEventOverridesJson storage by  *}
			{* ChatwootSettingsForm — no raw JSON in this tab.              *}
			{* ============================================================ *}
			<h3>{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix"}</h3>
			<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix.description"}</p>
			<table class="cwEventMatrix">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix.event"}</th>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix.enabled"}</th>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix.action"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$eventMatrixRows item=row}
						<tr>
							<td>{$row.label|escape}</td>
							<td>
								{if $row.rowKey == 'submissionCreated'}{fbvElement type="checkbox" id="eventSubmissionCreated" value="1" checked=$eventSubmissionCreated label=""}{/if}
								{if $row.rowKey == 'reviewSubmitted'}{fbvElement type="checkbox" id="eventReviewSubmitted" value="1" checked=$eventReviewSubmitted label=""}{/if}
								{if $row.rowKey == 'revisionRequested'}{fbvElement type="checkbox" id="eventRevisionRequested" value="1" checked=$eventRevisionRequested label=""}{/if}
								{if $row.rowKey == 'accepted'}{fbvElement type="checkbox" id="eventAccepted" value="1" checked=$eventAccepted label=""}{/if}
								{if $row.rowKey == 'rejected'}{fbvElement type="checkbox" id="eventRejected" value="1" checked=$eventRejected label=""}{/if}
								{if $row.rowKey == 'publicationScheduled'}{fbvElement type="checkbox" id="eventPublicationScheduled" value="1" checked=$eventPublicationScheduled label=""}{/if}
								{if $row.rowKey == 'publicationPublished'}{fbvElement type="checkbox" id="eventPublicationPublished" value="1" checked=$eventPublicationPublished label=""}{/if}
								{if $row.rowKey == 'decisionRecorded'}{fbvElement type="checkbox" id="eventDecisionRecorded" value="1" checked=$eventDecisionRecorded label=""}{/if}
							</td>
							<td>
								<select id="eventAction_{$row.rowKey}" name="eventAction_{$row.rowKey}" class="cwEventActionSelect">
									{html_options options=$eventActionOptions selected=$row.currentAction}
								</select>
								<p class="cwCustomerVisibleWarning" id="cwCustomerVisibleWarning_{$row.rowKey}" hidden>
									{translate key="plugins.generic.chatwootIntegration.settings.eventMatrix.customerVisibleWarning"}
								</p>
							</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			<div id="cwEventConsentWrap" hidden>
				{fbvFormSection list=true}
					{fbvElement type="checkbox" id="eventDeliveryCustomerMessageConsent" value="1" checked=$eventDeliveryCustomerMessageConsent label="plugins.generic.chatwootIntegration.settings.eventDeliveryCustomerMessageConsent.description"}
				{/fbvFormSection}
			</div>

		</div>

		{* ================================================================ *}
		{* Verification — Settings Console item G / HAR-014 remainder. No  *}
		{* real setting keys of its own (identity secrets live on the       *}
		{* Chatwoot tab); this is a status/action panel over the real       *}
		{* VerificationEmailTemplateKeys EmailTemplate state.               *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-verification" aria-labelledby="cwTab-verification" hidden>
			<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.verification.description"}</p>

			<div class="cwOverviewGrid">
				<div class="cwOverviewCard cwState-{if $chatwootIdentityValidationSecret}configured{else}optional_off{/if}">
					<div class="cwOverviewCardLabel">{translate key="plugins.generic.chatwootIntegration.settings.verification.card.identityHmac"}</div>
					<div class="cwOverviewCardState">{if $chatwootIdentityValidationSecret}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.configured"}{else}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.optionalOff"}{/if}</div>
				</div>
				<div class="cwOverviewCard cwState-{if $verificationPinTemplateExists}healthy{else}configured{/if}">
					<div class="cwOverviewCardLabel">{translate key="plugins.generic.chatwootIntegration.settings.verification.card.pinTemplate"}</div>
					<div class="cwOverviewCardState">{if $verificationPinTemplateExists}{translate key="plugins.generic.chatwootIntegration.settings.verification.state.customized"}{else}{translate key="plugins.generic.chatwootIntegration.settings.verification.state.usingDefault"}{/if}</div>
				</div>
				<div class="cwOverviewCard cwState-{if $verificationLinkTemplateExists}healthy{else}configured{/if}">
					<div class="cwOverviewCardLabel">{translate key="plugins.generic.chatwootIntegration.settings.verification.card.linkTemplate"}</div>
					<div class="cwOverviewCardState">{if $verificationLinkTemplateExists}{translate key="plugins.generic.chatwootIntegration.settings.verification.state.customized"}{else}{translate key="plugins.generic.chatwootIntegration.settings.verification.state.usingDefault"}{/if}</div>
				</div>
			</div>

			<h3>{translate key="plugins.generic.chatwootIntegration.settings.verification.methods"}</h3>
			<ul>
				<li>{translate key="plugins.generic.chatwootIntegration.settings.verification.methods.pin"}</li>
				<li>{translate key="plugins.generic.chatwootIntegration.settings.verification.methods.link"}</li>
			</ul>
			<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.verification.policySummary"}</p>

			<div class="cwSecurityInvariant">
				<strong>{translate key="plugins.generic.chatwootIntegration.settings.verification.privacy.title"}</strong>
				<p>{translate key="plugins.generic.chatwootIntegration.settings.verification.privacy.description"}</p>
			</div>

			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.health.sendMailTestDescription"}</div>
			{fbvElement type="button" id="chatwootSendMailTestBtn" label="plugins.generic.chatwootIntegration.settings.health.sendMailTest"}
			<div class="cwActionStatus" id="chatwootSendMailTestBtnStatus" role="status" hidden></div>

			<p class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.verification.manageTemplates.description"}
				<a href="{$manageEmailTemplatesUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.chatwootIntegration.settings.verification.manageTemplates"}</a>
			</p>
		</div>

		{* ================================================================ *}
		{* AI & Knowledge — SettingsRegistry tab "ai_knowledge".            *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-aiKnowledge" aria-labelledby="cwTab-aiKnowledge" hidden>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId"}
				<select id="chatwootCaptainAssistantId" name="chatwootCaptainAssistantId" class="cwDiscoverySelect" data-current-value="{$chatwootCaptainAssistantId|escape}">
					{if $chatwootCaptainAssistantId}
						<option value="{$chatwootCaptainAssistantId|escape}" selected>
							{if $chatwootCaptainAssistantName}{$chatwootCaptainAssistantName|escape}{else}{translate key="plugins.generic.chatwootIntegration.settings.discover.savedNeedsVerify"} (ID {$chatwootCaptainAssistantId|escape}){/if}
						</option>
					{/if}
				</select>
				<input type="hidden" id="chatwootCaptainAssistantName" name="chatwootCaptainAssistantName" value="{$chatwootCaptainAssistantName|escape}">
				<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId.description"}</p>
			{/fbvFormSection}
			<div class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.health.syncCaptainDescription"}
			</div>
			{fbvElement type="button" id="chatwootSyncCaptainBtn" label="plugins.generic.chatwootIntegration.settings.health.syncCaptain"}
			<div class="cwActionStatus" id="chatwootSyncCaptainBtnStatus" role="status" hidden></div>
		</div>

		{* ================================================================ *}
		{* API & MCP — SettingsRegistry tab "api_mcp".                      *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-apiMcp" aria-labelledby="cwTab-apiMcp" hidden>
			<div class="cwSecurityInvariant">
				<strong>{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.authVsAuthz.title"}</strong>
				<p>{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.authVsAuthz.description"}</p>
			</div>

			<h3>{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.supportApi"}</h3>
			<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.supportApi.description"}</p>
			<div class="cwOverviewCard cwCredentialCard cwState-{if $supportApiTokenConfigured}configured{else}not_configured{/if}">
				<div class="cwOverviewCardLabel">{translate key="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken"}</div>
				<div class="cwOverviewCardState">{if $supportApiTokenConfigured}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.configured"}{else}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.notConfigured"}{/if}</div>
			</div>
			{fbvElement type="button" id="chatwootGenerateSupportApiTokenBtn" label="plugins.generic.chatwootIntegration.settings.apiMcp.generate"}
			<div class="cwActionStatus" id="chatwootGenerateSupportApiTokenBtnStatus" role="status" hidden></div>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken"}
				{fbvElement type="text" password=true id="chatwootSupportApiToken" value=$chatwootSupportApiToken label="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken.description"}
			{/fbvFormSection}

			<h3>{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.mcp"}</h3>
			<div class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.mcpEndpoint.description" endpoint=$mcpEndpointUrl revision=$mcpProtocolRevision}
			</div>
			<div class="cwOverviewCard cwCredentialCard cwState-{if $mcpServiceTokenConfigured}configured{else}optional_off{/if}">
				<div class="cwOverviewCardLabel">{translate key="plugins.generic.chatwootIntegration.settings.mcpServiceToken"}</div>
				<div class="cwOverviewCardState">{if $mcpServiceTokenConfigured}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.configured"}{else}{translate key="plugins.generic.chatwootIntegration.settings.overview.state.optionalOff"}{/if}</div>
			</div>
			{fbvElement type="button" id="chatwootGenerateMcpTokenBtn" label="plugins.generic.chatwootIntegration.settings.apiMcp.generate"}
			<div class="cwActionStatus" id="chatwootGenerateMcpTokenBtnStatus" role="status" hidden></div>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.mcpServiceToken"}
				{fbvElement type="text" password=true id="mcpServiceToken" value=$mcpServiceToken label="plugins.generic.chatwootIntegration.settings.mcpServiceToken.description"}
			{/fbvFormSection}

			<h3>{translate key="plugins.generic.chatwootIntegration.settings.apiMcp.capabilities" mcpToolCount=$mcpToolCount|escape}</h3>
			<ul class="cwMcpToolList">
				{foreach from=$mcpToolSummaries item=tool}
					<li><strong>{$tool.name|escape}</strong> — {$tool.description|escape}</li>
				{/foreach}
			</ul>
		</div>

		{* ================================================================ *}
		{* Integrations — Settings Console item I. Real installed/enabled  *}
		{* state for real, verified sibling plugins only (IntegrationCatalog)*}
		{* — no setting keys of its own, status + link-out only.           *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-integrations" aria-labelledby="cwTab-integrations" hidden>
			<p class="description">{translate key="plugins.generic.chatwootIntegration.settings.integrations.description"}</p>
			<table class="cwEventMatrix">
				<thead>
					<tr>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.integrations.name"}</th>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.integrations.status"}</th>
						<th>{translate key="plugins.generic.chatwootIntegration.settings.integrations.version"}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$integrationEntries item=entry}
						<tr>
							<td>{$entry.label|escape}</td>
							<td>
								{if !$entry.installed}
									<span class="cwOverviewCardState">{translate key="plugins.generic.chatwootIntegration.settings.overview.state.notConfigured"}</span>
								{elseif $entry.enabled}
									<span class="cwOverviewCardState">{translate key="plugins.generic.chatwootIntegration.settings.integrations.enabled"}</span>
								{else}
									<span class="cwOverviewCardState">{translate key="plugins.generic.chatwootIntegration.settings.integrations.installedNotEnabled"}</span>
								{/if}
							</td>
							<td>{if $entry.versionString}{$entry.versionString|escape}{else}&mdash;{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			<p class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.integrations.openPlugins.description"}
				<a href="{$pluginsPageUrl|escape}" target="_blank" rel="noopener">{translate key="plugins.generic.chatwootIntegration.settings.integrations.openPlugins"}</a>
			</p>
		</div>

		{* ================================================================ *}
		{* Advanced — SettingsRegistry tab "advanced", plus power-user      *}
		{* migration controls (export/import/global profile) and           *}
		{* troubleshooting (debug mode, mail test).                        *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-advanced" aria-labelledby="cwTab-advanced" hidden>
			{* Legacy retry queue (HAR-012 remainder): the sole drain path
			   is ProcessLegacyRetryQueueScheduledTask; not the normal
			   Automation workflow, kept here until fully retired. *}
			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.legacyRetryQueue"}
				{fbvElement type="checkbox" id="retryQueueEnabled" value="1" checked=$retryQueueEnabled label="plugins.generic.chatwootIntegration.settings.retryQueueEnabled"}
				{fbvElement type="text" id="maxRetryAttempts" value=$maxRetryAttempts label="plugins.generic.chatwootIntegration.settings.maxRetryAttempts"}
				{fbvElement type="select" id="eventSyncMode" from=$eventSyncModeOptions selected=$eventSyncMode label="plugins.generic.chatwootIntegration.settings.eventSyncMode" translate=false}
			{/fbvFormSection}

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetSettingsJson"}
				<p class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.widgetSettingsJson.advancedDescription"}</p>
				{fbvElement type="textarea" id="widgetSettingsJson" value=$widgetSettingsJson label="plugins.generic.chatwootIntegration.settings.widgetSettingsJson"}
			{/fbvFormSection}

			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.performance"}
				{fbvElement type="checkbox" id="lazyLoadWidget" value="1" checked=$lazyLoadWidget label="plugins.generic.chatwootIntegration.settings.lazyLoadWidget"}
				{fbvElement type="select" id="lazyLoadTrigger" from=$lazyLoadTriggerOptions selected=$lazyLoadTrigger label="plugins.generic.chatwootIntegration.settings.lazyLoadTrigger" translate=false}
				{fbvElement type="text" id="excludedPages" value=$excludedPages label="plugins.generic.chatwootIntegration.settings.excludedPages"}
				{fbvElement type="checkbox" id="skipBackendPages" value="1" checked=$skipBackendPages label="plugins.generic.chatwootIntegration.settings.skipBackendPages"}
				{fbvElement type="checkbox" id="cspSafeMode" value="1" checked=$cspSafeMode label="plugins.generic.chatwootIntegration.settings.cspSafeMode"}
			{/fbvFormSection}

			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.troubleshooting"}
				{fbvElement type="checkbox" id="enableDebugMode" value="1" checked=$enableDebugMode label="plugins.generic.chatwootIntegration.settings.enableDebugMode"}
			{/fbvFormSection}

			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.adminTools"}
				{fbvElement type="checkbox" id="enableGlobalDefaults" value="1" checked=$enableGlobalDefaults label="plugins.generic.chatwootIntegration.settings.enableGlobalDefaults"}
				{fbvElement type="button" id="chatwootExportBtn" label="plugins.generic.chatwootIntegration.settings.exportSettings"}
				{fbvElement type="button" id="chatwootImportBtn" label="plugins.generic.chatwootIntegration.settings.importSettings"}
				{fbvElement type="button" id="chatwootSaveGlobalBtn" label="plugins.generic.chatwootIntegration.settings.saveGlobalProfile"}
				{fbvElement type="button" id="chatwootApplyGlobalBtn" label="plugins.generic.chatwootIntegration.settings.applyGlobalProfile"}
				{fbvElement type="textarea" id="chatwootImportExport" value="" label="plugins.generic.chatwootIntegration.settings.importExportPayload"}
			{/fbvFormSection}
			<div class="cwActionStatus" id="chatwootExportBtnStatus" role="status" hidden></div>
			<div class="cwActionStatus" id="chatwootImportBtnStatus" role="status" hidden></div>
			<div class="cwActionStatus" id="chatwootSaveGlobalBtnStatus" role="status" hidden></div>
			<div class="cwActionStatus" id="chatwootApplyGlobalBtnStatus" role="status" hidden></div>
		</div>
	{/fbvFormArea}

	{fbvFormButtons}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
