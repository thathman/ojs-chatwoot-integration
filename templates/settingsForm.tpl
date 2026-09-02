{**
 * plugins/generic/chatwootIntegration/templates/settingsForm.tpl
 *
 * Chatwoot Integration plugin settings
 *}
<script>
	$(function() {ldelim}
		$('#chatwootIntegrationSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		function cwPost(url, data) {ldelim}
			return $.ajax({ldelim}
				url: url,
				type: 'POST',
				data: $.extend({ldelim}csrfToken: $('input[name="csrfToken"]').val(){rdelim}, data || {ldelim}{rdelim}),
			{rdelim});
		{rdelim}

		$('#chatwootHealthCheckBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$healthCheckUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(JSON.stringify(resp.content || resp, null, 2));
			{rdelim});
		{rdelim});

		$('#chatwootTestMessageBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$testMessageUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert((resp.content || 'Done'));
			{rdelim});
		{rdelim});

		$('#chatwootExportBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$exportSettingsUrl|escape:"javascript"}').done(function(resp) {ldelim}
				$('#chatwootImportExport').val(JSON.stringify(resp.content || {ldelim}{rdelim}, null, 2));
			{rdelim});
		{rdelim});

		$('#chatwootImportBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$importSettingsUrl|escape:"javascript"}', {ldelim}importPayload: $('#chatwootImportExport').val(){rdelim}).done(function(resp) {ldelim}
				alert(resp.content || 'Imported');
			{rdelim});
		{rdelim});

		$('#chatwootSaveGlobalBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$saveGlobalProfileUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(resp.content || 'Saved');
			{rdelim});
		{rdelim});

		$('#chatwootApplyGlobalBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$applyGlobalProfileUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(resp.content || 'Applied');
			{rdelim});
		{rdelim});

		$('#chatwootSyncCaptainBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$syncCaptainResourcesUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(JSON.stringify(resp.content || resp, null, 2));
			{rdelim});
		{rdelim});

		$('#chatwootRetryDeadLettersBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$retryDeadLetterEventsUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(JSON.stringify(resp.content || resp, null, 2));
			{rdelim});
		{rdelim});

		$('#chatwootSendMailTestBtn').on('click', function(e) {ldelim}
			e.preventDefault();
			cwPost('{$sendSupportMailTestUrl|escape:"javascript"}').done(function(resp) {ldelim}
				alert(resp.content || 'Done');
			{rdelim});
		{rdelim});
	{rdelim});
</script>

<form class="pkp_form" id="chatwootIntegrationSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="chatwootIntegrationSettingsFormNotification"}

	<div id="description">{translate key="plugins.generic.chatwootIntegration.description"}</div>
	<h3>{translate key="plugins.generic.chatwootIntegration.settings"}</h3>

	{fbvFormArea id="chatwootIntegrationSettingsFormArea"}
		{if $supportGatewayHealth}
			<h4>{translate key="plugins.generic.chatwootIntegration.settings.section.health"}</h4>
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
				</ul>
			</div>
			<div id="description">
				{translate key="plugins.generic.chatwootIntegration.settings.health.syncCaptainDescription"}
			</div>
			{fbvElement type="button" id="chatwootSyncCaptainBtn" label="plugins.generic.chatwootIntegration.settings.health.syncCaptain"}
			{if $supportGatewayHealth.deadLetterCount > 0}
				<div id="description">
					{translate key="plugins.generic.chatwootIntegration.settings.health.retryDeadLettersDescription"}
				</div>
				{fbvElement type="button" id="chatwootRetryDeadLettersBtn" label="plugins.generic.chatwootIntegration.settings.health.retryDeadLetters"}
			{/if}
			<div id="description">
				{translate key="plugins.generic.chatwootIntegration.settings.health.sendMailTestDescription"}
			</div>
			{fbvElement type="button" id="chatwootSendMailTestBtn" label="plugins.generic.chatwootIntegration.settings.health.sendMailTest"}
		{/if}

		<h4>{translate key="plugins.generic.chatwootIntegration.settings.section.connection"}</h4>
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

		{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId"}
			{fbvElement type="text" id="chatwootCaptainAssistantId" value=$chatwootCaptainAssistantId label="plugins.generic.chatwootIntegration.settings.chatwootCaptainAssistantId.description"}
		{/fbvFormSection}

		<h4>{translate key="plugins.generic.chatwootIntegration.settings.section.supportApi"}</h4>
		{fbvFormSection title="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken"}
			{fbvElement type="text" password=true id="chatwootSupportApiToken" value=$chatwootSupportApiToken label="plugins.generic.chatwootIntegration.settings.chatwootSupportApiToken.description"}
		{/fbvFormSection}

		<h4>{translate key="plugins.generic.chatwootIntegration.settings.section.mcp"}</h4>
		<div id="description">
			{translate key="plugins.generic.chatwootIntegration.settings.mcpEndpoint.description" endpoint=$mcpEndpointUrl revision=$mcpProtocolRevision}
		</div>
		{fbvFormSection title="plugins.generic.chatwootIntegration.settings.mcpServiceToken"}
			{fbvElement type="text" password=true id="mcpServiceToken" value=$mcpServiceToken label="plugins.generic.chatwootIntegration.settings.mcpServiceToken.description"}
		{/fbvFormSection}

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
			{fbvElement type="checkbox" id="enableDebugMode" value="1" checked=$enableDebugMode label="plugins.generic.chatwootIntegration.settings.enableDebugMode"}
		{/fbvFormSection}

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

		<h4>{translate key="plugins.generic.chatwootIntegration.settings.section.eventBridge"}</h4>
		<div id="description">{translate key="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.description"}</div>
		{fbvFormSection title="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode"}
			{fbvElement type="select" id="eventDeliveryGlobalMode" from=$eventDeliveryGlobalModeOptions selected=$eventDeliveryGlobalMode label="plugins.generic.chatwootIntegration.settings.eventDeliveryGlobalMode.help" translate=false}
		{/fbvFormSection}
		{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.eventDeliveryCustomerMessageConsent"}
			{fbvElement type="checkbox" id="eventDeliveryCustomerMessageConsent" value="1" checked=$eventDeliveryCustomerMessageConsent label="plugins.generic.chatwootIntegration.settings.eventDeliveryCustomerMessageConsent.description"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.chatwootIntegration.settings.eventDeliveryPerEventOverridesJson"}
			{fbvElement type="textarea" id="eventDeliveryPerEventOverridesJson" value=$eventDeliveryPerEventOverridesJson label="plugins.generic.chatwootIntegration.settings.eventDeliveryPerEventOverridesJson.description"}
		{/fbvFormSection}

		{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.performance"}
			{fbvElement type="checkbox" id="lazyLoadWidget" value="1" checked=$lazyLoadWidget label="plugins.generic.chatwootIntegration.settings.lazyLoadWidget"}
			{fbvElement type="select" id="lazyLoadTrigger" from=$lazyLoadTriggerOptions selected=$lazyLoadTrigger label="plugins.generic.chatwootIntegration.settings.lazyLoadTrigger" translate=false}
			{fbvElement type="text" id="excludedPages" value=$excludedPages label="plugins.generic.chatwootIntegration.settings.excludedPages"}
			{fbvElement type="checkbox" id="skipBackendPages" value="1" checked=$skipBackendPages label="plugins.generic.chatwootIntegration.settings.skipBackendPages"}
			{fbvElement type="checkbox" id="cspSafeMode" value="1" checked=$cspSafeMode label="plugins.generic.chatwootIntegration.settings.cspSafeMode"}
			{fbvElement type="textarea" id="widgetSettingsJson" value=$widgetSettingsJson label="plugins.generic.chatwootIntegration.settings.widgetSettingsJson"}
		{/fbvFormSection}

		{fbvFormSection list=true title="plugins.generic.chatwootIntegration.settings.adminTools"}
			{fbvElement type="checkbox" id="enableGlobalDefaults" value="1" checked=$enableGlobalDefaults label="plugins.generic.chatwootIntegration.settings.enableGlobalDefaults"}
			{fbvElement type="button" id="chatwootHealthCheckBtn" label="plugins.generic.chatwootIntegration.settings.healthCheck"}
			{fbvElement type="button" id="chatwootTestMessageBtn" label="plugins.generic.chatwootIntegration.settings.testMessage"}
			{fbvElement type="button" id="chatwootExportBtn" label="plugins.generic.chatwootIntegration.settings.exportSettings"}
			{fbvElement type="button" id="chatwootImportBtn" label="plugins.generic.chatwootIntegration.settings.importSettings"}
			{fbvElement type="button" id="chatwootSaveGlobalBtn" label="plugins.generic.chatwootIntegration.settings.saveGlobalProfile"}
			{fbvElement type="button" id="chatwootApplyGlobalBtn" label="plugins.generic.chatwootIntegration.settings.applyGlobalProfile"}
			{fbvElement type="textarea" id="chatwootImportExport" value="" label="plugins.generic.chatwootIntegration.settings.importExportPayload"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
