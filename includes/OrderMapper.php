<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class OrderMapper
{
    private const FALLBACK_TAX_KEYS = [
        '_billing_tax_number',
        'billing_tax_number',
        '_billing_vat_number',
        '_billing_vergi_no',
        '_billing_tc_no',
        '_billing_tckn',
        '_billing_tax_id',
        '_billing_vkn',
    ];

    private const FALLBACK_OFFICE_KEYS = [
        '_billing_tax_office',
        'billing_tax_office',
        '_billing_vergi_dairesi',
    ];

    /**
     * Tüm tutarlar kuruş cinsinden tam sayıdır (API sözleşmesi).
     *
     * @return array<string,mixed>
     */
    public function map(\WC_Order $order): array
    {
        $acc = ['gross' => 0, 'discount' => 0, 'breakdown' => []];
        $lines = $this->lines($order, $acc);

        return [
            'order_id'     => $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'date'         => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d') : gmdate('Y-m-d'),
            'currency'     => $order->get_currency() ?: 'TRY',
            'customer'     => $this->customer($order),
            'lines'        => $lines,
            'totals'       => $this->totals($acc),
        ];
    }

    private function kurus(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function upper(string $value): string
    {
        $value = str_replace(['i', 'ı'], ['İ', 'I'], $value);
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    /**
     * @return array<string,string>
     */
    private function customer(\WC_Order $order): array
    {
        $country = $order->get_billing_country() ?: ($order->get_shipping_country() ?: 'TR');
        $stateCode = $order->get_billing_state();
        $city = $order->get_billing_city();
        $addr = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $postcode = $order->get_billing_postcode();

        if ($addr === '' && $city === '') {
            $stateCode = $order->get_shipping_state();
            $city = $order->get_shipping_city();
            $addr = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());
            $postcode = $order->get_shipping_postcode();
        }

        $states = (function_exists('WC') && WC()->countries) ? WC()->countries->get_states($country) : [];
        $state = (is_array($states) && isset($states[$stateCode])) ? (string) $states[$stateCode] : (string) $stateCode;

        if ($state !== '') {
            $province = $state;
            $district = $city;
        } else {
            $province = $city;
            $district = '';
        }

        $company = trim($order->get_billing_company());
        $first = trim($order->get_billing_first_name());
        $last = trim($order->get_billing_last_name());
        $taxId = $this->taxId($order);

        $customerType = (string) $order->get_meta('_billing_tef_customer_type');

        if ($company !== '' && ($customerType === 'corporate' || ($customerType === '' && strlen($taxId) === 10))) {
            $name = $company;
            $surname = '';
        } else {
            $name = $first;
            $surname = $last;
        }
        if (mb_strlen($name) < 2) {
            $name = $company !== '' ? $company : 'Müşteri';
        }

        return [
            'company'    => $company,
            'tax_id'     => $taxId,
            'tax_office' => $this->taxOffice($order),
            'name'       => mb_substr($name, 0, 127),
            'surname'    => mb_strlen($surname) >= 2 ? mb_substr($surname, 0, 255) : '',
            'phone'      => $this->phone($order->get_billing_phone()),
            'email'      => (string) $order->get_billing_email(),
            'address'    => mb_strlen($addr) >= 2 ? mb_substr($addr, 0, 255) : '',
            'city'       => mb_strlen($province) >= 2 ? $this->upper($province) : 'BELİRTİLMEDİ',
            'district'   => $district !== '' ? $this->upper($district) : '',
            'country'    => strtoupper($country),
            'postcode'   => preg_match('/^\d{5}$/', (string) $postcode) ? (string) $postcode : '',
        ];
    }

    public function taxId(\WC_Order $order): string
    {
        $keys = array_unique(array_filter(array_merge([(string) Settings::get('tax_id_key')], self::FALLBACK_TAX_KEYS)));
        foreach ($keys as $key) {
            $value = preg_replace('/\D/', '', (string) $order->get_meta($key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public function taxOffice(\WC_Order $order): string
    {
        $keys = array_unique(array_filter(array_merge([(string) Settings::get('tax_office_key')], self::FALLBACK_OFFICE_KEYS)));
        foreach ($keys as $key) {
            $value = sanitize_text_field((string) $order->get_meta($key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function phone(string $raw): string
    {
        $digits = (string) preg_replace('/\D/', '', $raw);
        if (strlen($digits) === 12 && strpos($digits, '90') === 0) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = substr($digits, 1);
        }
        return strlen($digits) === 10 ? $digits : '';
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<int,array<string,mixed>>
     */
    private function lines(\WC_Order $order, array &$acc): array
    {
        $lines = [];

        foreach ($order->get_items('line_item') as $item) {
            /** @var \WC_Order_Item_Product $item */
            $qty = (float) $item->get_quantity();
            if ($qty <= 0) {
                continue;
            }
            $gross = $this->kurus((float) $item->get_subtotal());
            $taxable = $this->kurus((float) $item->get_total());
            $tax = $this->kurus((float) $item->get_total_tax());
            $discount = max(0, $gross - $taxable);
            $rate = $this->rate((float) $item->get_total(), (float) $item->get_total_tax());

            $product = $item->get_product();
            $sku = $product ? (string) $product->get_sku() : '';
            $name = $item->get_name() . ($sku !== '' ? ' (' . $sku . ')' : '');

            $lines[] = $this->line($name, $qty, (int) round($gross / $qty), $gross, $discount, $taxable, $rate, $tax);
            $acc['gross'] += $gross;
            $acc['discount'] += $discount;
            $this->addBreakdown($acc, $rate, $taxable, $tax);
        }

        foreach ($order->get_items('shipping') as $item) {
            $total = $this->kurus((float) $item->get_total());
            $tax = $this->kurus((float) $item->get_total_tax());
            if ($total <= 0 && $tax <= 0) {
                continue;
            }
            $rate = $this->rate((float) $item->get_total(), (float) $item->get_total_tax());
            $lines[] = $this->line('Kargo: ' . $item->get_name(), 1.0, $total, $total, 0, $total, $rate, $tax);
            $acc['gross'] += $total;
            $this->addBreakdown($acc, $rate, $total, $tax);
        }

        foreach ($order->get_items('fee') as $item) {
            $total = $this->kurus((float) $item->get_total());
            $tax = $this->kurus((float) $item->get_total_tax());
            $rate = $this->rate(abs((float) $item->get_total()), abs((float) $item->get_total_tax()));
            if ($total >= 0) {
                $lines[] = $this->line($item->get_name(), 1.0, $total, $total, 0, $total, $rate, $tax);
                $acc['gross'] += $total;
            } else {
                $acc['discount'] += abs($total);
            }
            $this->addBreakdown($acc, $rate, $total, $tax);
        }

        return $lines;
    }

    private function rate(float $taxable, float $tax): float
    {
        return $taxable > 0 ? (float) round($tax / $taxable * 100) : 0.0;
    }

    /**
     * @return array<string,mixed>
     */
    private function line(string $name, float $qty, int $unit, int $gross, int $discount, int $taxable, float $rate, int $tax): array
    {
        return [
            'name'       => mb_substr($name, 0, 255),
            'quantity'   => $qty,
            'unit_price' => $unit,
            'total'      => $gross,
            'discount'   => $discount,
            'taxable'    => $taxable,
            'tax_rate'   => $rate,
            'tax'        => $tax,
        ];
    }

    /**
     * @param array<string,mixed> $acc
     */
    private function addBreakdown(array &$acc, float $rate, int $taxable, int $tax): void
    {
        $key = (string) $rate;
        if (!isset($acc['breakdown'][$key])) {
            $acc['breakdown'][$key] = ['rate' => $rate, 'taxable' => 0, 'tax' => 0];
        }
        $acc['breakdown'][$key]['taxable'] += $taxable;
        $acc['breakdown'][$key]['tax'] += $tax;
    }

    /**
     * @param array<string,mixed> $acc
     * @return array<string,mixed>
     */
    private function totals(array $acc): array
    {
        $tax = 0;
        foreach ($acc['breakdown'] as $entry) {
            $tax += $entry['tax'];
        }
        $taxExclusive = $acc['gross'] - $acc['discount'];

        return [
            'line_extension' => $acc['gross'],
            'allowance'      => $acc['discount'],
            'tax_exclusive'  => $taxExclusive,
            'tax'            => $tax,
            'tax_inclusive'  => $taxExclusive + $tax,
            'breakdown'      => array_values($acc['breakdown']),
        ];
    }
}
