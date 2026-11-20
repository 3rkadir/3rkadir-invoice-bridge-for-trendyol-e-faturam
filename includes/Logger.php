<?php

namespace TYEfaturam;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Eklentinin kendi özel tabloları; çekirdekte bunlar için önbellek API'si yoktur.

defined('ABSPATH') || exit;

class Logger
{
    public const MAX_RESPONSE = 60000;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tef_logs';
    }

    public function log(int $orderId, string $method, string $endpoint, int $httpCode, string $response): void
    {
        global $wpdb;

        $response = $this->mask($response);
        if (strlen($response) > self::MAX_RESPONSE) {
            $cut = function_exists('mb_strcut')
                ? mb_strcut($response, 0, self::MAX_RESPONSE, 'UTF-8')
                : wp_check_invalid_utf8(substr($response, 0, self::MAX_RESPONSE), true);
            $response = $cut . '…';
        }

        $wpdb->insert(
            self::table(),
            [
                'created_at' => current_time('mysql'),
                'order_id'   => $orderId,
                'method'     => substr($method, 0, 10),
                'endpoint'   => substr($endpoint, 0, 255),
                'http_code'  => $httpCode,
                'is_success' => ($httpCode >= 200 && $httpCode < 300) ? 1 : 0,
                'response'   => $response,
            ],
            ['%s', '%d', '%s', '%s', '%d', '%d', '%s']
        );
    }

    private function mask(string $text): string
    {
        $masked = preg_replace(
            '/("(?:accessToken|access_token|refreshToken|token|jwt|password)"\s*:\s*")[^"]*(")/i',
            '$1***$2',
            $text
        );
        return $masked ?? $text;
    }

    /**
     * @param array{result?:string,order_id?:int} $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public function query(array $filters, int $page, int $perPage): array
    {
        global $wpdb;
        $table = self::table();

        $result = (string) ($filters['result'] ?? '');
        // -1: filtre yok, 1: başarılı, 0: başarısız.
        $success = -1;
        if ($result === 'success') {
            $success = 1;
        } elseif ($result === 'failed') {
            $success = 0;
        }
        $orderId = max(0, (int) ($filters['order_id'] ?? 0));

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE (%d = -1 OR is_success = %d) AND (%d = 0 OR order_id = %d)",
                $table,
                $success,
                $success,
                $orderId,
                $orderId
            )
        );

        $offset = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE (%d = -1 OR is_success = %d) AND (%d = 0 OR order_id = %d) ORDER BY id DESC LIMIT %d OFFSET %d",
                $table,
                $success,
                $success,
                $orderId,
                $orderId,
                $perPage,
                $offset
            ),
            ARRAY_A
        );

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /**
     * Belirtilen günden eski log kayıtlarını siler.
     */
    public function prune(int $days): void
    {
        global $wpdb;
        $table = self::table();
        $limit = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $table, $limit));
    }

    public function purge(): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare('TRUNCATE TABLE %i', $table));
    }
}
