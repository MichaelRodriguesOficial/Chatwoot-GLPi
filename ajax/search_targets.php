<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

Session::checkRight('config', UPDATE);

header('Content-Type: application/json; charset=UTF-8');

$itemtype = (string) ($_GET['itemtype'] ?? '');
$term     = (string) ($_GET['term'] ?? '');

if (!in_array($itemtype, ['Profile', 'Group', 'User'], true)) {
    echo json_encode([]);
    return;
}

echo json_encode(ChatwootConfig::searchTargets($itemtype, $term, 10));
