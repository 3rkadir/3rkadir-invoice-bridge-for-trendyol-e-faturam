<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class InvoiceService
{
    /** Dokümandaki hata durum kodları: 29 Döküman Hatası, 405 Hatalı. */
    private const FAILED_STATUSES = [29, 405];

    public function __construct(
        private ApiClient $api,
        private InvoiceRepository $repo,
        private OrderMapper $mapper,
        private PayloadBuilder $builder
    ) {
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function createForOrder(int $orderId, ?bool $draft = null): array
    {
        $draft ??= Settings::get('draft_mode') === 'yes';

        $order = wc_get_order($orderId);
        if (!$order instanceof \WC_Order) {
            return $this->result(false, __('Sipariş bulunamadı.', 'ty-e-faturam'));
        }

        $existing = $this->repo->find($orderId);
        if ($existing !== null && $existing['invoice_uuid'] !== '' && $existing['status'] !== 'failed') {
            return $this->result(false, __('Bu sipariş için fatura zaten oluşturulmuş. Durumu sorgulayabilirsiniz.', 'ty-e-faturam'));
        }

        $lock = 'tef_lock_' . $orderId;
        if (get_transient($lock)) {
            return $this->result(false, __('Bu sipariş için işlem zaten devam ediyor.', 'ty-e-faturam'));
        }
        set_transient($lock, 1, 120);

        try {
            return $this->process($order, $draft);
        } finally {
            delete_transient($lock);
        }
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function process(\WC_Order $order, bool $draft): array
    {
        $orderId = $order->get_id();

        if (!Settings::isConfigured()) {
            return $this->fail($order, '', __('API e-posta ve şifre ayarlanmamış.', 'ty-e-faturam'));
        }
        if (!Settings::hasAccountIds()) {
            $this->api->resolveAccount();
        }
        if (!Settings::hasAccountIds()) {
            return $this->fail(
                $order,
                '',
                __('Company ID / User ID bulunamadı. Ayarlar sayfasındaki "Elle Giriş" bölümüne yazın.', 'ty-e-faturam')
            );
        }

        $data = $this->mapper->map($order);
        if ($data['lines'] === []) {
            return $this->fail($order, '', __('Siparişte faturalanacak satır bulunamadı.', 'ty-e-faturam'));
        }

        $taxId = $data['customer']['tax_id'];
        $fallback = (string) Settings::get('fallback_tckn');

        if ($taxId === '') {
            if ($data['customer']['company'] !== '') {
                return $this->fail($order, '', __('Kurumsal sipariş için vergi numarası bulunamadı.', 'ty-e-faturam'));
            }
            $taxId = $fallback;
            $data['customer']['tax_id'] = $taxId;
        }
        if (!preg_match('/^\d{10,11}$/', $taxId)) {
            return $this->fail($order, '', __('Vergi numarası / TCKN geçersiz (10 veya 11 haneli olmalıdır).', 'ty-e-faturam'));
        }

        $type = 'EARSIV';
        if ($taxId !== $fallback) {
            $query = $this->api->queryTaxpayer($taxId, $orderId);
            if (!$query['ok']) {
                // translators: %s: error message.
                return $this->fail($order, '', sprintf(__('Mükellef sorgusu başarısız: %s', 'ty-e-faturam'), $query['error']));
            }
            if ($query['taxpayer']) {
                $type = 'EFATURA';
                $data['target_alias'] = $query['info']['alias'];
            }
        }

        $payload = $this->builder->build($data, $type);

        if ($draft) {
            return $this->saveDraft($order, $type, $payload);
        }

        $response = $type === 'EFATURA'
            ? $this->api->createOutgoingEInvoice($payload, $orderId)
            : $this->api->createEArchive($payload, $orderId);

        if (!$response['ok']) {
            return $this->fail($order, $type, $response['error']);
        }

        $body = $response['data'];
        $uuid = (string) ($body['invoiceUuid'] ?? $body['uuid'] ?? '');
        if ($uuid === '') {
            return $this->fail($order, $type, __('Yanıtta fatura UUID değeri bulunamadı.', 'ty-e-faturam'));
        }

        $number = (string) ($body['invoiceId'] ?? '');
        $remote = isset($body['status']) ? (string) $body['status'] : '';
        $gib = (string) ($body['gibStatus'] ?? $body['gibStatusCode'] ?? '');
        $done = $this->isCompleted($body);
        $pdf = $this->findPdfUrl($body);
        if ($pdf === '' && (int) $remote >= 30 && !in_array((int) $remote, self::FAILED_STATUSES, true)) {
            $pdf = $this->fetchPdfUrl($type, $uuid, $orderId);
        }

        $this->repo->save($orderId, [
            'invoice_uuid'   => $uuid,
            'invoice_number' => $number,
            'invoice_type'   => $type,
            'status'         => $done ? 'completed' : 'created',
            'remote_status'  => $remote,
            'gib_status'     => $gib,
            'pdf_url'        => $pdf,
            'error_message'  => '',
        ]);

        $order->update_meta_data('_tef_invoice_uuid', $uuid);
        $order->update_meta_data('_tef_invoice_number', $number);
        $order->update_meta_data('_tef_invoice_type', $type);
        $order->update_meta_data('_tef_invoice_status', $done ? 'completed' : 'created');
        $order->update_meta_data('_tef_created_at', current_time('mysql'));
        $order->update_meta_data('_tef_pdf_url', $pdf);
        $order->delete_meta_data('_tef_error');
        $order->delete_meta_data('_tef_draft_summary');
        $order->save_meta_data();

        $order->add_order_note(sprintf(
            // translators: 1: invoice type label, 2: invoice UUID.
            __('TY E-Faturam: %1$s oluşturuldu. UUID: %2$s', 'ty-e-faturam'),
            InvoiceRepository::typeLabel($type),
            $uuid
        ));

        if ($done) {
            do_action('ty_e_faturam_invoice_completed', $orderId);
        }

        // translators: 1: invoice type label, 2: invoice UUID.
        return $this->result(true, sprintf(__('%1$s oluşturuldu. UUID: %2$s', 'ty-e-faturam'), InvoiceRepository::typeLabel($type), $uuid));
    }

    /**
     * Faturayı API'ye göndermeden taslak olarak kaydeder (onay bekler).
     *
     * @param array<string,mixed> $payload
     * @return array{success:bool,message:string}
     */
    private function saveDraft(\WC_Order $order, string $type, array $payload): array
    {
        $summary = [
            'type'    => $type,
            'payable' => (int) ($payload['invoiceTotal']['payableAmount'] ?? 0),
            'lines'   => count((array) ($payload['invoiceLines'] ?? [])),
            'created' => current_time('mysql'),
        ];

        $this->repo->save($order->get_id(), [
            'invoice_uuid'  => '',
            'invoice_type'  => $type,
            'status'        => 'draft',
            'error_message' => '',
        ]);
        $order->update_meta_data('_tef_invoice_status', 'draft');
        $order->update_meta_data('_tef_invoice_type', $type);
        $order->update_meta_data('_tef_draft_summary', wp_json_encode($summary));
        $order->delete_meta_data('_tef_error');
        $order->save_meta_data();
        $order->add_order_note(sprintf(
            // translators: %s: invoice type label.
            __('TY E-Faturam: %s taslağı hazırlandı, onay bekliyor.', 'ty-e-faturam'),
            InvoiceRepository::typeLabel($type)
        ));

        // translators: %s: invoice type label.
        return $this->result(true, sprintf(__('%s taslağı kaydedildi. Göndermek için "Onayla ve Gönder" düğmesini kullanın.', 'ty-e-faturam'), InvoiceRepository::typeLabel($type)));
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function approveDraft(int $orderId): array
    {
        $row = $this->repo->find($orderId);
        if ($row === null || $row['status'] !== 'draft') {
            return $this->result(false, __('Onay bekleyen bir taslak bulunamadı.', 'ty-e-faturam'));
        }
        return $this->createForOrder($orderId, false);
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function discardDraft(int $orderId): array
    {
        $order = wc_get_order($orderId);
        $row = $this->repo->find($orderId);
        if (!$order instanceof \WC_Order || $row === null || $row['status'] !== 'draft') {
            return $this->result(false, __('Silinecek bir taslak bulunamadı.', 'ty-e-faturam'));
        }
        $this->repo->delete($orderId);
        $order->delete_meta_data('_tef_invoice_status');
        $order->delete_meta_data('_tef_invoice_type');
        $order->delete_meta_data('_tef_draft_summary');
        $order->save_meta_data();
        $order->add_order_note(__('TY E-Faturam: Fatura taslağı silindi.', 'ty-e-faturam'));
        return $this->result(true, __('Taslak silindi.', 'ty-e-faturam'));
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function refreshStatus(int $orderId): array
    {
        $order = wc_get_order($orderId);
        $row = $this->repo->find($orderId);
        if (!$order instanceof \WC_Order || $row === null || $row['invoice_uuid'] === '') {
            return $this->result(false, __('Bu sipariş için sorgulanacak fatura UUID değeri yok.', 'ty-e-faturam'));
        }

        $response = $this->api->invoiceStatus($row['invoice_type'], $row['invoice_uuid'], $orderId);
        if (!$response['ok']) {
            // translators: %s: error message.
            return $this->result(false, sprintf(__('Durum sorgulanamadı: %s', 'ty-e-faturam'), $response['error']));
        }

        $body = $response['data'];
        $remote = isset($body['status']) ? (string) $body['status'] : (string) $row['remote_status'];
        $gib = (string) ($body['gibStatus'] ?? $body['gibStatusCode'] ?? $row['gib_status']);

        if (in_array((int) $remote, self::FAILED_STATUSES, true)) {
            // translators: %s: remote status code.
            $message = sprintf(__('Fatura işlenirken hata oluştu (durum kodu %s). Yeniden gönderebilirsiniz.', 'ty-e-faturam'), $remote);
            $this->repo->save($orderId, [
                'status'        => 'failed',
                'remote_status' => $remote,
                'gib_status'    => $gib,
                'error_message' => $message,
            ]);
            $order->update_meta_data('_tef_invoice_status', 'failed');
            $order->update_meta_data('_tef_error', $message);
            $order->delete_meta_data('_tef_invoice_uuid');
            $order->save_meta_data();
            // translators: 1: status message, 2: invoice UUID.
            $order->add_order_note(sprintf(__('TY E-Faturam: %1$s UUID: %2$s', 'ty-e-faturam'), $message, $row['invoice_uuid']));
            return $this->result(false, $message);
        }

        $pdf = $this->findPdfUrl($body);
        if ($pdf === '') {
            $pdf = (string) $row['pdf_url'];
        }
        if ($pdf === '' && (int) $remote >= 30) {
            $pdf = $this->fetchPdfUrl($row['invoice_type'], $row['invoice_uuid'], $orderId);
        }

        $done = $this->isCompleted($body);
        $status = $done ? 'completed' : 'created';

        $this->repo->save($orderId, [
            'status'        => $status,
            'remote_status' => $remote,
            'gib_status'    => $gib,
            'pdf_url'       => $pdf,
            'error_message' => '',
        ]);

        $order->update_meta_data('_tef_invoice_status', $status);
        $order->update_meta_data('_tef_pdf_url', $pdf);
        $order->save_meta_data();

        if ($done && $row['status'] !== 'completed') {
            // translators: %s: invoice UUID.
            $order->add_order_note(sprintf(__('TY E-Faturam: Fatura tamamlandı. UUID: %s', 'ty-e-faturam'), $row['invoice_uuid']));
            do_action('ty_e_faturam_invoice_completed', $orderId);
        }

        $label = $gib !== '' ? $gib : $remote;
        // translators: %s: invoice status label.
        return $this->result(true, $done ? __('Fatura tamamlandı.', 'ty-e-faturam') : sprintf(__('Fatura işleniyor. Durum: %s', 'ty-e-faturam'), $label));
    }

    /**
     * Kayıtlı PDF bağlantısını döndürür; yoksa (veya $force ise) API'den yenisini alıp kaydeder.
     */
    public function ensurePdfUrl(int $orderId, bool $force = false): string
    {
        $row = $this->repo->find($orderId);
        if ($row === null || (string) $row['invoice_uuid'] === '') {
            return '';
        }
        $current = (string) $row['pdf_url'];
        if ($current !== '' && !$force) {
            return $current;
        }
        $url = $this->fetchPdfUrl((string) $row['invoice_type'], (string) $row['invoice_uuid'], $orderId);
        if ($url === '') {
            return $current;
        }
        $this->repo->save($orderId, ['pdf_url' => $url]);
        $order = wc_get_order($orderId);
        if ($order instanceof \WC_Order) {
            $order->update_meta_data('_tef_pdf_url', $url);
            $order->save_meta_data();
        }
        return $url;
    }

    private function fetchPdfUrl(string $type, string $uuid, int $orderId): string
    {
        $docType = $type === 'EFATURA' ? 'EINVOICE' : 'EARCHIVE';
        foreach (['permanent', 'temporary'] as $kind) {
            $url = $this->api->documentUrl($docType, $uuid, $kind, $orderId);
            if ($url !== '') {
                return $url;
            }
        }
        return '';
    }

    /**
     * Tamamlanma: e-Arşiv GİB'e raporlandı (REPORTED), GİB başarı kodu 1300 veya onaylandı (205).
     */
    private function isCompleted(array $body): bool
    {
        if (($body['gibStatus'] ?? '') === 'REPORTED') {
            return true;
        }
        if (isset($body['gibStatusCode']) && (int) $body['gibStatusCode'] === 1300) {
            return true;
        }
        return isset($body['status']) && (int) $body['status'] === 205;
    }

    private function findPdfUrl(mixed $data): string
    {
        if (!is_array($data)) {
            return '';
        }
        foreach ($data as $key => $value) {
            if (is_string($value) && preg_match('#^https?://#i', $value)) {
                if (stripos((string) $key, 'pdf') !== false || preg_match('/\.pdf(\?|$)/i', $value)) {
                    return esc_url_raw($value);
                }
            } elseif (is_array($value)) {
                $found = $this->findPdfUrl($value);
                if ($found !== '') {
                    return $found;
                }
            }
        }
        return '';
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function fail(\WC_Order $order, string $type, string $message): array
    {
        $this->repo->save($order->get_id(), [
            'invoice_type'  => $type,
            'status'        => 'failed',
            'error_message' => $message,
        ]);
        $order->update_meta_data('_tef_invoice_status', 'failed');
        $order->update_meta_data('_tef_invoice_type', $type);
        $order->update_meta_data('_tef_error', $message);
        $order->save_meta_data();
        // translators: %s: error message.
        $order->add_order_note(sprintf(__('TY E-Faturam: Fatura oluşturulamadı. %s', 'ty-e-faturam'), $message));

        return $this->result(false, $message);
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function result(bool $success, string $message): array
    {
        return ['success' => $success, 'message' => $message];
    }
}
