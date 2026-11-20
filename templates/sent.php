<?php
/**
 * Gönderilmiş Faturalar sayfası.
 *
 * @var array<int,array<string,mixed>>     $rows
 * @var int                                $total_pages
 * @var int                                $paged
 * @var int                                $order_id
 * @var string                             $status
 * @var \TYEfaturam\Admin\OrderUi    $orderUi
 */

use TYEfaturam\InvoiceRepository;

defined('ABSPATH') || exit;
?>
<div class="wrap tef-wrap">
    <h1><?php echo esc_html__('Gönderilmiş Faturalar', 'ty-e-faturam'); ?></h1>

    <form method="get" class="tef-filter">
        <input type="hidden" name="page" value="tef-sent">
        <label for="tef-order-id"><?php echo esc_html__('Sipariş No', 'ty-e-faturam'); ?></label>
        <input type="number" min="0" id="tef-order-id" name="order_id" value="<?php echo $order_id > 0 ? esc_attr((string) $order_id) : ''; ?>">
        <select name="status">
            <option value=""><?php echo esc_html__('Tüm durumlar', 'ty-e-faturam'); ?></option>
            <option value="created" <?php selected($status, 'created'); ?>><?php echo esc_html(InvoiceRepository::statusLabel('created')); ?></option>
            <option value="completed" <?php selected($status, 'completed'); ?>><?php echo esc_html(InvoiceRepository::statusLabel('completed')); ?></option>
        </select>
        <button type="submit" class="button"><?php echo esc_html__('Filtrele', 'ty-e-faturam'); ?></button>
    </form>

    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Sipariş', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Tip', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Fatura No', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('UUID', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Oluşturulma', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Fatura', 'ty-e-faturam'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)) : ?>
            <tr><td colspan="6"><?php echo esc_html__('Kayıt bulunamadı.', 'ty-e-faturam'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($rows as $ty_e_faturam_row) :
                $order = wc_get_order((int) $ty_e_faturam_row['order_id']);
                ?>
                <tr>
                    <td>
                        <?php if ($order instanceof \WC_Order) : ?>
                            <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a>
                        <?php else : ?>
                            #<?php echo esc_html((string) $ty_e_faturam_row['order_id']); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(InvoiceRepository::typeLabel((string) $ty_e_faturam_row['invoice_type'])); ?></td>
                    <td><?php echo esc_html((string) ($ty_e_faturam_row['invoice_number'] !== '' ? $ty_e_faturam_row['invoice_number'] : '—')); ?></td>
                    <td><code><?php echo esc_html((string) $ty_e_faturam_row['invoice_uuid']); ?></code></td>
                    <td><?php echo esc_html((string) $ty_e_faturam_row['created_at']); ?></td>
                    <td><?php echo $orderUi->renderActions((int) $ty_e_faturam_row['order_id'], '', true, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
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
</div>
