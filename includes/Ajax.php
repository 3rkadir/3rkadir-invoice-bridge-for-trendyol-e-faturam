<?php

namespace TYEfaturam;

use TYEfaturam\Admin\OrderUi;

defined('ABSPATH') || exit;

class Ajax
{
    public function __construct(
        private InvoiceService $service,
        private ApiClient $api,
        private OrderUi $orderUi
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_tef_invoice_action', [$this, 'handleInvoiceAction']);
        add_action('wp_ajax_tef_test_connection', [$this, 'handleTestConnection']);
        add_action('wp_ajax_tef_lookup_taxpayer', [$this, 'handleLookupTaxpayer']);
    }

    /**
     * Nonce'u doğrular; başarısızsa JSON hata yanıtı verip çıkar.
     * Her işleyici $_POST'a dokunmadan önce çağırır.
     */
    private function nonceFailed(): void
    {
        wp_send_json_error(['message' => __('Güvenlik doğrulaması başarısız. Sayfayı yenileyin.', 'ty-e-faturam')], 403);
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Bu işlem için yetkiniz yok.', 'ty-e-faturam')], 403);
        }
    }

    public function handleInvoiceAction(): void
    {
        if (!check_ajax_referer('tef_ajax', 'nonce', false)) {
            $this->nonceFailed();
        }
        $this->authorize();

        $do = isset($_POST['do']) ? sanitize_key(wp_unslash($_POST['do'])) : '';
        $orderId = isset($_POST['order_id']) ? absint(wp_unslash($_POST['order_id'])) : 0;
        $reload = isset($_POST['reload']) && sanitize_text_field(wp_unslash($_POST['reload'])) === '1';

        if ($orderId <= 0 || !in_array($do, ['create', 'resend', 'status', 'approve', 'discard'], true)) {
            wp_send_json_error(['message' => __('Geçersiz istek.', 'ty-e-faturam')], 400);
        }

        $result = match ($do) {
            'status'  => $this->service->refreshStatus($orderId),
            'approve' => $this->service->approveDraft($orderId),
            'discard' => $this->service->discardDraft($orderId),
            default   => $this->service->createForOrder($orderId),
        };

        wp_send_json_success([
            'ok'      => $result['success'],
            'message' => $result['message'],
            'html'    => $this->orderUi->renderActions($orderId, $result['message'], $result['success'], $reload),
        ]);
    }

    public function handleTestConnection(): void
    {
        if (!check_ajax_referer('tef_ajax', 'nonce', false)) {
            $this->nonceFailed();
        }
        $this->authorize();
        $result = $this->api->resolveAccount();
        if ($result['ok']) {
            wp_send_json_success(['message' => $result['message']]);
        }
        wp_send_json_error(['message' => $result['message']]);
    }

    public function handleLookupTaxpayer(): void
    {
        if (!check_ajax_referer('tef_ajax', 'nonce', false)) {
            $this->nonceFailed();
        }
        $this->authorize();

        $raw = isset($_POST['tax_id']) ? sanitize_text_field(wp_unslash($_POST['tax_id'])) : '';
        $taxId = (string) preg_replace('/\D/', '', $raw);
        if (!preg_match('/^\d{10,11}$/', $taxId)) {
            wp_send_json_error(['message' => __('Vergi No / TCKN 10 veya 11 haneli olmalıdır.', 'ty-e-faturam')]);
        }

        $result = $this->api->inspectTaxpayer($taxId);
        wp_send_json_success(['message' => $result['summary'], 'detail' => $result['detail']]);
    }
}
