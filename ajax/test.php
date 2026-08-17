<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
// /ajax is still served directly (URL unchanged), same as /front.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

Session::checkRight('config', UPDATE);

header('Content-Type: application/json; charset=UTF-8');

/**
 * GLPI invalidates the CSRF token after it is used (replay protection).
 * Since this endpoint is called several times without reloading the page, we
 * return a fresh token in every response for the JS to reuse on the next
 * call (and also for the Save button, keeping the form's token always valid).
 */
function plugin_chatwoot_json_response(array $payload): void
{
    $payload['csrf_token'] = Session::getNewCSRFToken();
    echo json_encode($payload);
}

$base_url   = trim((string) ($_POST['base_url'] ?? ''));
$api_key    = trim((string) ($_POST['api_key'] ?? ''));
$account_id = trim((string) ($_POST['account_id'] ?? ''));
$inbox_id   = trim((string) ($_POST['inbox_id'] ?? ''));

if ($base_url === '' || $api_key === '' || $account_id === '' || $inbox_id === '') {
    plugin_chatwoot_json_response([
        'success' => false,
        'message' => __('Fill in URL, API Access Token, Account ID and Inbox ID before testing.', 'chatwoot'),
    ]);
    return;
}

if (!preg_match('#^https?://#i', $base_url) || !filter_var($base_url, FILTER_VALIDATE_URL)) {
    plugin_chatwoot_json_response([
        'success' => false,
        'message' => __('Invalid Chatwoot URL. Use the format https://your-chatwoot.com', 'chatwoot'),
    ]);
    return;
}

// A single test already validates URL + API Access Token + Account ID + Inbox
// ID, since it is exactly what Save uses to fetch the Website Token.
$result = ChatwootConfig::fetchInboxDetails($base_url, $api_key, $account_id, $inbox_id);
if (!$result['ok']) {
    // Try once more: avoids reporting an error because of a one-off network blip.
    $result = ChatwootConfig::fetchInboxDetails($base_url, $api_key, $account_id, $inbox_id);
}

if (!$result['ok']) {
    plugin_chatwoot_json_response([
        'success' => false,
        'message' => $result['error'],
    ]);
    return;
}

// "Test connection" also syncs: if something changed on the Chatwoot side
// (token, HMAC, widget script), the saved configuration is updated right
// here, without needing to click Save afterwards.
$before = ChatwootConfig::getConfig();
$changed = (
    ($before['website_token'] ?? '') !== $result['website_token']
    || ($before['hmac_token'] ?? '') !== $result['hmac_token']
    || trim((string) ($before['widget_script'] ?? '')) !== $result['widget_script']
);

$item = ChatwootConfig::getInstance();
$item->update([
    'id'            => 1,
    'website_token' => $result['website_token'],
    'hmac_token'    => $result['hmac_token'],
    'widget_script' => $result['widget_script'],
]);

$suffix = $result['name'] !== '' ? sprintf(__(' Inbox: "%s".', 'chatwoot'), $result['name']) : '';
$base_message = $changed
    ? __('Connection successful. I detected changes in the Inbox and automatically updated the configuration.', 'chatwoot')
    : __('Connection successful. No changes detected — everything is already in sync.', 'chatwoot');

plugin_chatwoot_json_response([
    'success' => true,
    'message' => $base_message . $suffix,
]);
