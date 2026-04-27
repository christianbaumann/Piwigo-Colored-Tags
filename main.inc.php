<?php
/*
Plugin Name: Colored Tags
Version: auto
Description: Allow to manage color of tags, as you want...
Plugin URI: auto
Author: Mistic
Author URI: http://www.strangeplanet.fr
Has Settings: true
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'typetags')
{
  add_event_handler('init', 'typetags_error');
  function typetags_error()
  {
    global $page;
    $page['errors'][] = 'Colored Tags folder name is incorrect, uninstall the plugin and rename it to "typetags"';
  }
  return;
}

global $prefixeTable, $conf;

define('TYPETAGS_PATH' ,  PHPWG_PLUGINS_PATH . 'typetags/');
define('TYPETAGS_TABLE' , $prefixeTable . 'typetags');
define('TYPETAGS_ADMIN',  get_root_url().'admin.php?page=plugin-typetags');

include_once(TYPETAGS_PATH . 'include/events_public.inc.php');
include_once(TYPETAGS_PATH . 'include/functions.inc.php');

$conf['TypeTags'] = safe_unserialize($conf['TypeTags']);


// inline tag assignment on picture page
if (script_basename() == 'picture')
{
  add_event_handler('loc_end_picture', 'typetags_picture_tags');
}

// tags everywhere
if ($conf['TypeTags']['show_all'] and script_basename() != 'tags')
{
  add_event_handler('render_tag_name', 'typetags_render', 0, 2);
}

// tags on tags page
add_event_handler('loc_end_tags', 'typetags_tags');

// escape keywords meta
add_event_handler('loc_begin_page_header', 'typetags_escape');


if (defined('IN_ADMIN'))
{
  add_event_handler('loc_begin_admin_page', 'typetags_admin');
  add_event_handler('loc_begin_admin_page', 'typetags_admin_photo');

  include_once(TYPETAGS_PATH . 'include/events_admin.inc.php');
}

// add api/service methods
add_event_handler('ws_add_methods', 'typetags_add_methods');

function typetags_add_methods($arr) 
{
  $service = &$arr[0];

  $service->addMethod(
    'typetags.tags.setType',
    'ws_typetags_tags_setType',
    array(
      'tag_id' => array('type' => WS_TYPE_ID, 'flags'=>WS_PARAM_FORCE_ARRAY),
      'typetag_id' => array('info' => 'Zero (0) for remove color')
      ),
    'Set/remove color for a list of tags',
    null,
    array('admin_only'=>true)
  );

  $service->addMethod(
    'typetags.type.add',
    'ws_typetags_type_add',
    array(
      'typetag_name' => array(),
      'typetag_color' => array('info' => 'In format RRVVBB (Example : FF0000 for red)')
      ),
    'Create a tag color'
    );

  $service->addMethod(
    'typetags.image.addTag',
    'ws_typetags_image_addTag',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'tag_id'   => array('type' => WS_TYPE_ID),
      'pwg_token' => array(),
    ),
    'Assign a colored tag to an image'
  );

  $service->addMethod(
    'typetags.image.removeTag',
    'ws_typetags_image_removeTag',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'tag_id'   => array('type' => WS_TYPE_ID),
      'pwg_token' => array(),
    ),
    'Remove a colored tag from an image'
  );
}


/**
 * API method
 * Set a color for tags
 * @param mixed[] $params
 *    @option int[] tag_id
 *    @option int typetag_id
 */
function ws_typetags_tags_setType($params, &$service) 
{
$query = '
UPDATE ' . TAGS_TABLE . '
  SET id_typetags = ' . ($params['typetag_id']!=0 ? $params['typetag_id'] : 'NULL') . '
  WHERE id IN ('.implode(',', $params['tag_id']).')
;';
  pwg_query($query);
}

/**
 * API method
 * Create a new type of tag
 * @param mixed[] $params
 *    @option string typetag_name
 *    @option string typetag_color
 */
function ws_typetags_type_add($params, &$service) 
{
  $name = $params['typetag_name'];
  $color = '#' . $params['typetag_color'];

  // does the tag already exists?
  $query = '
SELECT id
  FROM ' . TYPETAGS_TABLE . '
  WHERE name = "' . pwg_db_real_escape_string($name) . '"
';

  if (pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, l10n('This name is already used'));
  }
  else if ( ($color = check_color($color)) === false )
  {
    return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid color'));
  }
  else
  {
    single_insert(
      TYPETAGS_TABLE, 
      array(
        "name" => pwg_db_real_escape_string($name),
        "color" => $color,
      )
    );

    $id = pwg_db_insert_id(IMAGES_TABLE);

    if (pwg_query($query)) 
    {
      return array(
        'id' => $id,
        'color' => $color,
        'color_text' => get_color_text($color),
        'name' => $name,
      );
    } 
    else 
    {
      return false;
    };
  }
}

function ws_typetags_image_addTag($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Verify tag is a colored tag
  $query = '
SELECT id FROM ' . TAGS_TABLE . '
  WHERE id = ' . (int)$params['tag_id'] . '
    AND id_typetags IS NOT NULL
;';
  if (!pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(404, 'Tag not found or not a colored tag');
  }

  // Insert (ignore if already exists)
  $query = '
INSERT IGNORE INTO ' . IMAGE_TAG_TABLE . '
  (image_id, tag_id)
  VALUES (' . (int)$params['image_id'] . ', ' . (int)$params['tag_id'] . ')
;';
  pwg_query($query);

  // Invalidate tag count cache
  $query = '
UPDATE ' . USER_CACHE_TABLE . '
  SET nb_available_tags = NULL
;';
  pwg_query($query);

  return true;
}

function ws_typetags_image_removeTag($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Verify tag is a colored tag
  $query = '
SELECT id FROM ' . TAGS_TABLE . '
  WHERE id = ' . (int)$params['tag_id'] . '
    AND id_typetags IS NOT NULL
;';
  if (!pwg_db_num_rows(pwg_query($query)))
  {
    return new PwgError(404, 'Tag not found or not a colored tag');
  }

  $query = '
DELETE FROM ' . IMAGE_TAG_TABLE . '
  WHERE image_id = ' . (int)$params['image_id'] . '
    AND tag_id = ' . (int)$params['tag_id'] . '
;';
  pwg_query($query);

  // Invalidate tag count cache
  $query = '
UPDATE ' . USER_CACHE_TABLE . '
  SET nb_available_tags = NULL
;';
  pwg_query($query);

  return true;
}