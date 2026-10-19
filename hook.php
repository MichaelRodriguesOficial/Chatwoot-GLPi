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
            `dashboard_app_token` text DEFAULT NULL,
            `dashboard_app_id` int unsigned DEFAULT NULL,
            `show_private_followups` tinyint NOT NULL DEFAULT '0',
            `restrict_entities` tinyint NOT NULL DEFAULT '0',
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
        // Upgrade path for sites coming from the 1.0.0 release (the only
        // version ever published) to this security-hardened release.

        // 1.0.0 stored dashboard_app_token as a short plaintext `string`
        // column; it now needs to hold an encrypted (and therefore longer)
        // value, so it's widened to `text` alongside being added to
        // ENCRYPTED_FIELDS/SECURED_FIELDS.
        if ($DB->fieldExists($table, 'dashboard_app_token')) {
            $migration->changeField($table, 'dashboard_app_token', 'dashboard_app_token', 'text');
        } else {
            $migration->addField($table, 'dashboard_app_token', 'text', ['after' => 'widget_dark_mode']);
        }
        if (!$DB->fieldExists($table, 'dashboard_app_id')) {
            $migration->addField($table, 'dashboard_app_id', 'integer', ['after' => 'dashboard_app_token']);
        }

        // Opt-in: whether internal/private follow-ups are exposed to Chatwoot
        // agents through the Dashboard App panel. Defaults to disabled —
        // previous versions always exposed them, which is no longer the case.
        if (!$DB->fieldExists($table, 'show_private_followups')) {
            $migration->addField($table, 'show_private_followups', 'bool', ['value' => 0, 'after' => 'dashboard_app_id']);
        }

        // Opt-in: whether the panel's ticket queries are restricted to the
        // entities selected under "Who can see the widget" > Entities.
        if (!$DB->fieldExists($table, 'restrict_entities')) {
            $migration->addField($table, 'restrict_entities', 'bool', ['value' => 0, 'after' => 'show_private_followups']);
        }
    }

    plugin_chatwoot_migrate_legacy_config($table);

    plugin_chatwoot_create_targets_table();

    plugin_chatwoot_create_access_log_table();

    plugin_chatwoot_register_crontask();

    $migration->executeMigration();

    return true;
}

/**
 * Lightweight access log for the unauthenticated Dashboard App endpoints —
 * used both to throttle identity-resolution attempts (dashboard_identify.php)
 * and to give administrators an audit trail of who was looked up and from
 * where, since these endpoints have no GLPI session to log against.
 * Rows older than a few hours are pruned opportunistically (see
 * Config::pruneAccessLog()), so this table never grows unbounded.
 */
function plugin_chatwoot_create_access_log_table(): void
{
    global $DB;

    $table = 'glpi_plugin_chatwoot_access_log';
    if ($DB->tableExists($table)) {
        return;
    }

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();

    $query = "CREATE TABLE `$table` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `ip` varchar(45) NOT NULL DEFAULT '',
        `context` varchar(32) NOT NULL DEFAULT '',
        `matched` tinyint NOT NULL DEFAULT '0',
        PRIMARY KEY (`id`),
        KEY `ip_created` (`ip`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
    $DB->doQuery($query);
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

    if ($DB->tableExists('glpi_plugin_chatwoot_access_log')) {
        $DB->doQuery('DROP TABLE `glpi_plugin_chatwoot_access_log`');
    }

    // Clean up any leftovers from versions <= 1.0.0.
    $config = new \Config();
    $config->deleteByCriteria(['context' => 'plugin:chatwoot']);

    return true;
}
