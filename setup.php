<?php

use Glpi\Plugin\Hooks;

define('PLUGIN_CHATWOOT_VERSION', '1.0.0');
define('PLUGIN_CHATWOOT_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_CHATWOOT_MAX_GLPI_VERSION', '11.9.99');

function plugin_init_chatwoot(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['chatwoot'] = true;

    require_once __DIR__ . '/src/autoload.php';

    if (!Plugin::isPluginActive('chatwoot')) {
        return;
    }

    $PLUGIN_HOOKS['add_javascript']['chatwoot'][] = 'js/chatwoot.js.php';
    $PLUGIN_HOOKS['add_css']['chatwoot'][] = 'css/chatwoot.css';

    // The Chatwoot dashboard (contact photo + the "GLPI" Dashboard App panel)
    // runs on their server, without the GLPI session/cookie — so these files
    // need to be reachable without login. Each PHP endpoint is protected by
    // its own token (a signed one for the photo, an integration one for the
    // ticket panel), not by session authentication; the static css/js assets
    // of the panel don't carry any sensitive data on their own.
    // avatar.php is unrelated to the Dashboard App panel — it serves the
    // contact photo used by Chatwoot's own native "Contacts" card (filled by
    // the widget's setUser(), in chatwoot.js.php), so it's matched separately.
    //
    // Both APIs below are GLPI 11-only (this plugin officially targets GLPI
    // 11.x only — plugin_chatwoot_check_prerequisites() already blocks
    // installation on older versions). The defensive method_exists() check
    // stays here anyway, in case a future GLPI 11.x minor release ever
    // renames/removes either method.
    $public_no_auth_paths = '#^/avatar\.php$'
        . '|^/dashboard_app/(dashboard_app\.php|css/dashboard_app\.css|js/dashboard_app\.js'
        . '|dashboard_tickets\.php|dashboard_ticket_detail\.php|dashboard_document\.php)$#';
    if (class_exists(\Glpi\Http\Firewall::class) && method_exists(\Glpi\Http\Firewall::class, 'addPluginStrategyForLegacyScripts')) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'chatwoot',
            $public_no_auth_paths,
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
    if (class_exists(\Glpi\Http\SessionManager::class) && method_exists(\Glpi\Http\SessionManager::class, 'registerPluginStatelessPath')) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('chatwoot', $public_no_auth_paths);
    }

    // Lets GLPI automatically re-encrypt api_key/hmac_token whenever the
    // administrator runs "php bin/console security:change_key".
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['chatwoot'] = [
        'glpi_plugin_chatwoot_configs.api_key',
        'glpi_plugin_chatwoot_configs.hmac_token',
    ];

    Plugin::registerClass(GlpiPlugin\Chatwoot\Config::class, ['addtabon' => [\Config::class]]);

    if (Session::haveRight('config', READ)) {
        $PLUGIN_HOOKS['config_page']['chatwoot'] = 'front/config.php';
    }
}

function plugin_version_chatwoot(): array
{
    return [
        'name'         => 'Chatwoot',
        'version'      => PLUGIN_CHATWOOT_VERSION,
        'author'       => 'Michael Rodrigues',
        'license'      => 'AGPLv3+',
        'homepage'     => 'https://github.com/MichaelRodriguesOficial/Chatwoot-GLPi',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_CHATWOOT_MIN_GLPI_VERSION,
                'max' => PLUGIN_CHATWOOT_MAX_GLPI_VERSION,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_chatwoot_check_prerequisites(): bool
{
    return version_compare(PHP_VERSION, '8.1.0', '>=')
        && version_compare(GLPI_VERSION, PLUGIN_CHATWOOT_MIN_GLPI_VERSION, '>=');
}

function plugin_chatwoot_check_config(bool $verbose = false): bool
{
    return true;
}
