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

		cwWireAction('chatwootHealthCheckBtn', function() {ldelim}
			return cwPost('{$healthCheckUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return JSON.stringify(resp.content || resp, null, 2);
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

		cwWireAction('chatwootSyncCaptainBtn', function() {ldelim}
			return cwPost('{$syncCaptainResourcesUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return JSON.stringify(resp.content || resp, null, 2);
		{rdelim});

		cwWireAction('chatwootRetryDeadLettersBtn', function() {ldelim}
			return cwPost('{$retryDeadLetterEventsUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return JSON.stringify(resp.content || resp, null, 2);
		{rdelim});

		cwWireAction('chatwootSendMailTestBtn', function() {ldelim}
			return cwPost('{$sendSupportMailTestUrl|escape:"javascript"}');
		{rdelim}, function(resp) {ldelim}
			return resp.content || 'Done.';
		{rdelim});
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
		<button type="button" role="tab" id="cwTab-aiKnowledge" aria-controls="cwPanel-aiKnowledge" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.aiKnowledge"}</button>
		<button type="button" role="tab" id="cwTab-apiMcp" aria-controls="cwPanel-apiMcp" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.apiMcp"}</button>
		<button type="button" role="tab" id="cwTab-advanced" aria-controls="cwPanel-advanced" aria-selected="false" tabindex="-1">{translate key="plugins.generic.chatwootIntegration.settings.tab.advanced"}</button>
	</div>

	{fbvFormArea id="chatwootIntegrationSettingsFormArea"}

		{* ================================================================ *}
		{* Overview: real system status only — never placebo/decorative.    *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-overview" aria-labelledby="cwTab-overview">
			{if $supportGatewayHealth}
				<div id="chatwootSupportGatewayHealth" class="pkp_notification pkp_notification_{if $supportGatewayHealth.overallState == 'healthy'}success{elseif $supportGatewayHealth.overallState == 'degraded'}warning{else}error{/if}">
					<ul>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.overallState"} <strong>{$supportGatewayHealth.overallState|escape}</strong></li>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.chatwootConfigured"} {if $supportGatewayHealth.chatwootConfigured}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</li>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.supportApiConfigured"} {if $supportGatewayHealth.supportApiConfigured}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</li>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.mcpConfigured"} {if $supportGatewayHealth.mcpConfigured}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</li>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.verificationConfigured"} {if $supportGatewayHealth.verificationConfigured}{translate key="common.yes"}{else}{translate key="common.no"}{/if}</li>
						{if $supportGatewayHealth.knowledgeState}<li>{translate key="plugins.generic.chatwootIntegration.settings.health.knowledgeState"} {$supportGatewayHealth.knowledgeState|escape}</li>{/if}
						{if $supportGatewayHealth.captainState}<li>{translate key="plugins.generic.chatwootIntegration.settings.health.captainState"} {$supportGatewayHealth.captainState|escape}</li>{/if}
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.pendingEventCount"} {$supportGatewayHealth.pendingEventCount|escape}</li>
						<li>{translate key="plugins.generic.chatwootIntegration.settings.health.deadLetterCount"} {$supportGatewayHealth.deadLetterCount|escape}</li>
						{if $supportGatewayHealth.queueHealth}
							<li>{translate key="plugins.generic.chatwootIntegration.settings.health.retryingEventCount"} {$supportGatewayHealth.queueHealth.retryingCount|escape}</li>
							{if $supportGatewayHealth.queueHealth.oldestPendingAgeSeconds !== null}
								<li>{translate key="plugins.generic.chatwootIntegration.settings.health.oldestPendingAge"} {$supportGatewayHealth.queueHealth.oldestPendingAgeSeconds|escape}s</li>
							{/if}
							{foreach from=$supportGatewayHealth.queueHealth.deadLetterErrorCodes key=errorCode item=errorCodeCount}
								<li>{translate key="plugins.generic.chatwootIntegration.settings.health.deadLetterErrorCode" errorCode=$errorCode|escape count=$errorCodeCount|escape}</li>
							{/foreach}
						{/if}
					</ul>
				</div>
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

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootInboxId"}
				{fbvElement type="text" id="chatwootInboxId" value=$chatwootInboxId label="plugins.generic.chatwootIntegration.settings.chatwootInboxId.description"}
			{/fbvFormSection}
		</div>

		{* ================================================================ *}
		{* Widget: visibility/appearance — SettingsRegistry tab "widget".   *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-widget" aria-labelledby="cwTab-widget" hidden>
			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.visibility"}
				{fbvElement type="checkbox" id="enableWidget" value="1" checked=$enableWidget label="plugins.generic.chatwootIntegration.settings.enableWidget"}
				{fbvElement type="checkbox" id="enablePrivacyMode" value="1" checked=$enablePrivacyMode label="plugins.generic.chatwootIntegration.settings.enablePrivacyMode"}
				{fbvElement type="checkbox" id="hideForGuests" value="1" checked=$hideForGuests label="plugins.generic.chatwootIntegration.settings.hideForGuests"}
				{fbvElement type="checkbox" id="hideForRole_1" value="1" checked=$hideForRole_1 label="plugins.generic.chatwootIntegration.settings.hideForRole_SiteAdmin"}
				{fbvElement type="checkbox" id="hideForRole_16" value="1" checked=$hideForRole_16 label="plugins.generic.chatwootIntegration.settings.hideForRole_Manager"}
				{fbvElement type="checkbox" id="hideForRole_17" value="1" checked=$hideForRole_17 label="plugins.generic.chatwootIntegration.settings.hideForRole_SubEditor"}
				{fbvElement type="checkbox" id="hideForRole_4097" value="1" checked=$hideForRole_4097 label="plugins.generic.chatwootIntegration.settings.hideForRole_Assistant"}
				{fbvElement type="checkbox" id="hideForRole_65536" value="1" checked=$hideForRole_65536 label="plugins.generic.chatwootIntegration.settings.hideForRole_Author"}
				{fbvElement type="checkbox" id="hideForRole_4096" value="1" checked=$hideForRole_4096 label="plugins.generic.chatwootIntegration.settings.hideForRole_Reviewer"}
				{fbvElement type="checkbox" id="hideForRole_1048576" value="1" checked=$hideForRole_1048576 label="plugins.generic.chatwootIntegration.settings.hideForRole_Reader"}
			{/fbvFormSection}

			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.widgetSettingsJson"}
				{fbvElement type="textarea" id="widgetSettingsJson" value=$widgetSettingsJson label="plugins.generic.chatwootIntegration.settings.widgetSettingsJson"}
			{/fbvFormSection}
		</div>

		{* ================================================================ *}
		{* Automation: Event Bridge — SettingsRegistry tab "automation".    *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-automation" aria-labelledby="cwTab-automation" hidden>
			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.workflowAutomation"}
				{fbvElement type="checkbox" id="retryQueueEnabled" value="1" checked=$retryQueueEnabled label="plugins.generic.chatwootIntegration.settings.retryQueueEnabled"}
				{fbvElement type="text" id="maxRetryAttempts" value=$maxRetryAttempts label="plugins.generic.chatwootIntegration.settings.maxRetryAttempts"}
				{fbvElement type="select" id="eventSyncMode" from=$eventSyncModeOptions selected=$eventSyncMode label="plugins.generic.chatwootIntegration.settings.eventSyncMode" translate=false}
				{fbvElement type="checkbox" id="eventSubmissionCreated" value="1" checked=$eventSubmissionCreated label="plugins.generic.chatwootIntegration.settings.eventSubmissionCreated"}
				{fbvElement type="checkbox" id="eventRevisionRequested" value="1" checked=$eventRevisionRequested label="plugins.generic.chatwootIntegration.settings.eventRevisionRequested"}
				{fbvElement type="checkbox" id="eventAccepted" value="1" checked=$eventAccepted label="plugins.generic.chatwootIntegration.settings.eventAccepted"}
				{fbvElement type="checkbox" id="eventRejected" value="1" checked=$eventRejected label="plugins.generic.chatwootIntegration.settings.eventRejected"}
				{fbvElement type="checkbox" id="eventPublicationScheduled" value="1" checked=$eventPublicationScheduled label="plugins.generic.chatwootIntegration.settings.eventPublicationScheduled"}
				{fbvElement type="checkbox" id="eventPublicationPublished" value="1" checked=$eventPublicationPublished label="plugins.generic.chatwootIntegration.settings.eventPublicationPublished"}
				{fbvElement type="checkbox" id="eventDecisionRecorded" value="1" checked=$eventDecisionRecorded label="plugins.generic.chatwootIntegration.settings.eventDecisionRecorded"}
			{/fbvFormSection}

			<div class="cwSectionDescription">{translate key="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.description"}</div>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode"}
				{fbvElement type="select" id="eventDeliveryGlobalMode" from=$eventDeliveryGlobalModeOptions selected=$eventDeliveryGlobalMode label="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.help" translate=false}
			{/fbvFormSection}
			{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.eventDeliveryCustomerMessageConsent"}
				{fbvElement type="checkbox" id="eventDeliveryCustomerMessageConsent" value="1" checked=$eventDeliveryCustomerMessageConsent label="plugins.generic.chatwootIntegration.settings.eventDeliveryCustomerMessageConsent.description"}
			{/fbvFormSection}
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.eventDeliveryPerEventOverridesJson"}
				{fbvElement type="textarea" id="eventDeliveryPerEventOverridesJson" value=$eventDeliveryPerEventOverridesJson label="plugins.generic.chatwootIntegration.settings.eventDeliveryPerEventOverridesJson.description"}
			{/fbvFormSection}

			{if $supportGatewayHealth.deadLetterCount > 0}
				<div class="cwSectionDescription">
					{translate key="plugins.generic.chatwootIntegration.settings.health.retryDeadLettersDescription"}
				</div>
				{fbvElement type="button" id="chatwootRetryDeadLettersBtn" label="plugins.generic.chatwootIntegration.settings.health.retryDeadLetters"}
				<div class="cwActionStatus" id="chatwootRetryDeadLettersBtnStatus" role="status" hidden></div>
			{/if}
		</div>

		{* ================================================================ *}
		{* AI & Knowledge — SettingsRegistry tab "ai_knowledge".            *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-aiKnowledge" aria-labelledby="cwTab-aiKnowledge" hidden>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId"}
				{fbvElement type="text" id="chatwootCaptainAssistantId" value=$chatwootCaptainAssistantId label="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId.description"}
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
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken"}
				{fbvElement type="text" password=true id="chatwootSupportApiToken" value=$chatwootSupportApiToken label="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken.description"}
			{/fbvFormSection}
			<div class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.mcpEndpoint.description" endpoint=$mcpEndpointUrl revision=$mcpProtocolRevision}
			</div>
			{fbvFormSection title="plugins.generic.chatwootIntegration.settings.mcpServiceToken"}
				{fbvElement type="text" password=true id="mcpServiceToken" value=$mcpServiceToken label="plugins.generic.chatwootIntegration.settings.mcpServiceToken.description"}
			{/fbvFormSection}
		</div>

		{* ================================================================ *}
		{* Advanced — SettingsRegistry tab "advanced", plus power-user      *}
		{* migration controls (export/import/global profile) and           *}
		{* troubleshooting (debug mode, mail test).                        *}
		{* ================================================================ *}
		<div role="tabpanel" id="cwPanel-advanced" aria-labelledby="cwTab-advanced" hidden>
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
			<div class="cwSectionDescription">
				{translate key="plugins.generic.chatwootIntegration.settings.health.sendMailTestDescription"}
			</div>
			{fbvElement type="button" id="chatwootSendMailTestBtn" label="plugins.generic.chatwootIntegration.settings.health.sendMailTest"}
			<div class="cwActionStatus" id="chatwootSendMailTestBtnStatus" role="status" hidden></div>

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
