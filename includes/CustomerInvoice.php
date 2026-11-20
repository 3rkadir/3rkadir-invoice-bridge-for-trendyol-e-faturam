<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

/**
 * Müşteri tarafı fatura erişimi: "Hesabım > Siparişler" bağlantısı, güvenli PDF indirme ve
 * fatura tamamlandığında müşteriye e-posta (bağlantı veya PDF eki).
 */
class CustomerInvoice
{
    private const MAIL_META = '_tef_customer_mailed';

    public function __construct(private InvoiceRepository $repo, private InvoiceService $service)
    {
    }

    public function register(): void
    {
        add_action('admin_post_tef_invoice_pdf', [$this, 'download']);
        add_action('admin_post_nopriv_tef_invoice_pdf', [$this, 'download']);
        add_action('admin_post_tef_send_invoice_email', [$this, 'resendEmail']);
        add_action('ty_e_faturam_invoice_completed', [$this, 'onInvoiceCompleted']);

        if (Settings::get('account_pdf') === 'yes') {
            add_filter('woocommerce_my_account_my_orders_actions', [$this, 'accountAction'], 10, 2);
            add_action('woocommerce_order_details_after_order_table', [$this, 'orderDetails']);
        }
    }

    /** Fatura tamamlanmış ve PDF bağlantısı kayıtlı mı? */
    public function available(int $orderId): bool
    {
        $row = $this->repo->find($orderId);
        return $row !== null
            && (string) ($row['status'] ?? '') === 'completed'
            && (string) ($row['pdf_url'] ?? '') !== '';
    }

    /** Müşteriye verilecek indirme adresi; erişim sipariş anahtarıyla (order key) korunur. */
    public function url(\WC_Order $order): string
    {
        return add_query_arg(
            [
                'action'   => 'tef_invoice_pdf',
                'order_id' => $order->get_id(),
                'key'      => $order->get_order_key(),
            ],
            admin_url('admin-post.php')
        );
    }

    /**
     * @param array<string,array{url:string,name:string}> $actions
     * @return array<string,array{url:string,name:string}>
     */
    public function accountAction(array $actions, mixed $order): array
    {
        if ($order instanceof \WC_Order && $this->available($order->get_id())) {
            $actions['tef_invoice'] = [
                'url'  => $this->url($order),
                'name' => __('Fatura (PDF)', 'ty-e-faturam'),
            ];
        }
        return $actions;
    }

    public function orderDetails(mixed $order): void
    {
        if (!$order instanceof \WC_Order || !$this->available($order->get_id())) {
            return;
        }
        echo '<p class="tef-invoice-download"><a class="button" href="' . esc_url($this->url($order)) . '">'
            . esc_html__('Faturayı indir (PDF)', 'ty-e-faturam') . '</a></p>';
    }

    /** PDF'i sunucu üzerinden aktarır; asıl depolama adresi müşteriye gösterilmez. */
    public function download(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Erişim, sipariş anahtarı (order key) ile doğrulanır; e-postadaki bağlantılar için nonce kullanılamaz.
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $order = $orderId > 0 ? wc_get_order($orderId) : false;
        $isManager = current_user_can('manage_woocommerce');
        if (
            !$order instanceof \WC_Order
            || (!$isManager && ($key === '' || !hash_equals((string) $order->get_order_key(), $key)))
        ) {
            wp_die(esc_html__('Geçersiz veya süresi dolmuş fatura bağlantısı.', 'ty-e-faturam'), '', ['response' => 403]);
        }
        if (!$this->available($orderId)) {
            wp_die(esc_html__('Bu sipariş için fatura henüz hazır değil.', 'ty-e-faturam'), '', ['response' => 404]);
        }

        $pdf = $this->fetchPdf($orderId);
        if ($pdf === null) {
            wp_die(esc_html__('Fatura PDF dosyası şu anda alınamadı. Lütfen daha sonra tekrar deneyin.', 'ty-e-faturam'), '', ['response' => 502]);
        }

        $name = 'fatura-' . sanitize_file_name((string) $order->get_order_number()) . '.pdf';
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ham PDF çıktısı.
        exit;
    }

    /**
     * Kayıtlı bağlantıdan PDF'i indirir; geçersizse (ör. süresi dolmuş geçici bağlantı) yeni bağlantı alıp bir kez daha dener.
     */
    public function fetchPdf(int $orderId): ?string
    {
        $row = $this->repo->find($orderId);
        $url = (string) ($row['pdf_url'] ?? '');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($url !== '') {
                $body = $this->fetchBody($url);
                if ($body !== null) {
                    return $body;
                }
            }
            if ($attempt === 0) {
                $url = $this->service->ensurePdfUrl($orderId, true);
            }
        }
        return null;
    }

    private function fetchBody(string $url): ?string
    {
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        $res = wp_safe_remote_get($url, [
            'timeout'             => 20,
            'redirection'         => 3,
            'limit_response_size' => 15 * MB_IN_BYTES,
            'headers'             => ['Accept' => 'application/pdf'],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = (string) wp_remote_retrieve_body($res);
        return strncmp($body, '%PDF', 4) === 0 ? $body : null;
    }

    public function onInvoiceCompleted(int $orderId): void
    {
        $mode = (string) Settings::get('customer_email', 'off');
        if ($mode === 'link' || $mode === 'attach') {
            $this->sendEmail($orderId, $mode, false);
        }
    }

    /**
     * Müşteriye fatura e-postası gönderir.
     *
     * @param string $mode  link | attach
     * @param bool   $force Daha önce gönderilmiş olsa da tekrar gönderir.
     */
    public function sendEmail(int $orderId, string $mode, bool $force): bool
    {
        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return false;
        }
        if (!$force && (string) $order->get_meta(self::MAIL_META) !== '') {
            return false;
        }
        $to = (string) $order->get_billing_email();
        if (!is_email($to)) {
            return false;
        }

        $this->service->ensurePdfUrl($orderId);
        if (!$this->available($orderId)) {
            return false;
        }

        $number = (string) $order->get_order_number();
        $attachments = [];
        if ($mode === 'attach') {
            $pdf = $this->fetchPdf($orderId);
            if ($pdf !== null) {
                $path = $this->writeTemp($pdf, $number);
                if ($path !== '') {
                    $attachments[] = $path;
                }
            }
        }

        $name = trim((string) $order->get_billing_first_name());
        // translators: %s: customer first name.
        $html = '<p>' . ($name !== '' ? esc_html(sprintf(__('Merhaba %s,', 'ty-e-faturam'), $name)) : esc_html__('Merhaba,', 'ty-e-faturam')) . '</p>';
        // translators: %s: order number.
        $html .= '<p>' . esc_html(sprintf(__('#%s numaralı siparişinizin faturası hazırlandı.', 'ty-e-faturam'), $number)) . '</p>';
        if ($attachments !== []) {
            $html .= '<p>' . esc_html__('Faturanızı bu e-postaya PDF olarak eklenmiş şekilde bulabilirsiniz.', 'ty-e-faturam') . '</p>';
        }
        $html .= '<p><a href="' . esc_url($this->url($order)) . '">' . esc_html__('Faturayı indir (PDF)', 'ty-e-faturam') . '</a></p>';

        $mailer = WC()->mailer();
        $subject = sprintf(
            // translators: 1: site name, 2: order number.
            __('[%1$s] #%2$s numaralı siparişinizin faturası', 'ty-e-faturam'),
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            $number
        );
        $message = $mailer->wrap_message(__('Faturanız hazır', 'ty-e-faturam'), $html);
        $sent = (bool) $mailer->send($to, $subject, $message, "Content-Type: text/html\r\n", $attachments);

        foreach ($attachments as $file) {
            $this->removeTemp($file);
        }

        if ($sent) {
            $order->update_meta_data(self::MAIL_META, current_time('mysql'));
            $order->save_meta_data();
            // translators: %s: recipient email address.
            $order->add_order_note(sprintf(__('TY E-Faturam: Fatura e-postası gönderildi (%s).', 'ty-e-faturam'), $to));
        } else {
            // translators: %s: recipient email address.
            $order->add_order_note(sprintf(__('TY E-Faturam: Fatura e-postası gönderilemedi (%s).', 'ty-e-faturam'), $to));
        }
        return $sent;
    }

    /** Sipariş ekranındaki "Gönder / Tekrar gönder" bağlantısı. */
    public function resendEmail(): void
    {
        $orderId = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        check_admin_referer('tef_mail_' . $orderId);
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Bu işlem için yetkiniz yok.', 'ty-e-faturam'));
        }

        $mode = (string) Settings::get('customer_email', 'off') === 'attach' ? 'attach' : 'link';
        $this->sendEmail($orderId, $mode, true);

        $back = wp_get_referer();
        wp_safe_redirect($back !== false ? $back : admin_url());
        exit;
    }

    /** E-posta eki için geçici dosya yazar; başarısızsa boş metin döner. */
    private function writeTemp(string $pdf, string $number): string
    {
        $fs = $this->filesystem();
        if ($fs === null) {
            return '';
        }
        $dir = trailingslashit(get_temp_dir()) . 'tef-' . wp_generate_password(12, false);
        if (!wp_mkdir_p($dir)) {
            return '';
        }
        $path = trailingslashit($dir) . 'fatura-' . sanitize_file_name($number) . '.pdf';
        if (!$fs->put_contents($path, $pdf, 0600)) {
            $fs->rmdir($dir);
            return '';
        }
        return $path;
    }

    private function removeTemp(string $path): void
    {
        wp_delete_file($path);
        $fs = $this->filesystem();
        if ($fs !== null) {
            $fs->rmdir(dirname($path));
        }
    }

    private function filesystem(): ?\WP_Filesystem_Base
    {
        global $wp_filesystem;
        if (!$wp_filesystem instanceof \WP_Filesystem_Base) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        return $wp_filesystem instanceof \WP_Filesystem_Base ? $wp_filesystem : null;
    }
}
