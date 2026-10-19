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
// Authorization is two-layered: the integration token proves the caller is
// the configured Chatwoot instance, and the capability (minted once by
// dashboard_identify.php) proves which GLPI user's tickets may be read —
// never a raw, client-asserted `identifier`/`phone` (see HIGH 1 of the 1.0.0
// security review). Only returns the tickets of that user, and only within
// the entities the panel is restricted to, if any.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$token      = (string) ($_SERVER['HTTP_X_CHATWOOT_PANEL_TOKEN'] ?? ($_GET['t'] ?? ''));
$capability = (string) ($_SERVER['HTTP_X_CHATWOOT_CAPABILITY'] ?? ($_GET['cap'] ?? ''));
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

$user = ChatwootConfig::verifyCapability($capability);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_or_expired_capability']);
    return;
}

echo json_encode(ChatwootConfig::getTicketsForUser((int) $user['id'], $search, $status, 50));
