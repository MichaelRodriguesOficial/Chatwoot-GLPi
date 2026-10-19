<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — see the
// Firewall::STRATEGY_NO_CHECK + SessionManager::registerPluginStatelessPath
// registration in setup.php. It's needed because the Chatwoot dashboard
// (where the agent sees the contact's photo) runs on their own server,
// without a GLPI session.
//
// To avoid exposing any user's photo by guessing an ID, access only works
// with a signed token (generated with the same encryption key GLPI already
// uses for the plugin's other secrets).

require_once dirname(__DIR__) . '/src/autoload.php';

$token = (string) ($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(404);
    return;
}

// GLPIKey::encrypt() already returns base64 (see Config::getUserIdentity()),
// so only the URL-encoding needs undoing here — no extra base64 layer.
$glpikey = new GLPIKey();
try {
    $user_id = (int) $glpikey->decrypt(rawurldecode($token));
} catch (\Throwable $e) {
    // Not logged on purpose: a forged/invalid token is expected input here.
    http_response_code(404);
    return;
}
if ($user_id <= 0) {
    http_response_code(404);
    return;
}

$u = new User();
if (!$u->getFromDB($user_id) || empty($u->fields['picture'])) {
    http_response_code(404);
    return;
}

$path = GLPI_PICTURE_DIR . '/' . $u->fields['picture'];
if (!is_file($path)) {
    http_response_code(404);
    return;
}

$mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'image/jpeg') : 'image/jpeg';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($path));
readfile($path);
