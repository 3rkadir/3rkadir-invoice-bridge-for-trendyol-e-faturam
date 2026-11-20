<?php
/**
 * Plugin Name:       TY E-Faturam
 * Plugin URI:        https://developers.trendyolefaturam.com/docs
 * Description:       Creates e-Invoices / e-Archive invoices from store orders, automatically or manually, using the TY E-Faturam API.
 * Version:           1.4.3
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Kadir Er
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ty-e-faturam
 * Domain Path:       /languages
 * WC requires at least: 8.0
 */

defined('ABSPATH') || exit;

/**
 * @author Kadir Er <3rkadir@gmail.com>
 */

define('TY_E_FATURAM_VERSION', '1.4.3');
define('TY_E_FATURAM_FILE', __FILE__);
define('TY_E_FATURAM_DIR', plugin_dir_path(__FILE__));
define('TY_E_FATURAM_URL', plugin_dir_url(__FILE__));
define('TY_E_FATURAM_BASENAME', plugin_basename(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'TYEfaturam\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $parts = explode('\\', substr($class, strlen($prefix)));
    $dir = TY_E_FATURAM_DIR . 'includes/';
    if ($parts[0] === 'Admin') {
        array_shift($parts);
        $dir = TY_E_FATURAM_DIR . 'admin/';
    }
    $file = $dir . implode('/', $parts) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

add_action('before_woocommerce_init', static function (): void {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', TY_E_FATURAM_FILE, true);
    }
});

register_activation_hook(TY_E_FATURAM_FILE, ['TYEfaturam\\Installer', 'activate']);
register_deactivation_hook(TY_E_FATURAM_FILE, ['TYEfaturam\\Installer', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    if (version_compare(PHP_VERSION, '8.1', '<')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('TY E-Faturam için PHP 8.1 veya üzeri gereklidir.', 'ty-e-faturam')
                . '</p></div>';
        });
        return;
    }
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('TY E-Faturam çalışmak için e-ticaret altyapı eklentisinin etkin olmasını gerektirir.', 'ty-e-faturam')
                . '</p></div>';
        });
        return;
    }
    TYEfaturam\Plugin::instance()->boot();
});
