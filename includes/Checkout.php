<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

/**
 * Klasik ödeme sayfası (sipariş notlarının üstünde): Bireysel / Kurumsal seçimi ve buna bağlı Vergi No / TCKN ile Vergi Dairesi alanları.
 */
class Checkout
{
    private const TYPE_KEY = 'billing_tef_customer_type';
    private const FIELD_KEYS = ['billing_tef_customer_type', 'billing_tax_number', 'billing_tax_office'];

    public function register(): void
    {
        if (Settings::get('checkout_fields') !== 'yes') {
            return;
        }
        add_filter('woocommerce_checkout_fields', [$this, 'addFields']);
        add_filter('woocommerce_form_field_tef_toggle', [$this, 'renderToggle'], 10, 4);
        add_action('woocommerce_before_order_notes', [$this, 'renderSection']);
        add_filter('woocommerce_form_field', [$this, 'stripOptionalLabel'], 10, 2);
        add_action('woocommerce_checkout_process', [$this, 'validate']);
        add_filter('woocommerce_checkout_posted_data', [$this, 'postedData']);
        add_filter('woocommerce_process_checkout_field_billing_tax_number', [$this, 'digitsOnly']);
        add_filter('woocommerce_process_checkout_field_billing_tax_office', 'sanitize_text_field');
        add_filter('woocommerce_process_checkout_field_billing_tef_customer_type', [$this, 'cleanType']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        wp_enqueue_style('ty-e-faturam-checkout', TY_E_FATURAM_URL . 'assets/css/checkout.css', [], TY_E_FATURAM_VERSION);
        $accent = self::color((string) Settings::get('toggle_active'), '#2271b1');
        $track = self::color((string) Settings::get('toggle_inactive'), '#e6e8eb');
        wp_add_inline_style(
            'ty-e-faturam-checkout',
            sprintf(
                '.tef-invoice-fields{--tef-accent:%1$s;--tef-track:%2$s;--tef-on-accent:%3$s;--tef-text:%4$s;}',
                $accent,
                $track,
                self::luminance($accent) > 0.179 ? '#111827' : '#ffffff',
                self::luminance($track) > 0.179 ? '#374151' : '#e5e7eb'
            )
        );
        wp_enqueue_script('ty-e-faturam-checkout', TY_E_FATURAM_URL . 'assets/js/checkout.js', ['jquery'], TY_E_FATURAM_VERSION, true);
        wp_localize_script('ty-e-faturam-checkout', 'tefCheckout', [
            'defaultTckn'           => (string) Settings::get('fallback_tckn'),
            'individualLabel'       => __('TC Kimlik No', 'ty-e-faturam'),
            'individualPlaceholder' => __('Fatura için TC kimlik no', 'ty-e-faturam'),
            'corporateLabel'        => __('Vergi No / TCKN', 'ty-e-faturam'),
            'corporatePlaceholder'  => __('Fatura için vergi no veya TCKN', 'ty-e-faturam'),
        ]);
    }

    private static function color(string $value, string $fallback): string
    {
        $color = sanitize_hex_color($value);
        return $color ? $color : $fallback;
    }

    /** WCAG göreli parlaklık değeri (0-1); yazı rengini okunabilir seçmek için kullanılır. */
    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = (string) preg_replace('/(.)/', '$1$1', $hex);
        }
        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $v = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * Alanlar, adres alanlarından ayrı bir grupta (tef_invoice) tanımlanır; böylece ödeme sayfasında
     * sipariş notlarının üstünde gösterilebilir. billing_ önekli anahtarlar sipariş ve müşteri meta verisine otomatik kaydedilir.
     *
     * @param array<string,array<string,array<string,mixed>>> $fieldsets
     * @return array<string,array<string,array<string,mixed>>>
     */
    public function addFields(array $fieldsets): array
    {
        $fieldsets['tef_invoice'] = [
            self::TYPE_KEY => [
                'type'     => 'tef_toggle',
                'label'    => __('Fatura Türü', 'ty-e-faturam'),
                'options'  => [
                    'individual' => __('Bireysel', 'ty-e-faturam'),
                    'corporate'  => __('Kurumsal', 'ty-e-faturam'),
                ],
                'required' => false,
                'priority' => 10,
            ],
            'billing_tax_number' => [
                'type'              => 'text',
                'label'             => __('Vergi No / TCKN', 'ty-e-faturam'),
                'placeholder'       => __('Fatura için vergi no veya TCKN', 'ty-e-faturam'),
                'required'          => false,
                'class'             => ['form-row-first'],
                'priority'          => 20,
                'custom_attributes' => ['maxlength' => '11', 'inputmode' => 'numeric', 'pattern' => '[0-9]*'],
            ],
            'billing_tax_office' => [
                'type'     => 'text',
                'label'    => __('Vergi Dairesi', 'ty-e-faturam'),
                'required' => false,
                'class'    => ['form-row-last'],
                'priority' => 30,
            ],
        ];
        return $fieldsets;
    }

    /** Fatura alanlarını "Ek bilgi / Sipariş notları" bölümünün hemen üstünde basar. */
    public function renderSection(mixed $checkout): void
    {
        if (!$checkout instanceof \WC_Checkout) {
            return;
        }
        $fields = $checkout->get_checkout_fields('tef_invoice');
        if (!is_array($fields) || $fields === []) {
            return;
        }
        echo '<div class="tef-invoice-fields"><h3>' . esc_html__('Fatura Bilgileri', 'ty-e-faturam') . '</h3>';
        foreach ($fields as $key => $args) {
            woocommerce_form_field((string) $key, $args, $checkout->get_value((string) $key));
        }
        echo '</div>';
    }

    /**
     * Bireysel / Kurumsal anahtarı (toggle). Gerçek radyo düğmeleri görünmez kalır; görünüm CSS ile verilir.
     *
     * @param array<string,mixed> $args
     */
    public function renderToggle(mixed $field, string $key, array $args, mixed $value): string
    {
        $options = (array) ($args['options'] ?? []);
        $current = (string) $value;
        $label = (string) ($args['label'] ?? '');

        $html = '<p class="form-row form-row-wide tef-toggle-row" id="' . esc_attr($key) . '_field">';
        $html .= '<span class="tef-toggle-title">' . esc_html($label) . '</span>';
        $html .= '<span class="tef-toggle" role="radiogroup" aria-label="' . esc_attr($label) . '">';
        foreach ($options as $optKey => $optLabel) {
            $html .= '<input type="radio" class="tef-toggle__input" name="' . esc_attr($key) . '" id="' . esc_attr($key . '_' . $optKey) . '" value="' . esc_attr((string) $optKey) . '"' . checked($current, (string) $optKey, false) . '>';
        }
        $html .= '<span class="tef-toggle__thumb" aria-hidden="true"></span>';
        foreach ($options as $optKey => $optLabel) {
            $html .= '<label class="tef-toggle__option" for="' . esc_attr($key . '_' . $optKey) . '">' . esc_html((string) $optLabel) . '</label>';
        }
        $html .= '</span></p>';
        return $html;
    }

    /**
     * Zorunluluk sunucu tarafında seçime göre denetlendiği için "(isteğe bağlı)" ibaresi gizlenir.
     */
    public function stripOptionalLabel(mixed $field, string $key): mixed
    {
        if (is_string($field) && in_array($key, self::FIELD_KEYS, true)) {
            $field = (string) preg_replace('#\s*<span class="optional">.*?</span>#s', '', $field);
        }
        return $field;
    }

    public function cleanType(mixed $value): string
    {
        $value = (string) $value;
        return in_array($value, ['individual', 'corporate'], true) ? $value : '';
    }

    /**
     * Bireysel faturada vergi dairesi kaydedilmez.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function postedData(array $data): array
    {
        if ((string) ($data[self::TYPE_KEY] ?? '') === 'individual') {
            $data['billing_tax_office'] = '';
        }
        return $data;
    }

    public function validate(): void
    {
        // Nonce, e-ticaret altyapısı tarafından bu kanca çalışmadan önce doğrulanır.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $type = isset($_POST[self::TYPE_KEY]) ? sanitize_text_field(wp_unslash($_POST[self::TYPE_KEY])) : '';
        $raw = isset($_POST['billing_tax_number']) ? sanitize_text_field(wp_unslash($_POST['billing_tax_number'])) : '';
        $office = isset($_POST['billing_tax_office']) ? sanitize_text_field(wp_unslash($_POST['billing_tax_office'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $digits = (string) preg_replace('/\D/', '', $raw);
        $length = strlen($digits);

        if ($type === 'individual') {
            if ($digits !== '' && $length !== 11) {
                wc_add_notice(__('Bireysel fatura için TC Kimlik No 11 haneli rakamlardan oluşmalıdır.', 'ty-e-faturam'), 'error');
            }
            return;
        }

        if ($type === 'corporate') {
            if ($digits === '') {
                wc_add_notice(__('Kurumsal fatura için Vergi No / TCKN zorunludur.', 'ty-e-faturam'), 'error');
            } elseif ($length !== 10 && $length !== 11) {
                wc_add_notice(__('Vergi No / TCKN alanı 10 veya 11 haneli rakamlardan oluşmalıdır.', 'ty-e-faturam'), 'error');
            }
            if (trim($office) === '') {
                wc_add_notice(__('Kurumsal fatura için Vergi Dairesi zorunludur.', 'ty-e-faturam'), 'error');
            }
            return;
        }

        if ($digits !== '' && $length !== 10 && $length !== 11) {
            wc_add_notice(__('Vergi No / TCKN alanı 10 veya 11 haneli rakamlardan oluşmalıdır.', 'ty-e-faturam'), 'error');
        }
    }

    public function digitsOnly(mixed $value): string
    {
        return (string) preg_replace('/\D/', '', (string) $value);
    }
}
