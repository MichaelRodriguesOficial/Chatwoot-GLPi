<?php
// GLPI 11: inc/includes.php no longer exists - bootstrap already happens
// automatically via public/index.php before this script is called.

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

require_once __DIR__ . '/../src/autoload.php';

Session::checkRight('config', UPDATE);

$config = ChatwootConfig::getInstance();

if (isset($_POST['rotate_token'])) {
    // Regenerates the Dashboard App integration token on demand — the
    // control the 1.0.0 security review found missing (MEDIUM 3): without
    // it, a leaked token could never be revoked short of manual SQL.
    $new_token = ChatwootConfig::rotateDashboardAppToken();
    $current   = ChatwootConfig::getConfig();

    if (!empty($current['base_url']) && !empty($current['account_id'])) {
        $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host      = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $panel_url = $scheme . '://' . $host . '/plugins/chatwoot/dashboard_app/dashboard_app.php?t=' . $new_token;

        $current['dashboard_app_token'] = $new_token;
        $dashboard = ChatwootConfig::registerDashboardApp($current, $panel_url);
        if ($dashboard['ok']) {
            $config->update(['id' => 1, 'dashboard_app_id' => $dashboard['id']]);
        }
    }

    Session::addMessageAfterRedirect(
        __('Dashboard panel token regenerated. The previous token no longer works.', 'chatwoot'),
        false,
        INFO
    );

    Html::redirect('/plugins/chatwoot/front/config.php');
}

if (isset($_POST['update'])) {
    $required = [
        'base_url'   => __('Chatwoot URL', 'chatwoot'),
        'account_id' => __('Account ID', 'chatwoot'),
        'inbox_id'   => __('Inbox ID', 'chatwoot'),
    ];
    // The API Access Token field is always rendered blank (the stored secret
    // is never echoed back into the page), so it's only mandatory while there
    // is no token saved yet; afterwards blank means "keep the current one".
    if (trim((string) (ChatwootConfig::getConfig()['api_key'] ?? '')) === '') {
        $required = ['api_key' => __('API Access Token', 'chatwoot')] + $required;
    }
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
    } elseif (!ChatwootConfig::isValidBaseUrl((string) $_POST['base_url'])) {
        // Same validation ajax/test.php already applied — now also enforced
        // on the actual save path, which previously forwarded an unvalidated
        // base_url straight into a curl call carrying the API Access Token
        // header (MEDIUM 4 of the 1.0.0 security review).
        Session::addMessageAfterRedirect(
            __('Invalid Chatwoot URL. Use the format https://your-chatwoot.com', 'chatwoot'),
            false,
            ERROR
        );
    } else {
        // Explicit allow-list of this form's own fields — never the whole
        // $_POST — so a stray or crafted request can't also overwrite
        // dashboard_app_token, dashboard_app_id, website_token or hmac_token
        // (LOW 7 of the 1.0.0 security review).
        $input = [
            'base_url'               => (string) $_POST['base_url'],
            'account_id'             => (string) $_POST['account_id'],
            'inbox_id'               => (string) $_POST['inbox_id'],
            'enabled'                => !empty($_POST['enabled']) ? 1 : 0,
            'widget_position'        => in_array($_POST['widget_position'] ?? '', ['left', 'right'], true) ? $_POST['widget_position'] : 'right',
            'widget_type'            => in_array($_POST['widget_type'] ?? '', ['standard', 'expanded_bubble'], true) ? $_POST['widget_type'] : 'expanded_bubble',
            'widget_launcher_title'  => (string) ($_POST['widget_launcher_title'] ?? ''),
            'widget_dark_mode'       => in_array($_POST['widget_dark_mode'] ?? '', ['auto', 'light', 'dark'], true) ? $_POST['widget_dark_mode'] : 'auto',
            'show_private_followups' => !empty($_POST['show_private_followups']) ? 1 : 0,
            'restrict_entities'      => !empty($_POST['restrict_entities']) ? 1 : 0,
            // Checklist of the account's inboxes, or — when the Chatwoot API
            // couldn't list them — a comma-separated fallback field. Nothing
            // checked is stored as '' (panel disabled everywhere), on purpose.
            'panel_inbox_ids'        => ChatwootConfig::normalizePanelInboxIds(array_merge(
                (array) ($_POST['panel_inbox_ids'] ?? []),
                explode(',', (string) ($_POST['panel_inbox_ids_manual'] ?? ''))
            )),
        ];

        // GLPI's own convention for stored secrets: a blank field means
        // "leave the current value alone", never "clear it" — the template
        // no longer re-emits the decrypted value, so this is the only way
        // the administrator can keep it unchanged (LOW 8 of the 1.0.0
        // security review).
        if (trim((string) $_POST['api_key']) !== '') {
            $input['api_key'] = (string) $_POST['api_key'];
        }

        // Website Token and HMAC Token are no longer entered manually: they are
        // fetched directly from the Chatwoot API using Account ID + Inbox ID.
        $inbox = ChatwootConfig::fetchInboxDetails(
            $input['base_url'],
            trim((string) $_POST['api_key']) !== '' ? (string) $_POST['api_key'] : (string) ChatwootConfig::getConfig()['api_key'],
            (string) $_POST['account_id'],
            (string) $_POST['inbox_id']
        );

        if ($inbox['ok']) {
            $input['website_token'] = $inbox['website_token'];
            $input['hmac_token']    = $inbox['hmac_token'];
            Session::addMessageAfterRedirect(
                __('Configuration saved. Website Token automatically fetched from the Inbox.', 'chatwoot'),
                false,
                INFO
            );
        } else {
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
            array_map('intval', $_POST['target_users'] ?? []),
            array_map('intval', $_POST['target_entities'] ?? [])
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
