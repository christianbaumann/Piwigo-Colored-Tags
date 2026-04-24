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
    $row['color_text'] = get_color_text($row['color']);
    $template->append('typetags', $row);
  }

  $query = '
SELECT
    t.id,
    id_typetags,
    color
  FROM ' . TAGS_TABLE . ' AS t
    LEFT JOIN ' . TYPETAGS_TABLE . ' AS tt
    ON t.id_typetags = tt.id
;';
  $template->assign('tags_color', query2array($query, 'id'));

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
    tt.color
  FROM ' . TYPETAGS_TABLE . ' AS tt
    INNER JOIN ' . TAGS_TABLE . ' AS t
    ON t.id_typetags = tt.id
  WHERE t.id_typetags IS NOT NULL
;';
  $tags_color = query2array($query, 'id', 'color');

  if (empty($tags_color))
  {
    return;
  }

  $css_rules = '';
  foreach ($tags_color as $tag_id => $color)
  {
    $color_text = get_color_text($color);
    $css_rules .= '.selectize-input .item[data-value="~~' . $tag_id . '~~"],'
      . '.selectize-input .item.active[data-value="~~' . $tag_id . '~~"]'
      . '{background-color:' . $color . ' !important;color:' . $color_text . ' !important;}'
      . '.selectize-input .item[data-value="~~' . $tag_id . '~~"] .remove,'
      . '.selectize-input .item.active[data-value="~~' . $tag_id . '~~"] .remove'
      . '{color:' . $color_text . ' !important;}';
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