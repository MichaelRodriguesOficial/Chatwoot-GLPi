<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.
//
// This file is INTENTIONALLY public (no login required) — see the
// Firewall::STRATEGY_NO_CHECK + SessionManager::registerPluginStatelessPath
// registration in setup.php, and the explanation in ../dashboard_tickets.php.
// Everything specific to this panel (HTML shell, CSS, JS, translations)
// lives together in this folder for easier maintenance.

require_once dirname(__DIR__, 2) . '/src/autoload.php';

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$token          = (string) ($_GET['t'] ?? '');
$is_valid       = ChatwootConfig::isValidDashboardAppToken($token);
$status_options = [];
$en_dict        = [];
$pt_dict        = [];
$locale         = 'en';

if ($is_valid) {
    // Translations for this panel's own fixed labels live as plain JSON next
    // to this file (locales/en.json, locales/pt_BR.json) — kept separate
    // from GLPI's own gettext locales/ folder at the plugin root, since this
    // panel is a standalone page outside GLPI's template engine and doesn't
    // go through gettext at all.
    $en_json = @file_get_contents(__DIR__ . '/locales/en.json');
    $pt_json = @file_get_contents(__DIR__ . '/locales/pt_BR.json');
    $en_dict = $en_json !== false ? (json_decode($en_json, true) ?: []) : [];
    $pt_dict = $pt_json !== false ? (json_decode($pt_json, true) ?: []) : [];

    // Language is 100% automatic — driven only by the Chatwoot account's own
    // configured language (Config::getAccountLocale(), confirmed working via
    // GET /api/v1/accounts/{account_id}), never by GLPI's own language
    // setting (a completely separate system) and with no manual override in
    // the panel. Rendered server-side so the very first HTML already comes
    // in the right language — nothing to swap client-side, so there's no way
    // for part of the page to end up in the "wrong" language after a switch.
    $config = ChatwootConfig::getConfig();
    $locale = ChatwootConfig::getAccountLocale($config) === 'pt_BR' ? 'pt_BR' : 'en';

    // Must run BEFORE ticketStatusOptions() below — that method calls GLPI's
    // own __(), which only picks up the language switched here if it already
    // ran first. This was the missing piece that left the status dropdown
    // showing GLPI's default system language instead of following the panel.
    ChatwootConfig::applyRequestLanguage($locale);

    $status_options = ChatwootConfig::ticketStatusOptions();
}

$dict = $locale === 'pt_BR' ? $pt_dict : $en_dict;

function chatwoot_dashboard_t(array $dict, string $key, string $fallback): string
{
    return (string) ($dict[$key] ?? $fallback);
}

$dicts_json = json_encode(
    ['en' => $en_dict, 'pt_BR' => $pt_dict],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
);

// Cache-busting: forces the browser to fetch a fresh copy of the CSS/JS
// whenever the plugin version changes, instead of possibly serving a stale
// cached copy from before an update.
$asset_version = defined('PLUGIN_CHATWOOT_VERSION') ? PLUGIN_CHATWOOT_VERSION : (string) time();
?>
<!DOCTYPE html>
<html lang="<?php echo $locale === 'pt_BR' ? 'pt-BR' : 'en'; ?>">
<head>
<meta charset="UTF-8">
<title>Tickets - GLPI</title>
<link rel="stylesheet" href="css/dashboard_app.css?v=<?php echo urlencode($asset_version); ?>">
</head>
<body data-token="<?php echo htmlspecialchars($token, ENT_QUOTES); ?>" data-locale="<?php echo htmlspecialchars($locale, ENT_QUOTES); ?>">

<?php if (!$is_valid): ?>
<div class="cw-error" id="cw-invalid-token">Invalid or unconfigured integration token. Save the plugin configuration again under Setup &gt; Chatwoot.</div>
<?php else: ?>

<div id="cw-list-view">
  <h2 id="cw-requester-title"><?php echo htmlspecialchars(chatwoot_dashboard_t($dict, 'requester', 'Requester')); ?></h2>
  <div id="cw-subtitle" class="cw-subtitle"><?php echo htmlspecialchars(chatwoot_dashboard_t($dict, 'waiting_data', 'Waiting for conversation data…')); ?></div>

  <div class="cw-toolbar">
    <input type="text" class="cw-search" id="cw-search" placeholder="<?php echo htmlspecialchars(chatwoot_dashboard_t($dict, 'search_placeholder', 'Search by title or ticket number…')); ?>" disabled>
    <select class="cw-status" id="cw-status" disabled>
      <option value="0" id="cw-status-all"><?php echo htmlspecialchars(chatwoot_dashboard_t($dict, 'all_statuses', 'All statuses')); ?></option>
      <?php foreach ($status_options as $id => $label): ?>
      <option value="<?php echo (int) $id; ?>" <?php echo $id === 2 ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="cw-count" class="cw-count" style="display:none;"></div>
  <div id="cw-list"></div>
</div>

<div id="cw-detail-view" style="display:none;"></div>

<script>window.__cwDictionaries = <?php echo $dicts_json; ?>;</script>
<script src="js/dashboard_app.js?v=<?php echo urlencode($asset_version); ?>"></script>

<?php endif; ?>
</body>
</html>
