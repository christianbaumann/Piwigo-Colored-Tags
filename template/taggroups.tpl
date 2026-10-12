{html_style}
#typetags-groups { padding:0 16px; }
#typetags-groups .typetags-group { margin:0 0 16px; }
#typetags-groups .typetags-group-title { display:flex; align-items:center; gap:8px; margin:0 0 6px; }
#typetags-groups .typetags-swatch { display:inline-block; width:20px; height:20px; border-radius:20px; }
#typetags-groups .typetags-group-tags { list-style:none; margin:0; padding:0; line-height:2.2; }
#typetags-groups .typetags-group-tags li { display:inline-block; margin:2px; }
#typetags-groups .typetags-group-tags a { text-decoration:none; }
{/html_style}
{if isset($MENUBAR)}{$MENUBAR}{/if}
<div id="content" class="content{if isset($MENUBAR)} contentWithMenu{/if}">

<div class="titrePage">
	<h2><a href="{$U_HOME}">{'Home'|@translate}</a>{$LEVEL_SEPARATOR}<a href="{$TYPETAGS_U_TAGS}">{'Tags'|@translate}</a>{$LEVEL_SEPARATOR}{'Tag groups'|@translate}</h2>
</div>

{include file='infos_errors.tpl'}

<div id="typetags-groups">
{foreach from=$TYPETAGS_GROUPS item=group}
	<div class="typetags-group" data-group-id="{$group.id}" data-color="{$group.color}" data-striped="{if $group.striped}1{else}0{/if}">
		<h3 class="typetags-group-title">
			<span class="typetags-swatch" style="background:{$group.swatch};"></span>
			{if $group.emoji_html}<span class="typetag-emoji">{$group.emoji_html}</span>{/if}
			<span class="typetags-group-name">{$group.name|@escape}</span>
			<span class="typetags-group-color">{$group.color}{if $group.striped}, {'Striped'|@translate}{/if}</span>
		</h3>
{if empty($group.tags)}
		<p class="typetags-group-empty">{'No visible tag'|@translate}</p>
{else}
		<ul class="typetags-group-tags">
{foreach from=$group.tags item=tag}
			<li><a href="{$tag.URL}" data-tag-id="{$tag.id}"><span class="typetag-badge" style="{$group.badge_style}">{$tag.label}</span></a></li>
{/foreach}
		</ul>
{/if}
	</div>
{/foreach}
</div>

</div> <!-- content -->
