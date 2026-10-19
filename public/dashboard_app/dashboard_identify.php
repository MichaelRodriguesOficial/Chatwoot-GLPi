<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — see the
// Firewall::STRATEGY_NO_CHECK + SessionManager::registerPluginStatelessPath
// registration in setup.php, and the explanation in dashboard_tickets.php.
//
// This is the ONLY endpoint that still accepts a raw `identifier`/`phone` —
// the thing HIGH 1 of the 1.0.0 security review flagged, where every data
// endpoint trusted those values directly with no way to tell "the person
// the agent is actually talking to" from "any login name the caller typed
// in". Resolution now happens exactly once, here, and the result is handed
// back as a short-lived, HMAC-signed-equivalent capability (GLPIKey
// authenticated encryption) that dashboard_tickets.php,
// dashboard_ticket_detail.php and dashboard_document.php require instead.
// Centralising it here also means only one endpoint needs the aggressive
// rate limiting that an identifier/phone-guessing surface needs.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Accepted via POST body (not the query string) and the token via a custom
// header rather than `?t=` — keeps both out of access logs, proxy logs, and
// browser history (MEDIUM 3 of the review).
$token      = (string) ($_SERVER['HTTP_X_CHATWOOT_PANEL_TOKEN'] ?? ($_POST['t'] ?? ''));
$identifier = trim((string) ($_POST['identifier'] ?? ''));
$phone      = trim((string) ($_POST['phone'] ?? ''));

if (!ChatwootConfig::isValidDashboardAppToken($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_token']);
    return;
}

$ip = ChatwootConfig::clientIp();
if (!ChatwootConfig::checkAndLogAccess('identify', $ip)) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited']);
    return;
}

if ($identifier === '' && $phone === '') {
    echo json_encode(['found' => false]);
    return;
}

$user = ChatwootConfig::resolveIdentity($identifier, $phone);
if (!$user) {
    echo json_encode(['found' => false]);
    return;
}

$user_name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];

echo json_encode([
    'found'          => true,
    'user_name'      => $user_name,
    'capability'     => ChatwootConfig::mintCapability((int) $user['id']),
    'expires_in'     => ChatwootConfig::CAPABILITY_TTL,
]);
