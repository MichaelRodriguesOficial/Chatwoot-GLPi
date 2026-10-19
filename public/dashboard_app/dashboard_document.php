<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — same explanation
// as dashboard_tickets.php. Serves images embedded in the text and
// attachments, but only after confirming (in Config::streamTicketDocumentForUser)
// that the document really belongs to a ticket of the capability-bound user.
//
// The panel (dashboard_app.js) fetches this endpoint with the token and
// capability as headers, same as the other two data endpoints, and turns the
// response into a blob URL for <img>/<a> — so neither secret ever appears in
// a URL, GLPI's access log, a reverse proxy log, or browser history
// (MEDIUM 3 of the 1.0.0 security review).

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

$token      = (string) ($_SERVER['HTTP_X_CHATWOOT_PANEL_TOKEN'] ?? ($_GET['t'] ?? ''));
$capability = (string) ($_SERVER['HTTP_X_CHATWOOT_CAPABILITY'] ?? ($_GET['cap'] ?? ''));
$ticket_id  = (int) ($_GET['ticket_id'] ?? 0);
$doc_id     = (int) ($_GET['doc'] ?? 0);

if (!ChatwootConfig::isValidDashboardAppToken($token)) {
    http_response_code(403);
    return;
}

$user = ChatwootConfig::verifyCapability($capability);
if (!$user) {
    http_response_code(401);
    return;
}

try {
    $file = ChatwootConfig::streamTicketDocumentForUser((int) $user['id'], $ticket_id, $doc_id);
} catch (\Throwable $e) {
    http_response_code(500);
    return;
}

if ($file === null) {
    http_response_code(404);
    return;
}

// Delegates to GLPI core's own Toolbox::getFileAsResponse() whenever
// available (inline allow-list, Content-Disposition encoding, traversal
// safety) — see Config::serveDocument() (HIGH 2 of the 1.0.0 review: this
// used to send `Content-Disposition: inline` unconditionally for every mime
// type, including attacker-controlled `text/html`).
ChatwootConfig::serveDocument($file);
