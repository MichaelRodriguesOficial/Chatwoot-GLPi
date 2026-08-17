<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

Session::checkRight('config', UPDATE);

$config = ChatwootConfig::getInstance();

if (isset($_POST['update'])) {
    $required = [
        'base_url'   => __('Chatwoot URL', 'chatwoot'),
        'api_key'    => __('API Access Token', 'chatwoot'),
        'account_id' => __('Account ID', 'chatwoot'),
        'inbox_id'   => __('Inbox ID', 'chatwoot'),
    ];
    $missing = [];
    foreach ($required as $field => $label) {
        if (empty($_POST[$field])) {
            $missing[] = $label;
        }
    }

    if (!empty($missing)) {
        Session::addMessageAfterRedirect(
            sprintf(__('Please fill in the required fields: %s.', 'chatwoot'), implode(', ', $missing)),
            false,
            ERROR
        );
    } else {
        $input = $_POST;

        // Website Token and HMAC Token are no longer entered manually: they are
        // fetched directly from the Chatwoot API using Account ID + Inbox ID.
        $inbox = ChatwootConfig::fetchInboxDetails(
            (string) $_POST['base_url'],
            (string) $_POST['api_key'],
            (string) $_POST['account_id'],
            (string) $_POST['inbox_id']
        );

        if ($inbox['ok']) {
            $input['website_token'] = $inbox['website_token'];
            $input['hmac_token']    = $inbox['hmac_token'];
            $input['widget_script'] = $inbox['widget_script'];
            Session::addMessageAfterRedirect(
                __('Configuration saved. Website Token and widget script automatically fetched from the Inbox.', 'chatwoot'),
                false,
                INFO
            );
        } else {
            unset($input['website_token'], $input['hmac_token'], $input['widget_script']);
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Configuration saved, but could not fetch the Inbox data: %s', 'chatwoot'),
                    $inbox['error']
                ),
                false,
                WARNING
            );
        }

        $config->update($input + ['id' => 1]);

        ChatwootConfig::saveTargets(
            array_map('intval', $_POST['target_profiles'] ?? []),
            array_map('intval', $_POST['target_groups'] ?? []),
            array_map('intval', $_POST['target_users'] ?? [])
        );

        // Automatically registers the "GLPI" panel under Integrations >
        // Dashboard Apps in Chatwoot, pointing to our own ticket panel —
        // only when the connection to the Inbox succeeded.
        if ($inbox['ok']) {
            $current = ChatwootConfig::getConfig();
            $dashboard_token = ChatwootConfig::ensureDashboardAppToken($current);
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
            $panel_url = $scheme . '://' . $host . '/plugins/chatwoot/dashboard_app/dashboard_app.php?t=' . $dashboard_token;

            $current['dashboard_app_token'] = $dashboard_token;
            $dashboard = ChatwootConfig::registerDashboardApp($current, $panel_url);

            if ($dashboard['ok']) {
                $config->update(['id' => 1, 'dashboard_app_id' => $dashboard['id']]);
                Session::addMessageAfterRedirect(
                    __('The "GLPI" panel was registered under Integrations > Dashboard Apps in Chatwoot.', 'chatwoot'),
                    false,
                    INFO
                );
            } else {
                Session::addMessageAfterRedirect(
                    sprintf(__('Could not register the ticket panel in Chatwoot: %s', 'chatwoot'), $dashboard['error']),
                    false,
                    WARNING
                );
            }
        }
    }
}

Html::redirect('/plugins/chatwoot/front/config.php');
