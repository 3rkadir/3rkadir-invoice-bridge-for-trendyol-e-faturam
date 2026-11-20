<?php

namespace TYEfaturam\Admin;

use TYEfaturam\InvoiceRepository;
use TYEfaturam\InvoiceService;

defined('ABSPATH') || exit;

class OrderUi
{
    private const BULK_LIMIT = 20;
    private const BULK_ACTIONS = ['tef_bulk_create', 'tef_bulk_approve', 'tef_bulk_status'];

    public function __construct(private InvoiceRepository $repo, private InvoiceService $service)
    {
    }

    public function register(): void
    {
        add_filter('manage_edit-shop_order_columns', [$this, 'addColumn']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'legacyColumn'], 10, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'addColumn']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'hposColumn'], 10, 2);
        add_action('add_meta_boxes', [$this, 'addMetaBox']);

        foreach (['edit-shop_order', 'woocommerce_page_wc-orders'] as $screenId) {
            add_filter('bulk_actions-' . $screenId, [$this, 'bulkActions']);
            add_filter('handle_bulk_actions-' . $screenId, [$this, 'handleBulk'], 10, 3);
        }
        add_action('admin_notices', [$this, 'bulkNotice']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function addColumn(array $columns): array
    {
        $label = __('Fatura', 'ty-e-faturam');
        $result = [];
        $added = false;
        foreach ($columns as $key => $title) {
            if ($key === 'wc_actions' && !$added) {
                $result['tef_invoice'] = $label;
                $added = true;
            }
            $result[$key] = $title;
        }
        if (!$added) {
            $result['tef_invoice'] = $label;
        }
        return $result;
    }

    public function legacyColumn(string $column, int $postId): void
    {
        if ($column === 'tef_invoice') {
            echo $this->renderActions($postId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function hposColumn(string $column, mixed $order): void
    {
        if ($column === 'tef_invoice' && $order instanceof \WC_Order) {
            echo $this->renderActions($order->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function addMetaBox(): void
    {
        add_meta_box(
            'tef_invoice_box',
            __('TY E-Faturam', 'ty-e-faturam'),
            [$this, 'renderMetaBox'],
            wc_get_page_screen_id('shop-order'),
            'side',
            'default'
        );
    }

    public function renderMetaBox(mixed $postOrOrder): void
    {
        $order = $postOrOrder instanceof \WP_Post ? wc_get_order($postOrOrder->ID) : $postOrOrder;
        if (!$order instanceof \WC_Order) {
            return;
        }
        $orderId = $order->get_id();
        $row = $this->repo->find($orderId) ?? [];

        $status = (string) ($row['status'] ?? '');
        $uuid = (string) ($row['invoice_uuid'] ?? '');
        $type = (string) ($row['invoice_type'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        $hasPdf = !empty($row['pdf_url']);

        echo '<table class="tef-meta"><tbody>';
        $this->metaRow(__('Fatura Durumu', 'ty-e-faturam'), esc_html(InvoiceRepository::statusLabel($status)));
        $this->metaRow(__('UUID', 'ty-e-faturam'), $uuid !== '' ? '<code>' . esc_html($uuid) . '</code>' : '—');
        $this->metaRow(__('Fatura Tipi', 'ty-e-faturam'), esc_html(InvoiceRepository::typeLabel($type)));
        $this->metaRow(
            __('Oluşturulma Tarihi', 'ty-e-faturam'),
            $uuid !== '' && $created !== '' ? esc_html($created) : '—'
        );
        $this->metaRow(
            __('PDF Linki', 'ty-e-faturam'),
            $hasPdf
                ? '<a href="' . esc_url($this->pdfUrl($orderId)) . '" target="_blank" rel="noopener">'
                    . esc_html__('PDF İndir', 'ty-e-faturam') . '</a>'
                : '—'
        );
        if ($status === 'completed' && $hasPdf) {
            $mailed = (string) $order->get_meta('_tef_customer_mailed');
            $sendUrl = wp_nonce_url(
                add_query_arg(['action' => 'tef_send_invoice_email', 'order_id' => $orderId], admin_url('admin-post.php')),
                'tef_mail_' . $orderId
            );
            $this->metaRow(
                __('Müşteriye E-posta', 'ty-e-faturam'),
                ($mailed !== ''
                    // translators: %s: date and time the email was sent.
                    ? esc_html(sprintf(__('Gönderildi: %s', 'ty-e-faturam'), $mailed))
                    : esc_html__('Gönderilmedi', 'ty-e-faturam'))
                . ' — <a href="' . esc_url($sendUrl) . '">'
                . ($mailed !== '' ? esc_html__('Tekrar gönder', 'ty-e-faturam') : esc_html__('Gönder', 'ty-e-faturam'))
                . '</a>'
            );
        }
        if ($status === 'draft') {
            $summary = json_decode((string) $order->get_meta('_tef_draft_summary'), true);
            if (is_array($summary)) {
                $this->metaRow(
                    __('Taslak Özeti', 'ty-e-faturam'),
                    esc_html(sprintf(
                        // translators: 1: invoice type label, 2: payable amount, 3: number of lines.
                        __('%1$s — %2$s TL, %3$d satır', 'ty-e-faturam'),
                        InvoiceRepository::typeLabel((string) ($summary['type'] ?? '')),
                        number_format_i18n(((int) ($summary['payable'] ?? 0)) / 100, 2),
                        (int) ($summary['lines'] ?? 0)
                    ))
                );
            }
        }
        if ($status === 'failed' && !empty($row['error_message'])) {
            $this->metaRow(__('Hata', 'ty-e-faturam'), esc_html((string) $row['error_message']));
        }
        echo '</tbody></table>';

        echo $this->renderActions($orderId, '', true, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public function bulkActions(array $actions): array
    {
        $actions['tef_bulk_create'] = __('TY E-Faturam: Fatura oluştur', 'ty-e-faturam');
        $actions['tef_bulk_approve'] = __('TY E-Faturam: Taslakları onayla ve gönder', 'ty-e-faturam');
        $actions['tef_bulk_status'] = __('TY E-Faturam: Fatura durumunu sorgula', 'ty-e-faturam');
        return $actions;
    }

    /**
     * Sipariş listesindeki toplu işlemi çalıştırır. Bulk nonce'u WordPress bu kancayı çağırmadan önce doğrular.
     * Zaman aşımını önlemek için tek seferde en fazla BULK_LIMIT sipariş işlenir.
     *
     * @param array<int|string> $ids
     */
    public function handleBulk(string $redirect, string $action, array $ids): string
    {
        if (!in_array($action, self::BULK_ACTIONS, true) || !current_user_can('manage_woocommerce')) {
            return $redirect;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $batch = array_slice($ids, 0, self::BULK_LIMIT);
        $summary = [
            'ok'        => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'remaining' => max(0, count($ids) - count($batch)),
            'errors'    => [],
        ];

        foreach ($batch as $orderId) {
            $row = $this->repo->find($orderId);
            $status = (string) ($row['status'] ?? '');
            $hasInvoice = $row !== null && (string) $row['invoice_uuid'] !== '' && $status !== 'failed';

            if ($action === 'tef_bulk_create') {
                if ($hasInvoice || $status === 'draft') {
                    $summary['skipped']++;
                    continue;
                }
                $result = $this->service->createForOrder($orderId);
            } elseif ($action === 'tef_bulk_approve') {
                if ($status !== 'draft') {
                    $summary['skipped']++;
                    continue;
                }
                $result = $this->service->approveDraft($orderId);
            } else {
                if (!$hasInvoice) {
                    $summary['skipped']++;
                    continue;
                }
                $result = $this->service->refreshStatus($orderId);
            }

            if ($result['success']) {
                $summary['ok']++;
            } else {
                $summary['failed']++;
                if (count($summary['errors']) < 5) {
                    $order = wc_get_order($orderId);
                    $number = $order instanceof \WC_Order ? (string) $order->get_order_number() : (string) $orderId;
                    $summary['errors'][] = '#' . $number . ': ' . $result['message'];
                }
            }
        }

        set_transient('tef_bulk_' . get_current_user_id(), $summary, 5 * MINUTE_IN_SECONDS);
        return add_query_arg('tef_bulk', '1', $redirect);
    }

    public function bulkNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Yalnızca kayıtlı sonuç özeti gösterilir.
        if (!isset($_GET['tef_bulk'])) {
            return;
        }
        $key = 'tef_bulk_' . get_current_user_id();
        $summary = get_transient($key);
        if (!is_array($summary)) {
            return;
        }
        delete_transient($key);

        $class = (int) $summary['failed'] > 0 ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html(sprintf(
            // translators: 1: number of successful orders, 2: number of failed orders, 3: number of skipped orders.
            __('TY E-Faturam toplu işlem: %1$d başarılı, %2$d hatalı, %3$d atlandı.', 'ty-e-faturam'),
            (int) $summary['ok'],
            (int) $summary['failed'],
            (int) $summary['skipped']
        )) . '</p>';
        if ((int) $summary['remaining'] > 0) {
            echo '<p>' . esc_html(sprintf(
                // translators: 1: number of orders not processed, 2: maximum orders processed at a time.
                __('Tek seferde en fazla %2$d sipariş işlenir; %1$d sipariş işlenmedi. Lütfen kalanları tekrar seçin.', 'ty-e-faturam'),
                (int) $summary['remaining'],
                self::BULK_LIMIT
            )) . '</p>';
        }
        if (!empty($summary['errors'])) {
            echo '<ul style="list-style:disc;margin-left:20px;">';
            foreach ((array) $summary['errors'] as $error) {
                echo '<li>' . esc_html((string) $error) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private function metaRow(string $label, string $valueHtml): void
    {
        echo '<tr><th>' . esc_html($label) . '</th><td>' . $valueHtml . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function pdfUrl(int $orderId): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => 'tef_download_pdf', 'order_id' => $orderId], admin_url('admin-post.php')),
            'tef_pdf_' . $orderId
        );
    }

    /**
     * Sipariş satırı / meta kutusu için durum etiketi ve düğmeleri üretir. Çıktı kaçışlanmıştır.
     */
    public function renderActions(int $orderId, string $message = '', bool $ok = true, bool $reload = false): string
    {
        $row = $this->repo->find($orderId);
        $status = (string) ($row['status'] ?? '');
        $uuid = (string) ($row['invoice_uuid'] ?? '');
        $hasPdf = !empty($row['pdf_url']);

        $html = '<div class="tef-actions" data-order-id="' . esc_attr((string) $orderId) . '" data-reload="' . ($reload ? '1' : '0') . '">';
        $html .= '<span class="tef-badge tef-badge-' . esc_attr($status !== '' ? $status : 'none') . '">'
            . esc_html(InvoiceRepository::statusLabel($status)) . '</span> ';

        if ($status === 'draft') {
            $html .= '<button type="button" class="button button-small button-primary tef-action" data-do="approve">'
                . esc_html__('Onayla ve Gönder', 'ty-e-faturam') . '</button> ';
            $html .= '<button type="button" class="button button-small tef-action" data-do="discard" data-confirm="'
                . esc_attr__('Taslak silinecek. Emin misiniz?', 'ty-e-faturam') . '">'
                . esc_html__('Taslağı Sil', 'ty-e-faturam') . '</button> ';
        } elseif ($uuid === '' || $status === 'failed') {
            $label = $status === 'failed'
                ? __('Yeniden Gönder', 'ty-e-faturam')
                : __('Fatura Oluştur', 'ty-e-faturam');
            $do = $status === 'failed' ? 'resend' : 'create';
            $html .= '<button type="button" class="button button-small button-primary tef-action" data-do="' . esc_attr($do) . '">'
                . esc_html($label) . '</button> ';
        } else {
            $html .= '<button type="button" class="button button-small tef-action" data-do="status">'
                . esc_html__('Durum Sorgula', 'ty-e-faturam') . '</button> ';
        }

        if ($hasPdf) {
            $html .= '<a class="button button-small" target="_blank" rel="noopener" href="' . esc_url($this->pdfUrl($orderId)) . '">'
                . esc_html__('PDF İndir', 'ty-e-faturam') . '</a>';
        }

        if ($message !== '') {
            $html .= '<span class="tef-msg ' . ($ok ? 'tef-msg-ok' : 'tef-msg-err') . '">' . esc_html($message) . '</span>';
        }

        return $html . '</div>';
    }
}
