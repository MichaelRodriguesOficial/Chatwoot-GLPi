<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — same explanation
// as dashboard_tickets.php. Serves images embedded in the text and
// attachments of the ticket the agent is viewing in the panel.
//
// Authorized by a per-document link (`?d=`) minted by
// Config::mintDocumentLink() when the ticket detail was loaded: bound to one
// GLPI user, one ticket and one document, valid for a few minutes, and
// unforgeable (GLPIKey authenticated encryption). The panel can therefore
// use plain <img src>/<a href target="_blank"> links, exactly like 1.0.0,
// without the instance-wide integration token or the capability ever
// appearing in a URL (MEDIUM 3 of the 1.0.0 security review).
//
// Even with a valid link, Config::streamTicketDocumentForUser() re-checks on
// every call that the ticket still belongs to that user (as requester, in an
// allowed entity) and that the document is really linked to that ticket.

// This file lives in public/dashboard_app/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

$link = ChatwootConfig::verifyDocumentLink((string) ($_GET['d'] ?? ''));
if ($link === null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    // No account language is known here (the link alone authorizes the
    // request), so the message covers both languages the panel ships with.
    echo "Link expired or invalid. Reopen the ticket in the panel.\n"
        . "Link expirado ou inválido. Abra o chamado novamente no painel.\n";
    return;
}

try {
    $file = ChatwootConfig::streamTicketDocumentForUser($link['uid'], $link['tid'], $link['did']);
} catch (\Throwable $e) {
    http_response_code(500);
    return;
}

if ($file === null) {
    http_response_code(404);
    return;
}

// Inline only for GLPI core's own allow-list (images except SVG, PDF),
// `attachment` for everything else, always `nosniff` — see
// Config::serveDocument() (HIGH 2 of the 1.0.0 review).
ChatwootConfig::serveDocument($file);
