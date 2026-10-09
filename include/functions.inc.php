<?php
defined('TYPETAGS_PATH') or die('Hacking attempt!');

/**
 * Width of piwigo_typetags.name (maintain.class.php). The column is
 * varchar(255) utf8mb3, so the bound is 255 characters, not bytes.
 */
define('TYPETAGS_NAME_MAX_LENGTH', 255);

// New tags one account may type in on the picture page in 24 hours. Tag ids
// are smallint unsigned, so creation open to every account needs a bound.
define('TYPETAGS_NEW_TAGS_PER_DAY', 255);

/** The second colour of a striped group's tab, and the background it sits on. */
define('TYPETAGS_STRIPE_COLOR', '#fff');
/** A striped badge is mostly white, so its text is black whatever the group colour. */
define('TYPETAGS_STRIPED_TEXT', '#000');
/** 8 code points of up to 6 hex digits fit the emoji column, varchar(64). */
define('TYPETAGS_EMOJI_MAX_CODEPOINTS', 8);
define('TYPETAGS_UNICODE_MAX', 0x10FFFF);
/** The only ASCII an emoji holds: the base of a keycap (1️⃣ is 31 FE0F 20E3). */
define('TYPETAGS_EMOJI_ASCII', '0123456789#*');

/**
 * Normalise a group's emoji to the code points it is stored as.
 *
 * Accepts the pasted characters or typed hex code points, optionally
 * written U+XXXX, separated by blanks. ASCII other than a keycap's base is
 * refused, so text pasted or typed beside an emoji is not stored as one.
 *
 * @param string $input
 * @return string|false "1F5BC FE0F", '' for no emoji, false when invalid
 */
function typetags_emoji_codepoints($input)
{
  $input = trim((string)$input);

  if ($input === '')
  {
    return '';
  }

  $codepoints = array();

  if (preg_match('/[^\x00-\x7F]/', $input))
  {
    $chars = preg_split('//u', preg_replace('/\s+/', '', $input), -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false)
    {
      return false;
    }

    foreach ($chars as $char)
    {
      $codepoints[] = mb_ord($char, 'UTF-8');
    }
  }
  else
  {
    foreach (preg_split('/\s+/', $input) as $token)
    {
      if (!preg_match('/^(?:U\+)?([0-9A-F]{1,6})$/i', $token, $matches))
      {
        return false;
      }
      $codepoints[] = hexdec($matches[1]);
    }
  }

  if (count($codepoints) > TYPETAGS_EMOJI_MAX_CODEPOINTS)
  {
    return false;
  }

  $hex = array();
  foreach ($codepoints as $codepoint)
  {
    if ($codepoint < 1 or $codepoint > TYPETAGS_UNICODE_MAX or ($codepoint >= 0xD800 and $codepoint <= 0xDFFF))
    {
      return false;
    }
    if ($codepoint < 0x80 and strpos(TYPETAGS_EMOJI_ASCII, chr($codepoint)) === false)
    {
      return false;
    }
    $hex[] = sprintf('%X', $codepoint);
  }

  return implode(' ', $hex);
}

/**
 * @param string $codepoints as stored, "1F5BC FE0F"
 * @return string "&#x1F5BC;&#xFE0F;", '' for no emoji
 */
function typetags_emoji_html($codepoints)
{
  $html = '';
  foreach (preg_split('/\s+/', trim($codepoints), -1, PREG_SPLIT_NO_EMPTY) as $codepoint)
  {
    $html .= sprintf('&#x%X;', hexdec($codepoint));
  }
  return $html;
}

/**
 * @param string $codepoints as stored, "1F5BC FE0F"
 * @return string "\1F5BC\FE0F" for a CSS content: value, '' for no emoji
 */
function typetags_emoji_css($codepoints)
{
  $css = '';
  foreach (preg_split('/\s+/', trim($codepoints), -1, PREG_SPLIT_NO_EMPTY) as $codepoint)
  {
    $css .= sprintf('\\%X', hexdec($codepoint));
  }
  return $css;
}

/**
 * @param string $color
 * @param bool $striped
 * @return string the text colour of a badge
 */
function typetags_text_color($color, $striped)
{
  return $striped ? TYPETAGS_STRIPED_TEXT : get_color_text($color);
}

/**
 * @param string $color
 * @return string the red/white stripes of a striped group, without position
 */
function typetags_stripes($color)
{
  return 'repeating-linear-gradient(45deg,' . $color . ' 0 6px,' . TYPETAGS_STRIPE_COLOR . ' 6px 12px)';
}

/**
 * The background of a small colour sample: the colour, or stripes all over.
 *
 * @param string $color
 * @param bool $striped
 * @return string a CSS background value
 */
function typetags_swatch($color, $striped)
{
  return $striped ? typetags_stripes($color) : $color;
}

/**
 * The colour part of a badge's style: background, text and, when striped,
 * the border. A striped group gets a striped tab at the left edge.
 *
 * @param string $color
 * @param bool $striped
 * @return string
 */
function typetags_badge_colors($color, $striped)
{
  if (!$striped)
  {
    return 'background-color:' . $color . ';color:' . get_color_text($color) . ';';
  }

  return 'background:' . typetags_stripes($color) . ' left/18px 100% no-repeat,' . TYPETAGS_STRIPE_COLOR . ';'
    . 'color:' . TYPETAGS_STRIPED_TEXT . ';border:1px solid ' . $color . ';';
}

/**
 * The inline style of one badge. A non-striped group keeps the style the
 * plugin always rendered.
 *
 * @param string $color
 * @param bool $striped
 * @return string
 */
function typetags_badge_style($color, $striped)
{
  return typetags_badge_colors($color, $striped)
    . ($striped ? 'padding:2px 8px 2px 24px;' : 'padding:2px 8px;')
    . 'border-radius:12px;display:inline-block;';
}

/**
 * A badge's content: the emoji, in its own element so a script can read the
 * name without it, then the name.
 *
 * @param string $name
 * @param string $codepoints as stored
 * @return string HTML
 */
function typetags_badge_label($name, $codepoints)
{
  if ($codepoints === '' or $codepoints === null)
  {
    return $name;
  }
  return '<span class="typetag-emoji">' . typetags_emoji_html($codepoints) . '</span> ' . $name;
}

/**
 * @param array $row id, name, color, striped, emoji as read from the table
 * @return array the same, typed, as the web-service methods answer it
 */
function typetags_group_answer($row)
{
  return array(
    'id' => (int)$row['id'],
    'name' => $row['name'],
    'color' => $row['color'],
    'striped' => (bool)$row['striped'],
    'emoji' => $row['emoji'],
    );
}

/**
 * CSS colouring one tag's chip in the admin tag fields (selectize).
 *
 * @param int $tag_id
 * @param string $color
 * @param bool $striped
 * @param string $codepoints as stored
 * @return string
 */
function typetags_chip_css($tag_id, $color, $striped, $codepoints)
{
  $item = '.selectize-input .item[data-value="~~' . $tag_id . '~~"]';
  $active = '.selectize-input .item.active[data-value="~~' . $tag_id . '~~"]';
  $color_text = typetags_text_color($color, $striped);

  if ($striped)
  {
    $box = 'background:' . typetags_stripes($color) . ' left/18px 100% no-repeat,' . TYPETAGS_STRIPE_COLOR . ' !important;'
      . 'color:' . $color_text . ' !important;'
      . 'border:1px solid ' . $color . ' !important;padding-left:24px !important;';
  }
  else
  {
    $box = 'background-color:' . $color . ' !important;color:' . $color_text . ' !important;';
  }

  $css = $item . ',' . $active . '{' . $box . '}'
    . $item . ' .remove,' . $active . ' .remove{color:' . $color_text . ' !important;}';

  if ($codepoints !== '' and $codepoints !== null)
  {
    $css .= $item . '::before{content:"' . typetags_emoji_css($codepoints) . '";margin-right:4px;}';
  }

  return $css;
}

function get_color_text($color)
{
  if (empty($color))
  {
    return '';
  }

  $rgb = array();

  if (strlen($color) == 7)
  {
    $rgb[] = hexdec(substr($color, 1, 2))/255;
    $rgb[] = hexdec(substr($color, 3, 2))/255;
    $rgb[] = hexdec(substr($color, 5, 2))/255;
  }
  else if (strlen($color) == 4)
  {
    $rgb[] = hexdec(substr($color, 1, 1))/15;
    $rgb[] = hexdec(substr($color, 2, 1))/15;
    $rgb[] = hexdec(substr($color, 3, 1))/15;
  }

  if (empty($rgb))
  {
    return '#000';
  }

  $l = (min($rgb) + max($rgb)) / 2;

  return $l > 0.45 ? '#000' : '#fff';
}

/**
 * Split colored tags into unassigned (with text colour, style and emoji) and
 * assigned ids.
 *
 * @param array $all_colored  rows of id, name, url_name, color, striped, emoji
 * @param array $assigned_ids tag ids assigned to the image (may include non-colored)
 * @return array{unassigned: array, assigned_colored_ids: array}
 */
function typetags_partition_tags($all_colored, $assigned_ids)
{
  $unassigned = array();
  $assigned_colored_ids = array();

  foreach ($all_colored as $tag)
  {
    if (in_array($tag['id'], $assigned_ids))
    {
      $assigned_colored_ids[] = $tag['id'];
    }
    else
    {
      $striped = !empty($tag['striped']);
      $tag['color_text'] = typetags_text_color($tag['color'], $striped);
      $tag['style'] = typetags_badge_style($tag['color'], $striped);
      $tag['emoji_html'] = typetags_emoji_html($tag['emoji'] ?? '');
      $unassigned[] = $tag;
    }
  }

  return array(
    'unassigned' => $unassigned,
    'assigned_colored_ids' => $assigned_colored_ids,
    );
}

function check_color($hex)
{
  global $page;

  $hex = ltrim($hex, '#');

  if (strlen($hex) == 3)
  {
    $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  }
  else if (strlen($hex) != 6)
  {
    return false;
  }

  if (!ctype_xdigit($hex))
  {
    return false;
  }

  return '#'.$hex;
}

function get_typetag_id($input)
{
  if (preg_match('#^~~([0-9]+)~~$#', $input, $matches))
  {
    return $matches[1];
  }
  else if (strpos($input, '|') !== false)
  {
    list($color, $name) = explode('|', $input, 2);

    if ( ($color = check_color($color)) === false)
    {
      return false;
    }

    $query = '
SELECT id FROM ' . TYPETAGS_TABLE . '
  WHERE color = "' . $color . '"
  LIMIT 1
;';
    $result = pwg_query($query);

    if (pwg_db_num_rows($result))
    {
      list($tt_id) = pwg_db_fetch_row($result);
      return $tt_id;
    }

    $query = '
INSERT INTO ' . TYPETAGS_TABLE . '(
    name,
    color
  )
  VALUES(
    "' . pwg_db_real_escape_string($name) . '",
    "' . $color . '"
  )
;';
    pwg_query($query);

    return pwg_db_insert_id();
  }
  else
  {
    return false;
  }
}
/**
 * Announce that every tag of one group changed group, e.g. because the
 * group was renamed. Listeners rewrite what depends on a tag's group.
 *
 * @param int $group_id
 */
function typetags_notify_regrouped_group($group_id)
{
  $query = '
SELECT id
  FROM ' . TAGS_TABLE . '
  WHERE id_typetags = ' . (int)$group_id . '
;';
  $tag_ids = query2array($query, null, 'id');

  if (count($tag_ids))
  {
    trigger_notify('typetags_tags_regrouped', $tag_ids);
  }
}
