<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — see the
// Firewall::STRATEGY_NO_CHECK + SessionManager::registerPluginStatelessPath
// registration in setup.php. It's needed because the panel runs inside an
// iframe on Chatwoot's domain, where the GLPI session cookie is normally not
// sent (modern browsers block third-party cookies in iframes).
//
// Protected by a unique integration token (generated in Setup > Chatwoot),
// not by session authentication. Only returns the tickets of the identified
// user — never a free search for any ticket in the system.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/json; charset=UTF-8');

$token      = (string) ($_GET['t'] ?? '');
$identifier = trim((string) ($_GET['identifier'] ?? ''));
$phone      = trim((string) ($_GET['phone'] ?? ''));
$search     = trim((string) ($_GET['q'] ?? ''));
$status     = (int) ($_GET['status'] ?? 0);
$lang       = trim((string) ($_GET['lang'] ?? ''));

if (!ChatwootConfig::isValidDashboardAppToken($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_token']);
    return;
}

// Matches the ticket status/priority labels to the language the panel
// already resolved from the Chatwoot account — see
// Config::applyRequestLanguage() for why this is safe (no real session touched).
if ($lang !== '') {
    ChatwootConfig::applyRequestLanguage($lang);
}

if ($identifier === '' && $phone === '') {
    echo json_encode(['user_found' => false, 'tickets' => []]);
    return;
}

echo json_encode(ChatwootConfig::getTicketsForIdentifier($identifier, $search, $status, 50, $phone));
