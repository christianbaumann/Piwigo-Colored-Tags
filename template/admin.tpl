{combine_script id="farbtastic" require="jquery" path=$TYPETAGS_PATH|cat:"template/farbtastic/farbtastic.js"}
{combine_css path=$TYPETAGS_PATH|cat:"template/farbtastic/farbtastic.css"}
{combine_css path=$TYPETAGS_PATH|cat:"template/style.css"}

{footer_script}
// init colorpicker
jQuery('#colorpicker').farbtastic('#hexval');
{/footer_script}

<div class="titrePage">
  <h2>Colored Tags</h2>
</div>

<form action="{$F_ACTION}" method="post" name="form">
  <fieldset>
  {if isset($IN_EDIT)}
    <legend>{'Edit color'|translate}</legend>
    <div class="edit-container">
      <div id="colorpicker"></div>
      <p><b>{'Edit color'|translate} : <input type="text" readonly="readonly" size="18" style="{$typetag.OLD_COLORS}{if $typetag.OLD_STRIPED}padding-left:24px;{/if}" value="{if $typetag.OLD_EMOJI}{$typetag.OLD_EMOJI} {/if}{$typetag.OLD_NAME}"></b></p>
      <p>&nbsp;</p>
      <p>{'New name'|translate} : <input type="text" size="18" name="typetag_name" value="{$typetag.NAME}"></p>
      <p>{'New color'|translate} : <input type="text" id="hexval" name="typetag_color" size="7" maxlength="7" value="{$typetag.COLOR}"></p>
      <p><label><input type="checkbox" name="typetag_striped" value="1"{if $typetag.STRIPED} checked="checked"{/if}> {'Striped'|translate}</label> <i>{'tab striped in the color and white, at the left edge'|translate}</i></p>
      <p>{'Emoji'|translate} : <input type="text" size="18" name="typetag_emoji" value="{$typetag.EMOJI}"> <i>{'paste an emoji or type code points, e.g. 1F5BC FE0F; empty for none'|translate}</i></p>
      <p>&nbsp;</p>
      <p>
        <input type="hidden" name="edited_typetag" value="{$edited_typetag}">
        <input class="submit" type="submit" name="edittypetag" value="{'Save'|translate}">
        <input class="submit" type="submit" name="cancel" value="{'Reset'|translate}">
      </p>
    </div>
  {else}
    <legend>{'Add a new color'|translate}</legend>
    <div class="edit-container">
      <div id="colorpicker"></div>
      <p>&nbsp;</p>
      <p>{'Name'|translate} : <input type="text" size="18" name="typetag_name" value="{if isset($typetag.NAME)}{$typetag.NAME}{/if}"></p>
      <p>{'Color'|translate} : <input type="text" id="hexval" name="typetag_color" size="7" maxlength="7" value="{if isset($typetag.COLOR)}{$typetag.COLOR}{else}#444444{/if}"></p>
      <p><label><input type="checkbox" name="typetag_striped" value="1"{if !empty($typetag.STRIPED)} checked="checked"{/if}> {'Striped'|translate}</label> <i>{'tab striped in the color and white, at the left edge'|translate}</i></p>
      <p>{'Emoji'|translate} : <input type="text" size="18" name="typetag_emoji" value="{if isset($typetag.EMOJI)}{$typetag.EMOJI}{/if}"> <i>{'paste an emoji or type code points, e.g. 1F5BC FE0F; empty for none'|translate}</i></p>
      <p>&nbsp;</p>
      <p>
        <input class="submit" type="submit" name="addtypetag" value="{'Add'|translate}">
      </p>
    </div>
  {/if}
  </fieldset>
</form>

{if !empty($typetags)}
<fieldset>
  <legend>{'Manage colors'|translate}</legend>

  <ul class="tagSelection typetagSelection">
  {foreach from=$typetags item=typetag}
    <li style="{$typetag.colors}{if $typetag.striped}padding-left:24px;{/if}">
      <span class="buttons">
        <a href="{$typetag.u_edit}" title="{'edit'|translate}"><img src="{$ROOT_URL}{$themeconf.admin_icon_dir}/edit_s.png" class="button" alt="{'edit'|translate}"></a>
        <a href="{$typetag.u_delete}" title="{'delete'|translate}" onclick="return confirm('{'Are you sure?'|translate}');"><img src="{$ROOT_URL}{$themeconf.admin_icon_dir}/delete.png" class="button" alt="{'delete'|translate}"></a>
      </span>
      {$typetag.label}
    </li>
  {/foreach}
  </ul>

  <p style="text-align:left;">{'Go to <a href="%s">Photos/Tags</a> to manage associations.'|translate:($ROOT_URL|cat:'admin.php?page=tags')}</p>
</fieldset>
{/if}


<form action="{$F_ACTION}" method="post" name="form">
  <fieldset>
    <legend>{'Configuration'|translate}</legend>

    <b>{'Display colored tags'|translate}</b>
    <label><input type="radio" name="show_all" value="false" {if not $SHOW_ALL}checked="checked"{/if}> {'Only on tags page'|translate}</label>
    <label><input type="radio" name="show_all" value="true" {if $SHOW_ALL}checked="checked"{/if}> {'Everywhere'|translate}</label>

    <p><input class="submit" type="submit" name="save_config" value="{'Submit'|translate}"></p>
  </fieldset>
</form>
