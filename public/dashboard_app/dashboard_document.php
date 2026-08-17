<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — same explanation
// as public/dashboard_tickets.php. Serves images embedded in the text and
// attachments, but only after confirming (in Config::streamTicketDocument)
// that the document really belongs to a ticket of the identified person.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

$token      = (string) ($_GET['t'] ?? '');
$identifier = trim((string) ($_GET['identifier'] ?? ''));
$phone      = trim((string) ($_GET['phone'] ?? ''));
$ticket_id  = (int) ($_GET['ticket_id'] ?? 0);
$doc_id     = (int) ($_GET['doc'] ?? 0);

if (!ChatwootConfig::isValidDashboardAppToken($token)) {
    http_response_code(403);
    return;
}

try {
    $file = ChatwootConfig::streamTicketDocument($ticket_id, $identifier, $doc_id, $phone);
} catch (\Throwable $e) {
    http_response_code(500);
    return;
}

if ($file === null) {
    http_response_code(404);
    return;
}

header('Content-Type: ' . $file['mime']);
header('Content-Disposition: inline; filename="' . rawurlencode($file['name']) . '"');
header('Cache-Control: private, max-age=3600');
header('Content-Length: ' . filesize($file['path']));
readfile($file['path']);
