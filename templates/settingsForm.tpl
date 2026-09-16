{**
 * plugins/generic/requiredAuthorMetadata/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * What the journal requires of its contributors, and who is left out of it.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#requiredAuthorMetadataSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="requiredAuthorMetadataSettingsForm"
	method="POST"
	action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}"
>
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="requiredAuthorMetadataSettingsNotification"}

	<p class="pkp_help">{translate key="plugins.generic.requiredAuthorMetadata.settings.intro"}</p>

	{fbvFormArea id="requiredAuthorMetadataFields" title="plugins.generic.requiredAuthorMetadata.settings.area.fields"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="requireFamilyName" name="requireFamilyName" checked=$requireFamilyName label="plugins.generic.requiredAuthorMetadata.settings.requireFamilyName"}
			{fbvElement type="checkbox" id="requireAffiliation" name="requireAffiliation" checked=$requireAffiliation label="plugins.generic.requiredAuthorMetadata.settings.requireAffiliation"}
			{fbvElement type="checkbox" id="requireBiography" name="requireBiography" checked=$requireBiography label="plugins.generic.requiredAuthorMetadata.settings.requireBiography"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="requiredAuthorMetadataEnforcement" title="plugins.generic.requiredAuthorMetadata.settings.area.enforcement" description="plugins.generic.requiredAuthorMetadata.settings.area.enforcement.description"}
		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="requireOnSubmit" name="requireOnSubmit" checked=$requireOnSubmit label="plugins.generic.requiredAuthorMetadata.settings.requireOnSubmit"}
			{fbvElement type="checkbox" id="editorsExempt" name="editorsExempt" checked=$editorsExempt label="plugins.generic.requiredAuthorMetadata.settings.editorsExempt"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons submitText="common.save"}
</form>
