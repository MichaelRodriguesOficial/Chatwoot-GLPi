<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
// This file lives in public/js/, so the plugin root is 2 levels up.
require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: application/javascript; charset=UTF-8');
// Prevents the browser (or any proxy in between) from caching an old copy of
// this script: the content is built from the database on every request.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$c = ChatwootConfig::getConfig();

if (empty($c['enabled']) || empty($c['base_url']) || empty($c['website_token'])) {
    echo "/* Chatwoot: disabled or unconfigured. */\n";
    return;
}

$user_id = (int) Session::getLoginUserID();
if (!$user_id) {
    echo "/* Chatwoot: anonymous page. */\n";
    return;
}

if (!ChatwootConfig::canCurrentUserSeeWidget()) {
    echo "/* Chatwoot: user not in the allowed list. */\n";
    return;
}

$base_url      = rtrim((string) $c['base_url'], '/');
$website_token = (string) $c['website_token'];
$widget_script = trim((string) ($c['widget_script'] ?? ''));

// --- User identification (setUser + identifier_hash) ---
// identifier_hash only exists if the Inbox has "Identity Validation" enabled
// in Chatwoot (the hmac_token comes from the same auto-fetch as the Website
// Token). Without it, Chatwoot shows "this user's identity has not been verified".
$identity = ChatwootConfig::getUserIdentity($user_id);

$hmac_token      = (string) ($c['hmac_token'] ?? '');
$identifier_hash = $hmac_token !== '' ? hash_hmac('sha256', $identity['identifier'], $hmac_token) : '';

// city/country_code also go into setUser (it's the format documented by
// Chatwoot's own SDK), even knowing it sometimes doesn't persist on its own
// (see ajax/sync_contact.php below, which works around that via the API).
$identify_payload = array_filter([
    'name'            => $identity['name'],
    'email'           => $identity['email'],
    'phone_number'    => $identity['phone'],
    'avatar_url'      => $identity['avatar_url'],
    'city'            => $identity['city'],
    'country'         => $identity['country'],
    'country_code'    => $identity['country_code'],
    'identifier_hash' => $identifier_hash,
], static fn ($value) => $value !== '');

$identifier_json = json_encode($identity['identifier'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$identify_json   = json_encode(
    $identify_payload,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
// CSRF token for the sync fetch() below — without it, GLPI itself might
// reject the POST before it even reaches our endpoint (the plugin is
// registered as csrf_compliant).
$csrf_json = json_encode(Session::getNewCSRFToken(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// The Chatwoot Inbox API does not return the Widget Builder options
// (position, bubble type, launcher title) — only the basic loader. Those
// options are configured manually in Setup > Chatwoot and applied here, on
// top of the script auto-fetched from the API.
$settings = array_filter([
    'position'      => (string) ($c['widget_position'] ?: 'right'),
    'type'          => (string) ($c['widget_type'] ?: 'expanded_bubble'),
    'launcherTitle' => (string) ($c['widget_launcher_title'] ?? ''),
    'darkMode'      => (string) ($c['widget_dark_mode'] ?: 'auto'),
], static fn ($value) => $value !== '');

$settings_json = json_encode(
    $settings,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
if (!window.__glpiChatwootLoaded) {
    window.__glpiChatwootLoaded = true;
    window.chatwootSettings = <?php echo $settings_json; ?>;

    window.addEventListener('chatwoot:ready', function () {
        if (!window.$chatwoot) return;
        window.$chatwoot.setUser(<?php echo $identifier_json; ?>, <?php echo $identify_json; ?>);

        // The SDK sometimes doesn't reliably persist city/country/country_code
        // on the contact record (known Chatwoot bug:
        // github.com/chatwoot/chatwoot/issues/7822). We call, without
        // blocking the page, an endpoint of ours that syncs this via the REST
        // API — the endpoint itself already avoids repeating the call every
        // time (it only runs again after a while).
        fetch('<?php echo json_encode('/plugins/chatwoot/ajax/sync_contact.php', JSON_UNESCAPED_SLASHES); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'identifier=' + encodeURIComponent(<?php echo $identifier_json; ?>) + '&_glpi_csrf_token=' + encodeURIComponent(<?php echo $csrf_json; ?>)
        }).catch(function () { /* best effort; fine if it fails */ });
    });
<?php if ($widget_script !== ''): ?>
    <?php
    // The content below is exactly what the Chatwoot API returned for this
    // Inbox (the web_widget_script field), only with the <script> tags
    // removed — GLPI does not rewrite or reinterpret this loading logic, so
    // future changes to the embed format arrive here automatically on the
    // next sync, without needing a plugin update.
    echo $widget_script;
    ?>
<?php else: ?>
    // Safety fallback: we don't have the official script cached yet
    // (e.g., just installed and the initial fetch failed). Equivalent basic
    // loading, without blocking the widget.
    (function () {
        var s = document.createElement('script');
        s.src = <?php echo json_encode($base_url, JSON_UNESCAPED_SLASHES); ?> + '/packs/js/sdk.js';
        s.async = true;
        s.onload = function () {
            if (window.chatwootSDK) {
                window.chatwootSDK.run({
                    websiteToken: <?php echo json_encode($website_token); ?>,
                    baseUrl: <?php echo json_encode($base_url, JSON_UNESCAPED_SLASHES); ?>
                });
            }
        };
        document.head.appendChild(s);
    })();
<?php endif; ?>
}
