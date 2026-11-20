<?php

namespace TYEfaturam;

defined('ABSPATH') || exit;

class PayloadBuilder
{
    /**
     * İstek gövdesini ty-e-faturam-go istemcisindeki CreateInvoiceRequest yapısına göre üretir.
     * Tüm tutarlar kuruş cinsinden tam sayıdır. Alanlar `ty_e_faturam_invoice_payload` filtresiyle uyarlanabilir.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function build(array $data, string $type): array
    {
        $settings = Settings::all();
        $c = $data['customer'];
        $t = $data['totals'];

        $recipient = array_filter([
            'taxId'       => $c['tax_id'],
            'countryCode' => $c['country'],
            'city'        => $c['city'],
            'district'    => $c['district'],
            'address'     => $c['address'],
            'postalCode'  => $c['postcode'],
            'phone'       => $c['phone'],
            'email'       => $c['email'],
            'name'        => $c['name'],
            'surname'     => $c['surname'],
            'taxOffice'   => $c['tax_office'],
        ], static fn($v): bool => $v !== '' && $v !== null);

        $lines = [];
        foreach ($data['lines'] as $l) {
            $lines[] = [
                'unitCode'            => 'C62',
                'quantity'            => $this->num((float) $l['quantity']),
                'totalAmount'         => (int) $l['total'],
                'taxAmount'           => (int) $l['tax'],
                'taxableAmount'       => (int) $l['taxable'],
                'taxPercent'          => $this->num((float) $l['tax_rate']),
                'taxName'             => 'KDV',
                'itemName'            => $l['name'],
                'unitPriceAmount'     => (int) $l['unit_price'],
                'totalDiscountAmount' => (int) $l['discount'],
                'totalTax'            => [
                    'totalTaxAmount' => (int) $l['tax'],
                    'subTotalTaxes'  => [$this->subTax((int) $l['taxable'], (int) $l['tax'], (float) $l['tax_rate'])],
                ],
            ];
        }

        $subTotals = [];
        foreach ($t['breakdown'] as $entry) {
            if ((int) $entry['taxable'] === 0 && (int) $entry['tax'] === 0) {
                continue;
            }
            $subTotals[] = $this->subTax((int) $entry['taxable'], (int) $entry['tax'], (float) $entry['rate']);
        }

        $isEInvoice = $type === 'EFATURA';

        $payload = [
            'autoInvoiceId'    => true,
            'companyId'        => Settings::companyId(),
            'userId'           => Settings::userId(),
            'source'           => 'PORTAL',
            'localReferenceId' => 'WC-' . $data['order_id'],
            'notes'            => ['Sipariş no: ' . $data['order_number']],
            'recipientInfo'    => $recipient,
            'currencyInfo'     => ['currency' => $data['currency'], 'hasExchange' => false, 'calculationRate' => 0],
            'orderInfo'        => ['orderId' => $data['order_number'], 'orderDate' => $data['date']],
            'invoiceInfo'      => [
                'invoiceType'     => $isEInvoice ? 'TEMELFATURA' : 'EARSIVFATURA',
                'invoiceTypeCode' => 'SATIS',
            ],
            'invoiceLines'     => $lines,
            'totalTax'         => ['totalTaxAmount' => (int) $t['tax'], 'subTotalTaxes' => $subTotals],
            'invoiceTotal'     => [
                'lineExtensionAmount'  => (int) $t['line_extension'],
                'taxExclusiveAmount'   => (int) $t['tax_exclusive'],
                'taxInclusiveAmount'   => (int) $t['tax_inclusive'],
                'payableAmount'        => (int) $t['tax_inclusive'],
                'allowanceTotalAmount' => (int) $t['allowance'],
            ],
        ];

        $prefix = (string) ($isEInvoice ? $settings['einvoice_prefix'] : $settings['earchive_prefix']);
        if ($prefix !== '') {
            $payload['prefix'] = $prefix;
        }

        $alias = (string) ($data['target_alias'] ?? '');
        if ($isEInvoice && $alias !== '') {
            $payload['targetAlias'] = $alias;
        }

        $extra = json_decode((string) Settings::get('extra_payload'), true);
        if (is_array($extra) && $extra !== []) {
            $payload = array_replace_recursive($payload, $extra);
        }

        return (array) apply_filters('ty_e_faturam_invoice_payload', $payload, $data, $type);
    }

    /**
     * @return array<string,mixed>
     */
    private function subTax(int $taxable, int $tax, float $rate): array
    {
        $entry = [
            'taxableAmount' => $taxable,
            'taxAmount'     => $tax,
            'taxType'       => 'KDV',
            'percent'       => $this->num($rate),
            'taxUnitPrice'  => 0,
            'name'          => 'KDV %' . $this->num($rate),
        ];
        if ($tax === 0) {
            // KDV tutarı sıfırsa API muafiyet sebebi ve kodunu zorunlu tutar.
            $entry['taxExemptionReason'] = (string) Settings::get('exemption_reason');
            $entry['taxExemptionReasonCode'] = (int) Settings::get('exemption_code');
        }
        return $entry;
    }

    private function num(float $value): int|float
    {
        return floor($value) === $value ? (int) $value : $value;
    }
}
