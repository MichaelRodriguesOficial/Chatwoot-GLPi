<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — same explanation
// as public/dashboard_tickets.php. Only returns the ticket if it really
// belongs to the identified person as requester.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/json; charset=UTF-8');

$token      = (string) ($_GET['t'] ?? '');
$identifier = trim((string) ($_GET['identifier'] ?? ''));
$phone      = trim((string) ($_GET['phone'] ?? ''));
$ticket_id  = (int) ($_GET['ticket_id'] ?? 0);
$lang       = trim((string) ($_GET['lang'] ?? ''));

if (!ChatwootConfig::isValidDashboardAppToken($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_token']);
    return;
}

// Matches the ticket status/priority/solution labels to the language the
// panel already resolved from the Chatwoot account — see
// Config::applyRequestLanguage() for why this is safe (no real session touched).
if ($lang !== '') {
    ChatwootConfig::applyRequestLanguage($lang);
}

try {
    $ticket = ChatwootConfig::getTicketDetail($ticket_id, $identifier, $phone);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['found' => false, 'error' => 'internal_error']);
    return;
}

if ($ticket === null) {
    http_response_code(404);
    echo json_encode(['found' => false]);
    return;
}

echo json_encode(['found' => true, 'ticket' => $ticket]);
