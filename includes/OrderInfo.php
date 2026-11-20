<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

/**
 * Ödeme sayfasında seçilen fatura bilgilerini (Bireysel/Kurumsal, TCKN/Vergi No, Vergi Dairesi)
 * sipariş ekranında, müşteri sipariş sayfalarında ve e-postalarda gösterir; yönetici ekranında düzenlenebilir yapar.
 */
class OrderInfo
{
    public function __construct(private OrderMapper $mapper)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'renderAdmin']);
        add_filter('woocommerce_admin_billing_fields', [$this, 'adminBillingFields']);
        add_action('woocommerce_order_details_after_customer_details', [$this, 'renderCustomer']);
        add_filter('woocommerce_email_customer_details_fields', [$this, 'emailFields'], 10, 3);
    }

    /**
     * @return array<int,array{label:string,value:string}>
     */
    public function rows(\WC_Order $order): array
    {
        $type = (string) $order->get_meta('_billing_tef_customer_type');
        $number = $this->mapper->taxId($order);
        $office = $this->mapper->taxOffice($order);

        $rows = [];
        if ($type === 'individual' || $type === 'corporate') {
            $rows[] = [
                'label' => __('Fatura Türü', 'ty-e-faturam'),
                'value' => $type === 'corporate' ? __('Kurumsal', 'ty-e-faturam') : __('Bireysel', 'ty-e-faturam'),
            ];
        }
        if ($number !== '') {
            $rows[] = [
                'label' => $type === 'individual' ? __('TC Kimlik No', 'ty-e-faturam') : __('Vergi No / TCKN', 'ty-e-faturam'),
                'value' => $number,
            ];
        }
        if ($office !== '') {
            $rows[] = ['label' => __('Vergi Dairesi', 'ty-e-faturam'), 'value' => $office];
        }
        return $rows;
    }

    /** Sipariş düzenleme ekranında, fatura adresinin altında. */
    public function renderAdmin(mixed $order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $rows = $this->rows($order);
        if ($rows === []) {
            return;
        }
        echo '<div class="tef-order-invoice-info"><h3>' . esc_html__('Fatura Bilgileri', 'ty-e-faturam') . '</h3>';
        foreach ($rows as $row) {
            echo '<p><strong>' . esc_html($row['label']) . ':</strong> ' . esc_html($row['value']) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Sipariş düzenleme formuna alanlar ekler (yalnızca düzenleme formunda görünür; okuma görünümünü renderAdmin basar).
     *
     * @param array<string,array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    public function adminBillingFields(array $fields): array
    {
        if (!isset($fields['tef_customer_type'])) {
            $fields['tef_customer_type'] = [
                'label'         => __('Fatura Türü', 'ty-e-faturam'),
                'show'          => false,
                'type'          => 'select',
                'wrapper_class' => 'form-field-wide',
                'options'       => [
                    ''           => __('Seçilmedi', 'ty-e-faturam'),
                    'individual' => __('Bireysel', 'ty-e-faturam'),
                    'corporate'  => __('Kurumsal', 'ty-e-faturam'),
                ],
            ];
        }
        if (!isset($fields['tax_number'])) {
            $fields['tax_number'] = [
                'label'         => __('Vergi No / TCKN', 'ty-e-faturam'),
                'show'          => false,
                'wrapper_class' => 'form-field-wide',
            ];
        }
        if (!isset($fields['tax_office'])) {
            $fields['tax_office'] = [
                'label'         => __('Vergi Dairesi', 'ty-e-faturam'),
                'show'          => false,
                'wrapper_class' => 'form-field-wide',
            ];
        }
        return $fields;
    }

    /** Teşekkür sayfası ve Hesabım > Sipariş görüntüle ekranında. */
    public function renderCustomer(mixed $order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        $rows = $this->rows($order);
        if ($rows === []) {
            return;
        }
        echo '<section class="tef-order-invoice-info"><h2 class="woocommerce-column__title">' . esc_html__('Fatura Bilgileri', 'ty-e-faturam') . '</h2>';
        foreach ($rows as $row) {
            echo '<p><strong>' . esc_html($row['label']) . ':</strong> ' . esc_html($row['value']) . '</p>';
        }
        echo '</section>';
    }

    /**
     * Sipariş e-postalarındaki müşteri bilgileri tablosuna satır ekler.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function emailFields(mixed $fields, mixed $sentToAdmin, mixed $order): mixed
    {
        if (!is_array($fields) || !$order instanceof \WC_Order) {
            return $fields;
        }
        foreach ($this->rows($order) as $index => $row) {
            $fields['tef_invoice_' . $index] = ['label' => $row['label'], 'value' => $row['value']];
        }
        return $fields;
    }
}
