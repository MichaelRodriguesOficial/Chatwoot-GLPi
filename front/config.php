<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

Session::checkRight('config', READ);
Html::header(ChatwootConfig::getTypeName(), $_SERVER['PHP_SELF'], 'config', 'plugins', 'chatwoot');
$config = new \Config();
$config->display(['id' => 1, 'forcetab' => 'GlpiPlugin\\Chatwoot\\Config$1']);
Html::footer();
