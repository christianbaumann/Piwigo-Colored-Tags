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
      'typetag_color' => array('info' => 'In format RRVVBB (Example : FF0000 for red)'),
      'striped' => array('default' => false, 'type' => WS_TYPE_BOOL),
      'emoji' => array('default' => '', 'info' => 'The emoji, or its code points (Example : 1F5BC FE0F)'),
      ),
    'Create a tag color',
    null,
    array('admin_only'=>true)
  );

  $service->addMethod(
    'typetags.type.list',
    'ws_typetags_type_list',
    array(),
    'List the tag colors',
    null,
    array('admin_only'=>true)
  );

  $service->addMethod(
    'typetags.type.update',
    'ws_typetags_type_update',
    array(
      'typetag_id' => array('type' => WS_TYPE_ID),
      'typetag_color' => array('default' => null, 'info' => 'In format RRVVBB (Example : FF0000 for red)'),
      'striped' => array('default' => null, 'type' => WS_TYPE_BOOL),
      'emoji' => array('default' => null, 'info' => 'The emoji, or its code points; empty for none'),
      'pwg_token' => array(),
      ),
    'Change the color, stripes or emoji of a tag color. A parameter left out keeps its value.',
    null,
    array('admin_only'=>true, 'post_only'=>true)
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

  $service->addMethod(
    'typetags.image.addNewTag',
    'ws_typetags_image_addNewTag',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'tag_name' => array('info' => 'Created when no tag has this name, otherwise that tag is assigned'),
      'pwg_token' => array(),
    ),
    'Assign a tag to an image by its name, creating it when it is new',
    null,
    array('post_only' => true)
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

  trigger_notify('typetags_tags_regrouped', $params['tag_id']);
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

  // mb_strlen, not strlen: the column counts characters, and a German name of
  // 255 umlauts is 255 characters but more than 255 bytes. Without this the
  // insert throws an uncaught mysqli_sql_exception and the stack trace is
  // rendered into the response.
  if (mb_strlen($name) > TYPETAGS_NAME_MAX_LENGTH)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid tag name'));
  }

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
  else if ( ($emoji = typetags_emoji_codepoints($params['emoji'])) === false )
  {
    return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid emoji'));
  }
  else
  {
    // not single_insert(): it writes '' as NULL, and "no emoji" is ''
    $insert = '
INSERT INTO ' . TYPETAGS_TABLE . ' (name, color, striped, emoji)
  VALUES ("' . pwg_db_real_escape_string($name) . '", "' . $color . '", ' . ($params['striped'] ? 1 : 0) . ', "' . $emoji . '")
;';
    pwg_query($insert);

    $id = pwg_db_insert_id(IMAGES_TABLE);

    if (pwg_query($query)) 
    {
      return array(
        'id' => $id,
        'color' => $color,
        'color_text' => typetags_text_color($color, $params['striped']),
        'swatch' => typetags_swatch($color, $params['striped']),
        'name' => $name,
        'striped' => (bool)$params['striped'],
        'emoji' => $emoji,
      );
    } 
    else 
    {
      return false;
    };
  }
}

/**
 * API method
 * List every tag color
 */
function ws_typetags_type_list($params, &$service)
{
  $query = '
SELECT id, name, color, striped, emoji
  FROM ' . TYPETAGS_TABLE . '
  ORDER BY id
;';
  return array_map('typetags_group_answer', query2array($query));
}

/**
 * API method
 * Change the color, stripes or emoji of a tag color
 * @param mixed[] $params
 *    @option int typetag_id
 *    @option string typetag_color (optional)
 *    @option bool striped (optional)
 *    @option string emoji (optional)
 */
function ws_typetags_type_update($params, &$service)
{
  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  $query = '
SELECT id, name, color, striped, emoji
  FROM ' . TYPETAGS_TABLE . '
  WHERE id = ' . (int)$params['typetag_id'] . '
;';
  $row = pwg_db_fetch_assoc(pwg_query($query));
  if (empty($row))
  {
    return new PwgError(404, 'Tag color not found');
  }

  if (isset($params['typetag_color']))
  {
    if (($row['color'] = check_color($params['typetag_color'])) === false)
    {
      return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid color'));
    }
  }

  if (isset($params['emoji']))
  {
    if (($row['emoji'] = typetags_emoji_codepoints($params['emoji'])) === false)
    {
      return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid emoji'));
    }
  }

  if (isset($params['striped']))
  {
    $row['striped'] = $params['striped'] ? 1 : 0;
  }

  // not single_update(): it writes '' as NULL, and "no emoji" is ''
  $query = '
UPDATE ' . TYPETAGS_TABLE . '
  SET color = "' . $row['color'] . '",
    striped = ' . ($row['striped'] ? 1 : 0) . ',
    emoji = "' . $row['emoji'] . '"
  WHERE id = ' . (int)$row['id'] . '
;';
  pwg_query($query);

  return typetags_group_answer($row);
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

  // A photo the account cannot see is answered like a missing one (a missing
  // one would also leave an orphan row behind INSERT IGNORE)
  if (!typetags_image_visible($params['image_id']))
  {
    return new PwgError(404, 'Image not found');
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

/**
 * API method
 * Assign a tag typed on the picture page, creating it when the name is new
 * @param mixed[] $params
 *    @option int image_id
 *    @option string tag_name
 */
function ws_typetags_image_addNewTag($params, &$service)
{
  if (is_a_guest())
  {
    return new PwgError(401, 'Access denied');
  }

  if (get_pwg_token() != $params['pwg_token'])
  {
    return new PwgError(403, 'Invalid security token');
  }

  // Cleaned as core cleans a name typed into its tag fields (get_tag_ids()).
  $name = trim(strip_tags(stripslashes($params['tag_name'])));
  if (!typetags_typed_tag_name_is_valid($name))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, l10n('Invalid tag name'));
  }

  if (!typetags_image_visible($params['image_id']))
  {
    return new PwgError(404, 'Image not found');
  }

  if (typetags_typed_tags_today() >= TYPETAGS_NEW_TAGS_PER_DAY and !typetags_tag_exists($name))
  {
    return new PwgError(429, l10n('You have typed in too many new tags today'));
  }

  include_once(PHPWG_ROOT_PATH . 'admin/include/functions.php');

  list($max_tag_id) = pwg_db_fetch_row(pwg_query('SELECT MAX(id) FROM ' . TAGS_TABLE . ';'));
  $tag_id = (int)tag_id_from_tag_name(pwg_db_real_escape_string($name));
  if ($tag_id > (int)$max_tag_id)
  {
    // also what typetags_typed_tags_today() counts
    pwg_activity('tag', $tag_id, 'add');
  }

  $query = '
INSERT IGNORE INTO ' . IMAGE_TAG_TABLE . '
  (image_id, tag_id)
  VALUES (' . (int)$params['image_id'] . ', ' . $tag_id . ')
;';
  pwg_query($query);

  $query = '
UPDATE ' . USER_CACHE_TABLE . '
  SET nb_available_tags = NULL
;';
  pwg_query($query);

  $query = '
SELECT name FROM ' . TAGS_TABLE . '
  WHERE id = ' . $tag_id . '
;';
  list($stored_name) = pwg_db_fetch_row(pwg_query($query));

  return array_merge(
    array(
      'tag_id' => $tag_id,
      'name' => $stored_name,
      'created' => $tag_id > (int)$max_tag_id,
      ),
    typetags_tag_badge($tag_id)
    );
}

/**
 * How many tags the current account typed in on the picture page in the last
 * 24 hours, from core's activity log, where pwg_activity() records the method.
 * @return int
 */
function typetags_typed_tags_today()
{
  global $user;

  $query = '
SELECT COUNT(*)
  FROM ' . ACTIVITY_TABLE . '
  WHERE object = \'tag\'
    AND action = \'add\'
    AND performed_by = ' . (int)$user['id'] . '
    AND occured_on > NOW() - INTERVAL 1 DAY
    AND details LIKE \'%"typetags.image.addNewTag"%\'
;';
  list($count) = pwg_db_fetch_row(pwg_query($query));
  return (int)$count;
}

/**
 * Whether a tag of that name exists, found as core's tag_id_from_tag_name()
 * looks first: by name, then by URL name.
 * @param string $name cleaned, not escaped
 * @return bool
 */
function typetags_tag_exists($name)
{
  $query = '
SELECT id
  FROM ' . TAGS_TABLE . '
  WHERE name = \'' . pwg_db_real_escape_string($name) . '\'
    OR url_name = \'' . pwg_db_real_escape_string(trigger_change('render_tag_url', $name)) . '\'
  LIMIT 1
;';
  return pwg_db_num_rows(pwg_query($query)) > 0;
}

/**
 * Whether the current account may see a photo: it exists and sits in an album
 * the account has access to, by core's own permission condition. The picture
 * page's tag methods act only on such a photo.
 * @param int $image_id
 * @return bool
 */
function typetags_image_visible($image_id)
{
  $query = '
SELECT DISTINCT image_id
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . IMAGES_TABLE . ' ON id = image_id
  WHERE image_id = ' . (int)$image_id . '
' . get_sql_condition_FandF(
    array(
      'forbidden_categories' => 'category_id',
      'visible_categories' => 'category_id',
      'visible_images' => 'id',
      ),
    '    AND'
    ) . '
;';
  return pwg_db_num_rows(pwg_query($query)) > 0;
}

/**
 * Whether a name typed by any logged-in account may become a tag. Refused:
 * - characters that could end an HTML attribute or open a tag, and control
 *   characters: core prints tag names unescaped inside attributes (the
 *   keywords meta, the tags page), and in core only administrators name tags
 * - a backslash: the admin tags page writes orphan tag names into a
 *   JavaScript array literal, where it escapes the closing quote
 * - characters outside the Basic Multilingual Plane, as most emoji are, and
 *   bytes that are no UTF-8: the tags table is utf8mb3
 * - a name, or the URL name core derives from it (which spells some letters
 *   with two), longer than the columns hold
 * @param string $name cleaned and trimmed
 * @return bool
 */
function typetags_typed_tag_name_is_valid($name)
{
  return $name !== ''
    and preg_match('/^[\x{0}-\x{FFFF}]*$/u', $name) === 1
    and preg_match('/["<>\\\\[:cntrl:]]/', $name) === 0
    and mb_strlen($name) <= TYPETAGS_NAME_MAX_LENGTH
    and mb_strlen(trigger_change('render_tag_url', $name)) <= TYPETAGS_NAME_MAX_LENGTH;
}

/**
 * The badge of one tag, as its group draws it
 * @param int $tag_id
 * @return array style and emoji_html, both '' for a tag in no group
 */
function typetags_tag_badge($tag_id)
{
  $query = '
SELECT tt.color, tt.striped, tt.emoji
  FROM ' . TAGS_TABLE . ' AS t
    INNER JOIN ' . TYPETAGS_TABLE . ' AS tt ON t.id_typetags = tt.id
  WHERE t.id = ' . (int)$tag_id . '
;';
  $group = pwg_db_fetch_assoc(pwg_query($query));
  if (empty($group))
  {
    return array('style' => '', 'emoji_html' => '');
  }

  return array(
    'style' => typetags_badge_style($group['color'], !empty($group['striped'])),
    'emoji_html' => typetags_emoji_html($group['emoji']),
    );
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

  if (!typetags_image_visible($params['image_id']))
  {
    return new PwgError(404, 'Image not found');
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