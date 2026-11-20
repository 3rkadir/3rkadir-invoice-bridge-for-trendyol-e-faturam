<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class Installer
{
    public const DB_VERSION = '1.0.0';

    public static function activate(): void
    {
        if (version_compare(PHP_VERSION, '8.1', '<')) {
            deactivate_plugins(TY_E_FATURAM_BASENAME);
            wp_die(
                esc_html__('Bu eklenti için PHP 8.1 veya üzeri gereklidir.', 'ty-e-faturam'),
                '',
                ['back_link' => true]
            );
        }
        self::createTables();
        if (get_option(Settings::OPTION) === false) {
            add_option(Settings::OPTION, Settings::defaults());
        }
        update_option('tef_db_version', self::DB_VERSION);
        if (!wp_next_scheduled(Cron::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', Cron::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Cron::HOOK);
    }

    public static function maybeUpgrade(): void
    {
        if (get_option('tef_db_version') !== self::DB_VERSION) {
            self::createTables();
            update_option('tef_db_version', self::DB_VERSION);
        }
        if (!wp_next_scheduled(Cron::HOOK)) {
            wp_schedule_event(time() + 300, 'hourly', Cron::HOOK);
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $logs = $wpdb->prefix . 'tef_logs';
        $invoices = $wpdb->prefix . 'tef_invoices';

        dbDelta("CREATE TABLE {$logs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  method varchar(10) NOT NULL DEFAULT '',
  endpoint varchar(255) NOT NULL DEFAULT '',
  http_code smallint(5) unsigned NOT NULL DEFAULT 0,
  is_success tinyint(1) NOT NULL DEFAULT 0,
  response longtext NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY is_success (is_success),
  KEY created_at (created_at)
) {$charset};");

        dbDelta("CREATE TABLE {$invoices} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  invoice_uuid varchar(64) NOT NULL DEFAULT '',
  invoice_number varchar(64) NOT NULL DEFAULT '',
  invoice_type varchar(20) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  remote_status varchar(32) NOT NULL DEFAULT '',
  gib_status varchar(64) NOT NULL DEFAULT '',
  pdf_url text NULL,
  error_message text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_id (order_id),
  KEY invoice_uuid (invoice_uuid),
  KEY status (status)
) {$charset};");
    }
}
