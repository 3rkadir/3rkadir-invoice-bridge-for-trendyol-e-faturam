<?php
/**
 * Loglar sayfası.
 *
 * @var array<int,array<string,mixed>> $rows
 * @var int                            $total_pages
 * @var int                            $paged
 * @var int                            $order_id
 * @var string                         $result
 */

defined('ABSPATH') || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$ty_e_faturam_purged = isset($_GET['purged']) && sanitize_text_field(wp_unslash($_GET['purged'])) === '1';
?>
<div class="wrap tef-wrap">
    <h1><?php echo esc_html__('TY E-Faturam — Loglar', 'ty-e-faturam'); ?></h1>

    <?php if ($ty_e_faturam_purged) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html__('Loglar temizlendi.', 'ty-e-faturam'); ?></p></div>
    <?php endif; ?>

    <form method="get" class="tef-filter">
        <input type="hidden" name="page" value="tef-logs">
        <select name="result">
            <option value=""><?php echo esc_html__('Tümü', 'ty-e-faturam'); ?></option>
            <option value="success" <?php selected($result, 'success'); ?>><?php echo esc_html__('Başarılı', 'ty-e-faturam'); ?></option>
            <option value="failed" <?php selected($result, 'failed'); ?>><?php echo esc_html__('Başarısız', 'ty-e-faturam'); ?></option>
        </select>
        <label for="tef-log-order"><?php echo esc_html__('Sipariş No', 'ty-e-faturam'); ?></label>
        <input type="number" min="0" id="tef-log-order" name="order_id" value="<?php echo $order_id > 0 ? esc_attr((string) $order_id) : ''; ?>">
        <button type="submit" class="button"><?php echo esc_html__('Filtrele', 'ty-e-faturam'); ?></button>
    </form>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Tarih', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Sipariş ID', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Endpoint', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('HTTP Kodu', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Response', 'ty-e-faturam'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)) : ?>
            <tr><td colspan="5"><?php echo esc_html__('Kayıt bulunamadı.', 'ty-e-faturam'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($rows as $ty_e_faturam_row) : ?>
                <tr>
                    <td><?php echo esc_html((string) $ty_e_faturam_row['created_at']); ?></td>
                    <td><?php echo (int) $ty_e_faturam_row['order_id'] > 0 ? esc_html((string) $ty_e_faturam_row['order_id']) : '—'; ?></td>
                    <td><code><?php echo esc_html($ty_e_faturam_row['method'] . ' ' . $ty_e_faturam_row['endpoint']); ?></code></td>
                    <td>
                        <span class="tef-badge <?php echo (int) $ty_e_faturam_row['is_success'] === 1 ? 'tef-badge-completed' : 'tef-badge-failed'; ?>">
                            <?php echo esc_html((string) $ty_e_faturam_row['http_code']); ?>
                        </span>
                    </td>
                    <td>
                        <details>
                            <summary><?php echo esc_html__('Görüntüle', 'ty-e-faturam'); ?></summary>
                            <pre class="tef-pre"><?php echo esc_html((string) $ty_e_faturam_row['response']); ?></pre>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($total_pages > 1) : ?>
        <div class="tablenav"><div class="tablenav-pages">
            <?php
            echo wp_kses_post(paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]));
            ?>
        </div></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tef-purge" data-tef-confirm="<?php echo esc_attr__('Tüm loglar silinecek. Emin misiniz?', 'ty-e-faturam'); ?>">
        <input type="hidden" name="action" value="tef_purge_logs">
        <?php wp_nonce_field('tef_purge_logs'); ?>
        <button type="submit" class="button button-link-delete">
            <?php echo esc_html__('Tüm Logları Temizle', 'ty-e-faturam'); ?>
        </button>
    </form>
</div>
