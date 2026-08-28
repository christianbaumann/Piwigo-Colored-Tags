<?php
defined('TYPETAGS_PATH') or die('Hacking attempt!');

define('TYPETAGS_TPL_TAG_ANCHOR', '<a href="{$tag.URL}">{$tag.name}</a>');
define('TYPETAGS_TPL_INJECT_POINT', '{if isset($metadata)}');

/**
 * triggered by 'render_tag_name'
 */
function typetags_render($tag_name, $tag=array())
{
  global $pwg_loaded_plugins, $page, $typetags_cache;

  if (defined('IN_ADMIN') and in_array($page['page'], array('photo', 'batch_manager', 'tags')))
  {
    return $tag_name;
  }

  if (isset($typetags_cache['tags'][$tag_name]))
  {
    return $typetags_cache['tags'][$tag_name];
  }

  if (!isset($typetags_cache['colors']))
  {
    $query = '
SELECT id, color
  FROM ' . TYPETAGS_TABLE . '
;';
    $typetags_cache['colors'] = query2array($query, 'id', 'color');
  }

  if (!isset($typetags_cache['color_of_tag']))
  {
    $typetags_cache['color_of_tag'] = array(
      'by_id' => array(),
      'by_name' => array(),
    );

    $query = '
SELECT
    t.id,
    t.name,
    color
  FROM ' . TYPETAGS_TABLE . ' AS tt
    INNER JOIN ' . TAGS_TABLE . ' AS t ON t.id_typetags = tt.id
;';
    $rows = query2array($query);
    foreach ($rows as $row)
    {
      $typetags_cache['color_of_tag']['by_id'][ $row['id'] ] = $row['color'];
      $typetags_cache['color_of_tag']['by_name'][ $row['name'] ] = $row['color'];
    }
  }

  if (!empty($tag['id_typetags']))
  {
    $color = $typetags_cache['colors'][ $tag['id_typetags'] ];
  }
  elseif (isset($tag['id']))
  {
    $color = $typetags_cache['color_of_tag']['by_id'][ $tag['id'] ] ?? null;
  }
  else
  {
    $color = $typetags_cache['color_of_tag']['by_name'][ $tag_name ] ?? null;
  }

  if ($color === null)
  {
    $ret = $tag_name;
  }
  else
  {
    $color_text = get_color_text($color);
    $style = 'background-color:' . $color . ';color:' . $color_text
      . ';padding:2px 8px;border-radius:12px;display:inline-block;';

    if (isset($pwg_loaded_plugins['ExtendedDescription']))
    {
      $ret = '[lang=all]<span style="' . $style . '">[/lang]' . $tag_name . '[lang=all]</span>[/lang]';
    }
    else
    {
      $ret = '<span style="' . $style . '">' . $tag_name . '</span>';
    }
  }

  $typetags_cache['tags'][$tag_name] = $ret;
  return $ret;
}

/**
 * colors tags on picture page
 */
/*function typetags_picture()
{
  global $template;

  $tags = $template->get_template_vars('related_tags');
  if (empty($tags)) return;

  $query = '
SELECT
    t.id ,
    tt.color
  FROM '.TYPETAGS_TABLE.' AS tt
    INNER JOIN '.TAGS_TABLE.' AS t
      ON t.id_typetags = tt.id
  WHERE t.id_typetags IS NOT NULL
;';
  $tagsColor = simple_hash_from_query($query, 'id', 'color');
  if (empty($tagsColor)) return;

  foreach ($tags as $key => $tag)
  {
    if (isset($tagsColor[ $tag['id'] ]))
    {
      $tags[$key]['URL'].= '" style="color:'.$tagsColor[ $tag['id'] ].';';
    }
  }

  $template->clear_assign('related_tags');
  $template->assign('related_tags', $tags);
}*/

/**
 * Prepare data for inline tag assignment on picture page
 * triggered by 'loc_end_picture'
 */
function typetags_picture_tags()
{
  global $template, $page;

  if (is_a_guest())
  {
    return;
  }

  $image_id = $page['image_id'];

  // Get IDs of tags already assigned to this image
  $query = '
SELECT tag_id
  FROM ' . IMAGE_TAG_TABLE . '
  WHERE image_id = ' . (int)$image_id . '
;';
  $assigned_ids = query2array($query, null, 'tag_id');

  // Get all colored tags with their colors
  $query = '
SELECT t.id, t.name, t.url_name, tt.color
  FROM ' . TAGS_TABLE . ' AS t
  INNER JOIN ' . TYPETAGS_TABLE . ' AS tt ON t.id_typetags = tt.id
  ORDER BY t.name
;';
  $all_colored = query2array($query);

  $partition = typetags_partition_tags($all_colored, $assigned_ids);
  $unassigned = $partition['unassigned'];
  $assigned_colored_ids = $partition['assigned_colored_ids'];

  $template->assign(array(
    'TYPETAGS_UNASSIGNED' => $unassigned,
    'TYPETAGS_ASSIGNED_COLORED_IDS' => $assigned_colored_ids,
    'TYPETAGS_IMAGE_ID' => $image_id,
    'TYPETAGS_PWG_TOKEN' => get_pwg_token(),
  ));

  $template->set_prefilter('picture', 'typetags_picture_prefilter');
}

function typetags_picture_prefilter($content)
{
  // 1. Add data-tag-id attribute to tag links in #Tags section
  $replace = '<a href="{$tag.URL}" data-tag-id="{$tag.id}">{$tag.name}</a>';
  $content = str_replace(TYPETAGS_TPL_TAG_ANCHOR, $replace, $content);

  // 2. Inject unassigned tags section after the info box </dl>
  $injection = '
{if isset($TYPETAGS_UNASSIGNED) && !empty($TYPETAGS_UNASSIGNED)}
<div id="typetags-unassigned" style="margin:8px 0;line-height:2.2;">
  {foreach from=$TYPETAGS_UNASSIGNED item=utag}
  <span class="typetag-badge typetag-add" data-tag-id="{$utag.id}" data-tag-name="{$utag.name|escape}" data-tag-color="{$utag.color}" data-tag-color-text="{$utag.color_text}" style="background-color:{$utag.color};color:{$utag.color_text};padding:2px 8px;border-radius:12px;display:inline-block;cursor:pointer;opacity:0.6;margin:2px;" title="{\'Add tag\'|@translate}">+ {$utag.name}</span>
  {/foreach}
</div>
{/if}
';

  $content = str_replace(TYPETAGS_TPL_INJECT_POINT, $injection . TYPETAGS_TPL_INJECT_POINT, $content);

  // 3. Inject JavaScript via footer_script
  $js = '
{if isset($TYPETAGS_IMAGE_ID)}
{footer_script require=\'jquery\'}
;(function() {ldelim}
  var imageId = {$TYPETAGS_IMAGE_ID};
  var pwgToken = "{$TYPETAGS_PWG_TOKEN}";
  var assignedColoredIds = [{foreach from=$TYPETAGS_ASSIGNED_COLORED_IDS item=tid name=tidloop}{$tid}{if !$smarty.foreach.tidloop.last},{/if}{/foreach}];

  // Add "x" buttons inside assigned colored tag badges
  jQuery("#Tags a[data-tag-id]").each(function() {ldelim}
    var tagId = parseInt(jQuery(this).data("tag-id"));
    if (assignedColoredIds.indexOf(tagId) !== -1) {ldelim}
      jQuery(this).find("span[style]").append(\' <span class="typetag-remove" data-tag-id="\' + tagId + \'" style="cursor:pointer;font-size:0.8em;" title="{\'Remove tag\'|@translate}">&times;</span>\');
    {rdelim}
  {rdelim});

  // Click: assign unassigned tag
  jQuery(document).on("click", ".typetag-add", function() {ldelim}
    var el = jQuery(this);
    var tagId = el.data("tag-id");
    var tagName = el.data("tag-name");
    var tagColor = el.data("tag-color");
    var tagColorText = el.data("tag-color-text");
    el.css("pointer-events", "none");

    jQuery.ajax({ldelim}
      url: "ws.php?format=json",
      type: "POST",
      data: {ldelim}
        method: "typetags.image.addTag",
        image_id: imageId,
        tag_id: tagId,
        pwg_token: pwgToken
      {rdelim},
      dataType: "json",
      success: function(data) {ldelim}
        if (data.stat === "ok") {ldelim}
          // Build assigned tag badge with "x" inside
          var style = "background-color:" + tagColor + ";color:" + tagColorText + ";padding:2px 8px;border-radius:12px;display:inline-block;";
          var removeBtn = \'<span class="typetag-remove" data-tag-id="\' + tagId + \'" style="cursor:pointer;font-size:0.8em;" title="{\'Remove tag\'|@translate}">&times;</span>\';
          var badge = \'<span style="\' + style + \'">\' + tagName + \' \' + removeBtn + \'</span>\';
          var link = \'<a href="#" data-tag-id="\' + tagId + \'">\' + badge + \'</a>\';

          var tagsDD = jQuery("#Tags dd");
          if (tagsDD.length === 0) {ldelim}
            // Tags section doesn\'t exist yet, create it
            var tagsDiv = \'<div id="Tags" class="imageInfo"><dt>{\'Tags\'|@translate}</dt><dd>\' + link + \'</dd></div>\';
            // Insert before Albums or at end of dl#standard
            var albums = jQuery("#Categories");
            if (albums.length) {ldelim}
              albums.before(tagsDiv);
            {rdelim} else {ldelim}
              jQuery("dl#standard").children().last().after(tagsDiv);
            {rdelim}
          {rdelim} else {ldelim}
            // Append to existing tags
            if (tagsDD.children().length > 0) {ldelim}
              tagsDD.append(", ");
            {rdelim}
            tagsDD.append(link);
            jQuery("#Tags").show();
          {rdelim}

          // Remove from unassigned list
          el.remove();
          assignedColoredIds.push(tagId);

          // Hide unassigned section if empty
          if (jQuery("#typetags-unassigned .typetag-add").length === 0) {ldelim}
            jQuery("#typetags-unassigned").hide();
          {rdelim}
        {rdelim} else {ldelim}
          // PwgError arrives as HTTP 200 + stat:"fail", so it lands here, not in error()
          el.css("pointer-events", "");
          if (window.console) { console.warn("typetags: " + (data.message || "request failed")); }
        {rdelim}
      {rdelim},
      error: function() {ldelim}
        el.css("pointer-events", "");
      {rdelim}
    {rdelim});
  {rdelim});

  // Click: remove assigned tag
  jQuery(document).on("click", ".typetag-remove", function(e) {ldelim}
    e.preventDefault();
    var el = jQuery(this);
    var tagId = el.data("tag-id");
    el.css("pointer-events", "none");

    jQuery.ajax({ldelim}
      url: "ws.php?format=json",
      type: "POST",
      data: {ldelim}
        method: "typetags.image.removeTag",
        image_id: imageId,
        tag_id: tagId,
        pwg_token: pwgToken
      {rdelim},
      dataType: "json",
      success: function(data) {ldelim}
        if (data.stat === "ok") {ldelim}
          // Find the tag link (x button is now inside it)
          var tagLink = el.closest("a[data-tag-id]");
          var tagName = "";
          var tagColor = "";
          var tagColorText = "";

          // Extract info from the badge span
          var badgeSpan = tagLink.find("span[style]").first();
          if (badgeSpan.length) {ldelim}
            tagName = badgeSpan.clone().children().remove().end().text().trim();
            var bgMatch = badgeSpan.attr("style").match(/background-color:\s*([^;]+)/);
            var clMatch = badgeSpan.attr("style").match(/(?:^|;)\s*color:\s*([^;]+)/);
            if (bgMatch) tagColor = bgMatch[1];
            if (clMatch) tagColorText = clMatch[1];
          {rdelim}

          // Remove separator (", " before or after)
          var prev = tagLink[0].previousSibling;
          var next = tagLink[0].nextSibling;
          if (next && next.nodeType === 3 && next.textContent.trim() === ",") {ldelim}
            next.remove();
          {rdelim} else if (prev && prev.nodeType === 3 && prev.textContent.match(/,\s*$/)) {ldelim}
            prev.textContent = prev.textContent.replace(/,\s*$/, "");
          {rdelim}

          tagLink.remove();

          // Remove from assigned list
          var idx = assignedColoredIds.indexOf(tagId);
          if (idx !== -1) assignedColoredIds.splice(idx, 1);

          // Add back to unassigned list
          if (tagName && tagColor) {ldelim}
            var addStyle = "background-color:" + tagColor + ";color:" + tagColorText + ";padding:2px 8px;border-radius:12px;display:inline-block;cursor:pointer;opacity:0.6;margin:2px;";
            var addBadge = \'<span class="typetag-badge typetag-add" data-tag-id="\' + tagId + \'" data-tag-name="\' + tagName + \'" data-tag-color="\' + tagColor + \'" data-tag-color-text="\' + tagColorText + \'" style="\' + addStyle + \'" title="{\'Add tag\'|@translate}">+ \' + tagName + \'</span>\';
            var container = jQuery("#typetags-unassigned");
            if (container.length === 0) {ldelim}
              container = jQuery(\'<div id="typetags-unassigned" style="margin:8px 0;line-height:2.2;"></div>\');
              jQuery("dl#standard").after(container);
            {rdelim}
            container.append(addBadge).show();
          {rdelim}

          // Hide Tags row if empty
          if (jQuery("#Tags dd").children("a").length === 0) {ldelim}
            jQuery("#Tags").hide();
          {rdelim}
        {rdelim} else {ldelim}
          // PwgError arrives as HTTP 200 + stat:"fail", so it lands here, not in error()
          el.css("pointer-events", "");
          if (window.console) { console.warn("typetags: " + (data.message || "request failed")); }
        {rdelim}
      {rdelim},
      error: function() {ldelim}
        el.css("pointer-events", "");
      {rdelim}
    {rdelim});
  {rdelim});

  // Hover effect on unassigned tags
  jQuery(document).on("mouseenter", ".typetag-add", function() {ldelim}
    jQuery(this).css("opacity", "1");
  {rdelim}).on("mouseleave", ".typetag-add", function() {ldelim}
    jQuery(this).css("opacity", "0.6");
  {rdelim});
{rdelim})();
{/footer_script}
{/if}
';

  // Only inject into the main picture template (prefilter runs on sub-templates too)
  if (strpos($content, TYPETAGS_TPL_INJECT_POINT) === false)
  {
    return $content;
  }

  $content .= $js;

  return $content;
}

/**
 * colors tags on tags page
 */
function typetags_tags()
{
  global $template, $page, $tags;

  if (empty($tags))
  {
    return;
  }

  $query = '
SELECT
    t.id,
    tt.color
  FROM ' . TYPETAGS_TABLE . ' AS tt
    INNER JOIN ' . TAGS_TABLE . ' AS t
    ON t.id_typetags = tt.id
  WHERE t.id_typetags IS NOT NULL
;';
  $tagsColor = query2array($query, 'id', 'color');

  if (empty($tagsColor))
  {
    return;
  }

  // LETTERS
  if ($page['display_mode'] == 'letters')
  {
    $letters = $template->get_template_vars('letters');

    foreach ($letters as &$letter)
    {
      foreach ($letter['tags'] as &$tag)
      {
        if (isset($tagsColor[ $tag['id'] ]))
        {
          $tag['URL'].= '" style="color:' . $tagsColor[ $tag['id'] ] . ';';
        }
      }
      unset($tag);
    }
    unset($letter);

    $template->assign('letters', $letters);
  }
  // CLOUD
  else if ($page['display_mode'] == 'cloud')
  {
    $tags = $template->get_template_vars('tags');

    foreach ($tags as &$tag)
    {
      if (isset($tagsColor[ $tag['id'] ]))
      {
        $tag['URL'].= '" style="color:' . $tagsColor[ $tag['id'] ] . ';';
      }
    }
    unset($tag);

    $template->assign('tags', $tags);
  }
  // CUMULUS
  else if ($page['display_mode'] == 'cumulus')
  {
    $tags = $template->get_template_vars('tags');

    foreach ($tags as &$tag)
    {
      if (isset($tagsColor[ $tag['id'] ]))
      {
        $tagsColor[ $tag['id'] ] = str_replace('#', '0x', $tagsColor[ $tag['id'] ]);
        $tag['URL'].= '\' color=\'' . $tagsColor[ $tag['id'] ] . '\' hicolor=\'' . $tagsColor[ $tag['id'] ];
      }
    }
    unset($tag);

    $template->assign('tags', $tags);
  }
}

function typetags_escape()
{
  global $template;
  $template->set_prefilter('header', 'typetags_escape_prefilter');
}
function typetags_escape_prefilter($content)
{
  $search = '{$tag.name}';
  $replace = '{$tag.name|strip_tags}';
  return str_replace($search, $replace, $content);
}
