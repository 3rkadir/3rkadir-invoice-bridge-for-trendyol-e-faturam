<?php

namespace TYEfaturam\Admin;

use TYEfaturam\InvoiceRepository;
use TYEfaturam\Logger;
use TYEfaturam\Settings;

defined('ABSPATH') || exit;

class Admin
{
    private const PER_PAGE = 20;

    public function __construct(private InvoiceRepository $repo, private Logger $logger, private OrderUi $orderUi)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_tef_purge_logs', [$this, 'purgeLogs']);
        add_action('admin_post_tef_download_pdf', [$this, 'downloadPdf']);
        add_filter('woocommerce_admin_billing_fields', [$this, 'adminBillingFields']);
        add_filter('plugin_action_links_' . TY_E_FATURAM_BASENAME, [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        $cap = 'manage_woocommerce';
        add_menu_page(
            __('TY E-Faturam', 'ty-e-faturam'),
            __('TY E-Faturam', 'ty-e-faturam'),
            $cap,
            'tef',
            [$this, 'pageSettings'],
            'dashicons-media-spreadsheet',
            56
        );
        add_submenu_page('tef', __('Genel Ayarlar', 'ty-e-faturam'), __('Genel Ayarlar', 'ty-e-faturam'), $cap, 'tef', [$this, 'pageSettings']);
        add_submenu_page('tef', __('Bekleyen Faturalar', 'ty-e-faturam'), __('Bekleyen Faturalar', 'ty-e-faturam'), $cap, 'tef-pending', [$this, 'pagePending']);
        add_submenu_page('tef', __('Gönderilmiş Faturalar', 'ty-e-faturam'), __('Gönderilmiş Faturalar', 'ty-e-faturam'), $cap, 'tef-sent', [$this, 'pageSent']);
        add_submenu_page('tef', __('Loglar', 'ty-e-faturam'), __('Loglar', 'ty-e-faturam'), $cap, 'tef-logs', [$this, 'pageLogs']);
    }

    public function assets(string $hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $onPlugin = strpos($page, 'tef') === 0;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $orderScreens = [wc_get_page_screen_id('shop-order'), 'edit-shop_order', 'shop_order'];
        $onOrders = $screen && in_array($screen->id, $orderScreens, true);

        if (!$onPlugin && !$onOrders) {
            return;
        }

        wp_enqueue_style('tef-admin', TY_E_FATURAM_URL . 'assets/css/admin.css', [], TY_E_FATURAM_VERSION);
        if ($onPlugin) {
            wp_enqueue_style('wp-color-picker');
            wp_enqueue_style('tef-toggle-preview', TY_E_FATURAM_URL . 'assets/css/checkout.css', [], TY_E_FATURAM_VERSION);
        }
        wp_enqueue_script('tef-admin', TY_E_FATURAM_URL . 'assets/js/admin.js', $onPlugin ? ['wp-color-picker'] : [], TY_E_FATURAM_VERSION, true);
        wp_localize_script('tef-admin', 'tefAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('tef_ajax'),
            'i18n'    => [
                'working' => __('İşleniyor…', 'ty-e-faturam'),
                'error'   => __('İstek başarısız oldu. Lütfen tekrar deneyin.', 'ty-e-faturam'),
                // translators: 1: current order number in the queue, 2: total number of selected orders.
                'bulkProgress' => __('İşleniyor: %1$s / %2$s', 'ty-e-faturam'),
                // translators: 1: number of successful orders, 2: number of failed orders.
                'bulkDone'     => __('Tamamlandı: %1$s başarılı, %2$s hatalı.', 'ty-e-faturam'),
                'bulkNone'     => __('Önce en az bir sipariş seçin.', 'ty-e-faturam'),
                // translators: %s: number of selected orders.
                'bulkConfirm'  => __('%s sipariş için işlem yapılacak. Devam edilsin mi?', 'ty-e-faturam'),
            ],
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public function adminBillingFields(array $fields): array
    {
        $fields['tax_number'] = [
            'label' => __('Vergi No / TCKN', 'ty-e-faturam'),
            'show'  => false,
        ];
        $fields['tax_office'] = [
            'label' => __('Vergi Dairesi', 'ty-e-faturam'),
            'show'  => false,
        ];
        return $fields;
    }

    /**
     * @param string[] $links
     * @return string[]
     */
    public function actionLinks(array $links): array
    {
        $url = admin_url('admin.php?page=tef');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Ayarlar', 'ty-e-faturam') . '</a>');
        return $links;
    }

    private function render(string $template, array $vars = []): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bu sayfaya erişim yetkiniz yok.', 'ty-e-faturam'));
        }
        $file = TY_E_FATURAM_DIR . 'templates/' . $template . '.php';
        if (!is_readable($file)) {
            return;
        }
        $orderUi = $this->orderUi;
        extract($vars, EXTR_SKIP);
        include $file;
    }

    private function pageNumber(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
    }

    public function pageSettings(): void
    {
        $this->render('settings', [
            's'        => Settings::all(),
            'statuses' => wc_get_order_statuses(),
        ]);
    }

    public function pagePending(): void
    {
        $paged = $this->pageNumber();
        $statuses = Settings::triggerStatuses();
        if ($statuses === []) {
            $statuses = ['processing', 'completed'];
        }

        $result = wc_get_orders([
            'limit'      => self::PER_PAGE,
            'paged'      => $paged,
            'paginate'   => true,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'status'     => $statuses,
            'meta_query' => [
                [
                    'key'     => '_tef_invoice_uuid',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $this->render('pending', [
            'orders'      => $result->orders ?? [],
            'total_pages' => (int) ($result->max_num_pages ?? 1),
            'paged'       => $paged,
            'in_progress' => $this->repo->pendingRemote(100),
        ]);
    }

    public function pageSent(): void
    {
        $paged = $this->pageNumber();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable

        $data = $this->repo->search(['order_id' => $orderId, 'status' => $status], $paged, self::PER_PAGE);

        $this->render('sent', [
            'rows'        => $data['rows'],
            'total_pages' => (int) ceil($data['total'] / self::PER_PAGE),
            'paged'       => $paged,
            'order_id'    => $orderId,
            'status'      => $status,
        ]);
    }

    public function pageLogs(): void
    {
        $paged = $this->pageNumber();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $result = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';
        // phpcs:enable
        if (!in_array($result, ['success', 'failed'], true)) {
            $result = '';
        }

        $data = $this->logger->query(['result' => $result, 'order_id' => $orderId], $paged, self::PER_PAGE);

        $this->render('logs', [
            'rows'        => $data['rows'],
            'total_pages' => (int) ceil($data['total'] / self::PER_PAGE),
            'paged'       => $paged,
            'order_id'    => $orderId,
            'result'      => $result,
        ]);
    }

    public function purgeLogs(): void
    {
        check_admin_referer('tef_purge_logs');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bu işlem için yetkiniz yok.', 'ty-e-faturam'));
        }
        $this->logger->purge();
        wp_safe_redirect(admin_url('admin.php?page=tef-logs&purged=1'));
        exit;
    }

    public function downloadPdf(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        check_admin_referer('tef_pdf_' . $orderId);
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bu işlem için yetkiniz yok.', 'ty-e-faturam'));
        }

        $row = $this->repo->find($orderId);
        $url = (string) ($row['pdf_url'] ?? '');
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            wp_die(esc_html__('Bu sipariş için kayıtlı bir PDF bağlantısı yok.', 'ty-e-faturam'));
        }

        wp_redirect(esc_url_raw($url)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        exit;
    }
}
