<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class typetags_maintain extends PluginMaintain
{
  const ADMIN_CACHE_REFRESHED = 'typetags_admin_cache_refreshed';

  private $default_conf = array(
    'show_all'=>true,
    );

  private $table;

  function __construct($plugin_id)
  {
    parent::__construct($plugin_id);

    global $prefixeTable;
    $this->table = $prefixeTable . 'typetags';
  }

  function install($plugin_version, &$errors=array())
  {
    global $conf;

    if (empty($conf['TypeTags']))
    {
      conf_update_param('TypeTags', $this->default_conf, true);
    }

    $result = pwg_query('SHOW COLUMNS FROM `' . TAGS_TABLE . '` LIKE "id_typetags";');
    if (!pwg_db_num_rows($result))
    {
      pwg_query('ALTER TABLE `' . TAGS_TABLE . '` ADD `id_typetags` SMALLINT(5) DEFAULT NULL;');
    }

    $query = '
CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
  `id` smallint(5) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `color` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) DEFAULT CHARSET=utf8
;';
    pwg_query($query);

    // emoji holds code points ("1F5BC FE0F"): the table is utf8mb3 and
    // cannot store a four-byte character
    $columns = array(
      'striped' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'emoji' => 'VARCHAR(64) NOT NULL DEFAULT \'\'',
      );
    foreach ($columns as $column => $definition)
    {
      $result = pwg_query('SHOW COLUMNS FROM `' . $this->table . '` LIKE "' . $column . '";');
      if (!pwg_db_num_rows($result))
      {
        pwg_query('ALTER TABLE `' . $this->table . '` ADD `' . $column . '` ' . $definition . ';');
      }
    }
  }

  function update($old_version, $new_version, &$errors=array())
  {
    global $conf;

    $this->install($new_version, $errors);

    // pwg.tags.getAdminList used to answer coloured names as badge HTML, and
    // browsers cache that list until MAX(tags.lastmodified) moves. Once only:
    // a "Version: auto" plugin is updated on every request.
    if (empty($conf[self::ADMIN_CACHE_REFRESHED]))
    {
      pwg_query('UPDATE `' . TAGS_TABLE . '` SET lastmodified = NOW() WHERE id_typetags IS NOT NULL;');
      conf_update_param(self::ADMIN_CACHE_REFRESHED, true, true);
    }
  }

  function uninstall()
  {
    conf_delete_param('TypeTags');
    conf_delete_param(self::ADMIN_CACHE_REFRESHED);

    pwg_query('ALTER TABLE `' . TAGS_TABLE . '` DROP `id_typetags`');
    pwg_query('DROP TABLE `' . $this->table . '`;');
  }
}
