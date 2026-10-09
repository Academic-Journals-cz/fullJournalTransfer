{**
 * plugins/importexport/fullJournalTransfer/templates/index.tpl
 *
 * Copyright (c) 2014-2024 Lepidus Tecnologia
 * Copyright (c) 2025-2026 academic-journals-cz
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Displays the message that the plugin is only usable from the command line.
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
	<h1 class="app__pageHeading">
		{$pageTitle|escape}
	</h1>

	<div class="app__contentPanel">
		<strong>{translate key="plugins.importexport.fullJournalTransfer.attention"}</strong>
		<p>
			{translate key="plugins.importexport.fullJournalTransfer.guiAttentionMessage"}
		</p>
	</div>
{/block}
