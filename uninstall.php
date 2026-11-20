<?php
/**
 * Eklenti silinirken çalışır. Veriler yalnızca ayarlarda "Kaldırırken Veriyi Sil" seçiliyse temizlenir.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Kaldırma sırasında eklentinin kendi tabloları silinir.

// Değişkenler global kapsamı kirletmesin diye anonim fonksiyon içinde çalışır.
(static function (): void {
    global $wpdb;

    $settings = get_option('tef_settings');
    $delete = is_array($settings) && isset($settings['delete_data']) && $settings['delete_data'] === 'yes';

    wp_clear_scheduled_hook('tef_hourly_status_check');

    if (!$delete) {
        return;
    }

    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tef_logs'));
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . 'tef_invoices'));

    delete_option('tef_settings');
    delete_option('tef_db_version');
    delete_option('tef_account_test');
    delete_option('tef_account_live');
    delete_option('tef_signin_variant_test');
    delete_option('tef_signin_variant_live');

    $like = $wpdb->esc_like('_tef_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like));

    $hpos = $wpdb->prefix . 'wc_orders_meta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos)) === $hpos) {
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $hpos, $like));
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_tef_') . '%',
            $wpdb->esc_like('_transient_timeout_tef_') . '%',
            $wpdb->esc_like('_site_transient_tef_') . '%',
            $wpdb->esc_like('_site_transient_timeout_tef_') . '%',
            $wpdb->esc_like('tef_lock_') . '%',
            $wpdb->esc_like('tef_tp_') . '%'
        )
    );
})();
