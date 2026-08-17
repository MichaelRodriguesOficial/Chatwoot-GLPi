<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
// /ajax is still served directly (URL unchanged), same as /front.
//
// Called asynchronously (fire-and-forget) by public/js/chatwoot.js.php right
// after setUser(), to sync city/country/country_code via the REST API — the
// widget SDK sometimes does not persist these fields reliably
// (chatwoot/chatwoot#7822). No need to check the config right here: this
// only runs for someone already authenticated and viewing their own widget.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

header('Content-Type: application/json; charset=UTF-8');

$user_id = (int) Session::getLoginUserID();
if (!$user_id) {
    http_response_code(403);
    echo json_encode(['success' => false]);
    return;
}

// Rate limit: at most one sync every 6 hours per user/session, to avoid
// hitting the Chatwoot API on every single page load.
$session_key = 'chatwoot_contact_synced_at';
$now         = time();
$last_sync   = (int) ($_SESSION[$session_key] ?? 0);

if ($last_sync > 0 && ($now - $last_sync) < 6 * HOUR_TIMESTAMP) {
    echo json_encode(['success' => true, 'skipped' => true]);
    return;
}

$config = ChatwootConfig::getConfig();
if (empty($config['enabled']) || empty($config['base_url']) || empty($config['api_key']) || empty($config['account_id'])) {
    echo json_encode(['success' => false]);
    return;
}

$identity = ChatwootConfig::getUserIdentity($user_id);

$additional_attributes = array_filter([
    'city'         => $identity['city'],
    'country'      => $identity['country'],
    'country_code' => $identity['country_code'],
], static fn ($value) => $value !== '');

if (empty($additional_attributes)) {
    echo json_encode(['success' => false, 'reason' => 'no_location_data']);
    return;
}

$synced = ChatwootConfig::syncContactAttributes($config, $identity['identifier'], $additional_attributes);

// Marks it as synced even on failure (e.g., the contact doesn't exist in
// Chatwoot yet), so it doesn't keep retrying on every page until the interval passes.
$_SESSION[$session_key] = $now;

echo json_encode(['success' => $synced]);
