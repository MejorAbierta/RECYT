
<div id="customHeaderSettings">
<div id="description">
{translate key="plugins.generic.calidadfecyt.description"}
</div>

<div class="separator"></div>
<br />
{if $exportAllAction}

	<p class="pkpHeader__title">
		<legend>{translate key="plugins.generic.calidadfecyt.export.all"}</legend>
	</p>

	<fieldset class="pkpFormField pkpFormField--options">
		<legend>
			{translate key="plugins.generic.calidadfecyt.exportAll.description"}
		</legend>
		<button id="exportAllButton" class="pkpButton">
			{translate key="plugins.generic.calidadfecyt.exportAll"}
		</button>

		{include file="linkAction/buttonGenericLinkAction.tpl" buttonSelector="#exportAllButton" action=$exportAllAction}
	</fieldset>
{/if}

<div class="separator"></div>
<br />

<p class="pkpHeader__title">
	<legend>{translate key="plugins.generic.calidadfecyt.export.single"}</legend>
</p>

{if $linkActions}
	{foreach from=$linkActions item=exportAction}
		<fieldset class="pkpFormField pkpFormField--options">
			<legend>
				{translate key="plugins.generic.calidadfecyt.export."|cat:$exportAction->name|cat:".description"}
			</legend>
			<button id="{$exportAction->name|cat:'Button'}" class="pkpButton">
				{translate key="plugins.generic.calidadfecyt.export."|cat:$exportAction->name}
			</button>

			{include file="linkAction/buttonGenericLinkAction.tpl" buttonSelector="#{$exportAction->name|cat:'Button'}" action=$exportAction->linkAction}
		</fieldset>
	{/foreach}
{/if}

<div class="separator"></div>
<br />

<p class="pkpHeader__title">
	<legend>{translate key="plugins.generic.calidadfecyt.export.editorial"}</legend>
</p>

<fieldset class="pkpFormField pkpFormField--options">
	<legend>
		{translate key="plugins.generic.calidadfecyt.export.editorial.description"}
	</legend>

	<form action="{$editorialUrl|escape}" method="get" id="editorialForm">
		<input type="hidden" name="verb" value="editorial">
		<input type="hidden" name="plugin" value="CalidadFECYTPlugin">
		<input type="hidden" name="category" value="generic">
		<select name="submission" id="submission" style="width: 90%; margin-bottom: 10px">
			{foreach from=$submissions item=submission}
				<option value="{$submission['id']}">{$submission['id']} - {$submission['title']}</option>
			{/foreach}
		</select>

		</br>

		<button id="editorialButton" type="submit" class="pkpButton">
			{translate key="plugins.generic.calidadfecyt.export.editorial"}
		</button>
	</form>
</fieldset>
</div>