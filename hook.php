<?php

use GlpiPlugin\Chatwoot\Config as ChatwootConfig;

function plugin_chatwoot_install(): bool
{
    global $DB;

    require_once __DIR__ . '/src/autoload.php';

    $migration = new Migration(PLUGIN_CHATWOOT_VERSION);
    $table     = ChatwootConfig::getTable();

    if (!$DB->tableExists($table)) {
        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();

        // GLPI 11 forbids DBmysql::query()/queryOrDie(); doQuery() is the
        // safe method for "raw" SQL (the text is fixed, no user data interpolation).
        $query = "CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `enabled` tinyint NOT NULL DEFAULT '0',
            `base_url` varchar(255) NOT NULL DEFAULT '',
            `account_id` varchar(64) NOT NULL DEFAULT '',
            `inbox_id` varchar(64) NOT NULL DEFAULT '',
            `api_key` text DEFAULT NULL,
            `website_token` varchar(255) NOT NULL DEFAULT '',
            `hmac_token` text DEFAULT NULL,
            `widget_script` text DEFAULT NULL,
            `widget_position` varchar(16) NOT NULL DEFAULT 'right',
            `widget_type` varchar(32) NOT NULL DEFAULT 'expanded_bubble',
            `widget_launcher_title` varchar(255) NOT NULL DEFAULT '',
            `widget_dark_mode` varchar(16) NOT NULL DEFAULT 'auto',
            `dashboard_app_token` varchar(64) NOT NULL DEFAULT '',
            `dashboard_app_id` int unsigned DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query);

        $DB->insert($table, [
            'id'            => 1,
            'enabled'       => 0,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    } else {
        // Upgrade from a previous version (<= 2.7.0): store the official embed
        // script returned by the Chatwoot API, so we always use its latest
        // version (instead of rebuilding the loading logic ourselves).
        if (!$DB->fieldExists($table, 'widget_script')) {
            $migration->addField($table, 'widget_script', 'text', ['after' => 'hmac_token']);
        }

        // Upgrade from a previous version (<= 2.8.0): the Chatwoot Inbox API
        // does not return the Widget Builder options (position, type,
        // launcher title) — they need to be configured manually and applied
        // on top of the auto-fetched script.
        if (!$DB->fieldExists($table, 'widget_position')) {
            $migration->addField($table, 'widget_position', 'string', ['value' => 'right', 'after' => 'widget_script']);
        }
        if (!$DB->fieldExists($table, 'widget_type')) {
            $migration->addField($table, 'widget_type', 'string', ['value' => 'expanded_bubble', 'after' => 'widget_position']);
        }
        if (!$DB->fieldExists($table, 'widget_launcher_title')) {
            $migration->addField($table, 'widget_launcher_title', 'string', ['after' => 'widget_type']);
        }

        // Upgrade from a previous version (<= 3.8.0): widget dark mode.
        if (!$DB->fieldExists($table, 'widget_dark_mode')) {
            $migration->addField($table, 'widget_dark_mode', 'string', ['value' => 'auto', 'after' => 'widget_launcher_title']);
        }

        // Upgrade from a previous version (<= 3.9.0): "GLPI" panel in
        // Chatwoot's Dashboard Apps, showing the tickets of the person
        // identified in the conversation.
        if (!$DB->fieldExists($table, 'dashboard_app_token')) {
            $migration->addField($table, 'dashboard_app_token', 'string', ['after' => 'widget_dark_mode']);
        }
        if (!$DB->fieldExists($table, 'dashboard_app_id')) {
            $migration->addField($table, 'dashboard_app_id', 'integer', ['after' => 'dashboard_app_token']);
        }
    }

    plugin_chatwoot_migrate_legacy_config($table);

    plugin_chatwoot_create_targets_table();

    plugin_chatwoot_register_crontask();

    $migration->executeMigration();

    return true;
}

/**
 * Permission table: who (profile, group, or user) can see the widget.
 * Empty = no restriction, visible to every authenticated user.
 */
function plugin_chatwoot_create_targets_table(): void
{
    global $DB;

    $table = 'glpi_plugin_chatwoot_targets';
    if ($DB->tableExists($table)) {
        return;
    }

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    $query = "CREATE TABLE `$table` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `itemtype` varchar(100) NOT NULL,
        `items_id` int unsigned NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unicity` (`itemtype`, `items_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
    $DB->doQuery($query);
}

/**
 * Registers the automatic action that keeps the Website Token and HMAC Token
 * up to date, fetching them periodically from the Chatwoot API (once an hour
 * by default — the interval can be adjusted in Setup > Maintenance >
 * Automatic actions). No need to unregister on uninstall: GLPI already
 * removes the plugin's automatic actions by itself.
 */
function plugin_chatwoot_register_crontask(): void
{
    $cron = new \CronTask();
    if ($cron->getFromDBbyName(ChatwootConfig::class, 'refreshtoken')) {
        return;
    }

    \CronTask::register(
        ChatwootConfig::class,
        'refreshtoken',
        HOUR_TIMESTAMP,
        [
            'comment' => __('Keeps the Chatwoot Website Token and HMAC Token automatically in sync.', 'chatwoot'),
            'mode'    => \CronTask::MODE_INTERNAL,
        ]
    );
}

/**
 * Copies the settings saved by plugin <= 1.0.0 (core glpi_configs table,
 * "plugin:chatwoot" context) into the new dedicated table and removes the old trace.
 */
function plugin_chatwoot_migrate_legacy_config(string $table): void
{
    global $DB;

    if (!$DB->tableExists('glpi_configs')) {
        return;
    }

    $legacy = \Config::getConfigurationValues('plugin:chatwoot');
    if (empty($legacy)) {
        return;
    }

    $map = [
        'chatwoot_enabled'       => 'enabled',
        'chatwoot_base_url'      => 'base_url',
        'chatwoot_account_id'    => 'account_id',
        'chatwoot_inbox_id'      => 'inbox_id',
        'chatwoot_api_key'       => 'api_key',
        'chatwoot_website_token' => 'website_token',
        'chatwoot_hmac_token'    => 'hmac_token',
    ];

    $glpikey = new GLPIKey();
    $update  = [];

    foreach ($map as $old => $new) {
        if (!isset($legacy[$old]) || $legacy[$old] === '') {
            continue;
        }
        $value = $legacy[$old];
        if (in_array($new, ChatwootConfig::ENCRYPTED_FIELDS, true)) {
            $value = $glpikey->encrypt((string) $value);
        }
        $update[$new] = $value;
    }

    if (!empty($update)) {
        $DB->update($table, $update, ['id' => 1]);
    }

    \Config::deleteConfigurationValues('plugin:chatwoot', array_keys($legacy));
}

function plugin_chatwoot_uninstall(): bool
{
    global $DB;

    require_once __DIR__ . '/src/autoload.php';

    $table = ChatwootConfig::getTable();
    if ($DB->tableExists($table)) {
        $DB->doQuery("DROP TABLE `$table`");
    }

    if ($DB->tableExists('glpi_plugin_chatwoot_targets')) {
        $DB->doQuery('DROP TABLE `glpi_plugin_chatwoot_targets`');
    }

    // Clean up any leftovers from versions <= 1.0.0.
    $config = new \Config();
    $config->deleteByCriteria(['context' => 'plugin:chatwoot']);

    return true;
}
