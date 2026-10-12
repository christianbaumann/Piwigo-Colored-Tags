<?php
defined('TYPETAGS_PATH') or die('Hacking attempt!');

define('TYPETAGS_TAGGROUPS_TOKEN', 'taggroups');
define('TYPETAGS_TPL_TAGS_LINK_POINT', "{include file='infos_errors.tpl'}");

/**
 * index.php?/taggroups: the colour groups, read-only, for every logged-in
 * account. Core renders the page around it (is_external skips the thumbnails).
 * triggered by 'loc_end_section_init'
 */
function typetags_taggroups_section()
{
  global $tokens, $page, $conf;

  if (script_basename() != 'index' or !isset($tokens[0]) or $tokens[0] !== TYPETAGS_TAGGROUPS_TOKEN)
  {
    return;
  }

  // Every logged-in account, generic included: ACCESS_CLASSIC would refuse generic.
  if (is_a_guest())
  {
    access_denied();
  }
  load_language('plugin.lang', TYPETAGS_PATH);

  $page['section'] = TYPETAGS_TAGGROUPS_TOKEN;
  $page['is_external'] = true;
  $page['is_homepage'] = false;
  $page['items'] = array();
  $page['title'] = l10n('Tag groups');
  $page['section_title'] = '<a href="' . get_gallery_home_url() . '">' . l10n('Home') . '</a>'
    . $conf['level_separator'] . l10n('Tag groups');
  $page['body_classes'][] = 'section-' . TYPETAGS_TAGGROUPS_TOKEN;
  $page['body_data']['section'] = TYPETAGS_TAGGROUPS_TOKEN;

  add_event_handler('loc_end_index', 'typetags_taggroups_page');
}

/**
 * Every group with the tags the account can see, as core's tags page counts
 * them (tags on photos in albums it may see).
 * triggered by 'loc_end_index'
 */
function typetags_taggroups_page()
{
  global $template;

  $tags = get_available_tags();
  foreach ($tags as &$tag)
  {
    // get_available_tags() hands the name through render_tag_name, which paints a badge already
    $tag['name'] = $tag['name_raw'];
  }
  unset($tag);
  usort($tags, 'tag_alpha_compare');

  $groups = typetags_groups_with_tags(typetags_groups(), $tags);
  foreach ($groups as &$group)
  {
    foreach ($group['tags'] as &$tag)
    {
      $tag['URL'] = make_index_url(array('tags' => array($tag)));
      $tag['label'] = typetags_badge_label($tag['name'], $group['emoji']);
    }
    unset($tag);
  }
  unset($group);

  $template->assign(array(
    'TYPETAGS_GROUPS' => $groups,
    'TYPETAGS_U_TAGS' => get_root_url() . 'tags.php',
    ));
  $template->set_filename('index', realpath(TYPETAGS_PATH . 'template/taggroups.tpl'));
}

/**
 * The tags page links to the group page.
 * triggered by 'loc_end_tags'
 */
function typetags_tags_link()
{
  global $template;

  load_language('plugin.lang', TYPETAGS_PATH);
  $template->assign('TYPETAGS_U_GROUPS', get_root_url() . 'index.php?/' . TYPETAGS_TAGGROUPS_TOKEN);
  $template->set_prefilter('tags', 'typetags_tags_link_prefilter');
}

/**
 * Included rather than pasted in, so an edit reaches the compiled page (Piwigo decision 0036).
 */
function typetags_tags_link_prefilter($content)
{
  $include = "{include file='" . realpath(TYPETAGS_PATH . 'template/tags_link.tpl') . "'}";
  return str_replace(TYPETAGS_TPL_TAGS_LINK_POINT, $include . TYPETAGS_TPL_TAGS_LINK_POINT, $content);
}
