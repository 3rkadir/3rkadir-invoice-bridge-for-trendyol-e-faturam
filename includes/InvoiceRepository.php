<?php

namespace TYEfaturam;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Eklentinin kendi özel tabloları; çekirdekte bunlar için önbellek API'si yoktur.

defined('ABSPATH') || exit;

class InvoiceRepository
{
    private const COLUMNS = [
        'invoice_uuid',
        'invoice_number',
        'invoice_type',
        'status',
        'remote_status',
        'gib_status',
        'pdf_url',
        'error_message',
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tef_invoices';
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'created'   => __('Gönderildi (işleniyor)', 'ty-e-faturam'),
            'completed' => __('Tamamlandı', 'ty-e-faturam'),
            'failed'    => __('Başarısız', 'ty-e-faturam'),
            'draft'     => __('Taslak (onay bekliyor)', 'ty-e-faturam'),
            default     => __('Fatura yok', 'ty-e-faturam'),
        };
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'EFATURA' => __('e-Fatura', 'ty-e-faturam'),
            'EARSIV'  => __('e-Arşiv', 'ty-e-faturam'),
            default   => '—',
        };
    }

    public function find(int $orderId): ?array
    {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', $table, $orderId),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public function save(int $orderId, array $data): void
    {
        global $wpdb;
        $row = array_intersect_key($data, array_flip(self::COLUMNS));
        $row['updated_at'] = current_time('mysql');

        if ($this->find($orderId) !== null) {
            $wpdb->update(self::table(), $row, ['order_id' => $orderId], null, ['%d']);
            return;
        }
        $row['order_id'] = $orderId;
        $row['created_at'] = current_time('mysql');
        $wpdb->insert(self::table(), $row);
    }

    public function delete(int $orderId): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['order_id' => $orderId], ['%d']);
    }

    /**
     * @param array{order_id?:int,status?:string} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        global $wpdb;
        $table = self::table();

        $orderId = max(0, (int) ($filters['order_id'] ?? 0));
        $status = (string) ($filters['status'] ?? '');
        if (!in_array($status, ['created', 'completed'], true)) {
            $status = '';
        }

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE invoice_uuid <> '' AND (%d = 0 OR order_id = %d) AND (%s = '' OR status = %s)",
                $table,
                $orderId,
                $orderId,
                $status,
                $status
            )
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE invoice_uuid <> '' AND (%d = 0 OR order_id = %d) AND (%s = '' OR status = %s) ORDER BY id DESC LIMIT %d OFFSET %d",
                $table,
                $orderId,
                $orderId,
                $status,
                $status,
                $perPage,
                $offset
            ),
            ARRAY_A
        );

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * UUID'si olup henüz tamamlanmamış faturalar.
     *
     * @return array<int,array<string,mixed>>
     */
    public function pendingRemote(int $limit): array
    {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE invoice_uuid <> '' AND status NOT IN ('completed', 'failed') ORDER BY updated_at ASC LIMIT %d",
                $table,
                $limit
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : [];
    }
}
