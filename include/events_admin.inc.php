<?php
defined('TYPETAGS_PATH') or die('Hacking attempt!');

/**
 * tags page
 */
function typetags_admin()
{
  global $template, $page;

  if ($page['page'] != 'tags')
  {
    return;
  }

  include_once(TYPETAGS_PATH . 'include/functions.inc.php');

  load_language('plugin.lang', TYPETAGS_PATH);

  // add tag colors to template
  $query = 'SELECT * FROM ' . TYPETAGS_TABLE . ' ORDER BY name;';
  $result = pwg_query($query);

  while ($row = pwg_db_fetch_assoc($result))
  {
    $row['color_text'] = typetags_text_color($row['color'], $row['striped']);
    $row['swatch'] = typetags_swatch($row['color'], $row['striped']);
    $row['emoji_html'] = typetags_emoji_html($row['emoji']);
    $template->append('typetags', $row);
  }

  $query = '
SELECT
    t.id,
    id_typetags,
    color,
    striped
  FROM ' . TAGS_TABLE . ' AS t
    LEFT JOIN ' . TYPETAGS_TABLE . ' AS tt
    ON t.id_typetags = tt.id
;';
  $tags_color = query2array($query, 'id');
  foreach ($tags_color as &$tag_color)
  {
    $tag_color['swatch'] = $tag_color['color'] === null ? null : typetags_swatch($tag_color['color'], $tag_color['striped']);
    unset($tag_color['striped']);
  }
  unset($tag_color);
  $template->assign('tags_color', $tags_color);

  $template->append(
    'tag_manager_plugin_actions',
    array(
      'ID' => 'typetags',
      'NAME' => l10n('Set tags color')
      )
    );

  $template->assign('TYPETAGS_PATH', TYPETAGS_PATH);
  $template->set_prefilter('tags', 'typetags_admin_prefilter');
}

function typetags_admin_prefilter($content)
{
  // add form part
  $search[0] = '<div class="tag-pagination">';
  $replace[0] = file_get_contents(realpath(TYPETAGS_PATH . 'template/tags.tpl')) . $search[0];

  // add button
  $search[1] = '<button id="DeleteSelectionMode"';
  $replace[1] = '<button id="TypetagsChangeColor" class="icon-brush">{"Couleur"|translate}</button>'.$search[1];

  return str_replace($search, $replace, $content);
}

/**
 * Inject per-tag color CSS on admin photo edit and batch manager pages
 */
function typetags_admin_photo()
{
  global $template, $page;

  if (!in_array($page['page'], array('photo', 'batch_manager')))
  {
    return;
  }

  include_once(TYPETAGS_PATH . 'include/functions.inc.php');

  $query = '
SELECT
    t.id,
    tt.color,
    tt.striped,
    tt.emoji
  FROM ' . TYPETAGS_TABLE . ' AS tt
    INNER JOIN ' . TAGS_TABLE . ' AS t
    ON t.id_typetags = tt.id
  WHERE t.id_typetags IS NOT NULL
;';
  $tags_color = query2array($query, 'id');

  if (empty($tags_color))
  {
    return;
  }

  $css_rules = '';
  foreach ($tags_color as $tag_id => $group)
  {
    $css_rules .= typetags_chip_css($tag_id, $group['color'], $group['striped'], $group['emoji']);
  }

  $template->assign('TYPETAGS_CSS', $css_rules);

  if ($page['page'] == 'photo')
  {
    $template->set_prefilter('picture_modify', 'typetags_photo_prefilter');
  }
  else if ($page['page'] == 'batch_manager')
  {
    $template->set_prefilter('batch_manager_global', 'typetags_photo_prefilter');
  }
}

function typetags_photo_prefilter($content)
{
  $css_block = '<style>{$TYPETAGS_CSS}</style>';
  $content .= $css_block;
  return $content;
}