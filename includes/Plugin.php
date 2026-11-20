<?php

namespace TYEfaturam;

use TYEfaturam\Admin\Admin;
use TYEfaturam\Admin\OrderUi;

defined('ABSPATH') || exit;

final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Eklenti dilini ayara göre belirler: Türkçe (kaynak metinler) veya İngilizce.
     * "Otomatik" modda site/kullanıcı dili Türkçe ise Türkçe, aksi halde İngilizce kullanılır.
     */
    public static function pluginLocale(string $locale, string $domain): string
    {
        if ($domain !== 'ty-e-faturam') {
            return $locale;
        }
        $preference = (string) Settings::get('language', 'auto');
        if ($preference === 'tr') {
            return 'tr_TR';
        }
        if ($preference === 'en') {
            return 'en_US';
        }
        return strpos($locale, 'tr') === 0 ? 'tr_TR' : 'en_US';
    }

    public function boot(): void
    {
        add_filter('plugin_locale', [self::class, 'pluginLocale'], 10, 2);
        add_action('init', static function (): void {
            // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Ayarlardaki dil seçimi (Otomatik/Türkçe/English) için paketle gelen çeviri dosyaları elle yüklenir.
            load_plugin_textdomain(
                'ty-e-faturam',
                false,
                dirname(TY_E_FATURAM_BASENAME) . '/languages'
            );
        });

        add_action('admin_init', static function (): void {
            if (!function_exists('wp_add_privacy_policy_content')) {
                return;
            }
            wp_add_privacy_policy_content(
                'TY E-Faturam',
                wp_kses_post(
                    '<p>' . __('Bu eklenti, fatura oluşturmak için sipariş verilerini (ad, soyad, firma, vergi numarası/TCKN, vergi dairesi, adres, telefon, e-posta ve sipariş kalemleri) TY E-Faturam API\'sine gönderir. API yanıtları, hata ayıklama amacıyla sitenizin veritabanındaki log tablosunda 30 gün saklanır.', 'ty-e-faturam') . '</p>'
                )
            );
        });

        Installer::maybeUpgrade();

        $logger = new Logger();
        $repo = new InvoiceRepository();
        $api = new ApiClient($logger);
        $service = new InvoiceService($api, $repo, new OrderMapper(), new PayloadBuilder());

        (new Cron($service, $repo, $logger))->register();
        (new Checkout())->register();
        (new CustomerInvoice($repo, $service))->register();
        (new OrderInfo(new OrderMapper()))->register();

        $orderUi = new OrderUi($repo, $service);
        (new Ajax($service, $api, $orderUi))->register();

        if (is_admin()) {
            add_action('admin_init', [Settings::class, 'register']);
            $orderUi->register();
            (new Admin($repo, $logger, $orderUi))->register();
        }
    }
}
