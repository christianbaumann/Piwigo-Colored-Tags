<?php
// functions.inc.php guards on TYPETAGS_PATH and then only declares functions,
// so it loads with no database and no Piwigo core.
define('TYPETAGS_PATH', dirname(__DIR__) . '/');
define('PIWIGO_ROOT', dirname(dirname(dirname(__DIR__))) . '/');
require_once TYPETAGS_PATH . 'include/functions.inc.php';
require_once TYPETAGS_PATH . 'include/events_public.inc.php';

// Integration-layer support classes (no Piwigo core needed — they talk to
// ws.php over HTTP and to MariaDB directly).
require_once __DIR__ . '/Support/Config.php';
require_once __DIR__ . '/Support/Db.php';
require_once __DIR__ . '/Support/WsClient.php';
