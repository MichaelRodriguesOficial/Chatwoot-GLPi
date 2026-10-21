<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — see the
// Firewall::STRATEGY_NO_CHECK + SessionManager::registerPluginStatelessPath
// registration in setup.php, and the explanation in dashboard_tickets.php.
//
// The single way the panel obtains a capability (HIGH 1 of the 1.0.0
// security review). It receives ONLY the id of the Chatwoot conversation the
// agent has open — never a login or phone number. Who the contact is comes
// from the Chatwoot API itself (Config::resolveConversationUser()), and the
// GLPI lookup is the same as always: contact identifier (GLPI login) first,
// then phone number. A leaked integration token therefore can't be used to
// name an arbitrary GLPI user: only people with a real conversation in this
// Chatwoot account can be resolved, exactly as Chatwoot itself knows them.
// The result is a short-lived capability (GLPIKey authenticated encryption)
// bound to that user and that conversation, which dashboard_tickets.php and
// dashboard_ticket_detail.php require.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Token via a custom header (not `?t=`) and the conversation id in the POST
// body: neither lands in access logs, proxy logs or browser history.
$token           = (string) ($_SERVER['HTTP_X_CHATWOOT_PANEL_TOKEN'] ?? '');
$conversation_id = (int) ($_POST['conversation_id'] ?? 0);

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

if ($conversation_id <= 0) {
    http_response_code(400);
    echo json_encode(['found' => false, 'error' => 'invalid_conversation']);
    return;
}

$result = ChatwootConfig::resolveConversationUser($conversation_id);
if (!$result['ok']) {
    // conversation_not_found / not_configured / chatwoot_unreachable / inbox_not_allowed
    $status = ['chatwoot_unreachable' => 502, 'inbox_not_allowed' => 403][$result['error']] ?? 404;
    http_response_code($status);
    echo json_encode(['found' => false, 'error' => $result['error']]);
    return;
}

$user = $result['user'];
if (!$user) {
    echo json_encode(['found' => false]);
    return;
}

$user_name = trim((string) $user['firstname'] . ' ' . (string) $user['realname']) ?: (string) $user['name'];

echo json_encode([
    'found'      => true,
    'user_name'  => $user_name,
    'capability' => ChatwootConfig::mintCapability((int) $user['id'], $conversation_id),
    'expires_in' => ChatwootConfig::CAPABILITY_TTL,
]);
