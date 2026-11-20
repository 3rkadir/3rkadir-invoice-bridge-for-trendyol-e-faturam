<?php
/**
 * Bekleyen Faturalar sayfası.
 *
 * @var \WC_Order[]                        $orders
 * @var int                                $total_pages
 * @var int                                $paged
 * @var array<int,array<string,mixed>>     $in_progress
 * @var \TYEfaturam\Admin\OrderUi    $orderUi
 */

defined('ABSPATH') || exit;
?>
<div class="wrap tef-wrap">
    <h1><?php echo esc_html__('Bekleyen Faturalar', 'ty-e-faturam'); ?></h1>

    <h2><?php echo esc_html__('Faturası oluşturulmamış veya hatalı siparişler', 'ty-e-faturam'); ?></h2>
    <p class="tef-bulk-bar">
        <button type="button" class="button button-primary" id="tef-bulk-run"><?php echo esc_html__('Seçilen Siparişlere Toplu Fatura Oluştur', 'ty-e-faturam'); ?></button>
        <span id="tef-bulk-status" class="tef-bulk-status" aria-live="polite"></span>
    </p>
    <p class="description"><?php echo esc_html__('Taslak modu açıksa taslak kaydedilir; taslak durumundaki siparişler onaylanıp gönderilir; hatalı olanlar yeniden denenir. Siparişler sırayla işlenir.', 'ty-e-faturam'); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <td class="check-column"><input type="checkbox" id="tef-bulk-all" aria-label="<?php echo esc_attr__('Tümünü seç', 'ty-e-faturam'); ?>"></td>
                <th><?php echo esc_html__('Sipariş', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Tarih', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Müşteri', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Tutar', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Fatura', 'ty-e-faturam'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($orders)) : ?>
            <tr><td colspan="6"><?php echo esc_html__('Bekleyen sipariş bulunmuyor.', 'ty-e-faturam'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($orders as $order) :
                if (!$order instanceof \WC_Order) {
                    continue;
                }
                $ty_e_faturam_customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
                if ($ty_e_faturam_customer === '') {
                    $ty_e_faturam_customer = $order->get_billing_company();
                }
                $ty_e_faturam_date = $order->get_date_created();
                ?>
                <tr>
                    <th scope="row" class="check-column"><input type="checkbox" class="tef-bulk-check" value="<?php echo esc_attr((string) $order->get_id()); ?>"></th>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html((string) $order->get_order_number()); ?></a></td>
                    <td><?php echo esc_html($ty_e_faturam_date ? $ty_e_faturam_date->date_i18n(get_option('date_format') . ' ' . get_option('time_format')) : '—'); ?></td>
                    <td><?php echo esc_html($ty_e_faturam_customer !== '' ? $ty_e_faturam_customer : '—'); ?></td>
                    <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                    <td><?php echo $orderUi->renderActions($order->get_id(), '', true, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
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

    <h2><?php echo esc_html__('Gönderilmiş, henüz tamamlanmamış faturalar', 'ty-e-faturam'); ?></h2>
    <p class="description"><?php echo esc_html__('Bu faturaların durumu saatlik olarak otomatik sorgulanır.', 'ty-e-faturam'); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php echo esc_html__('Sipariş', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Tip', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('UUID', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('GİB / Durum', 'ty-e-faturam'); ?></th>
                <th><?php echo esc_html__('Fatura', 'ty-e-faturam'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($in_progress)) : ?>
            <tr><td colspan="5"><?php echo esc_html__('İşlenmekte olan fatura yok.', 'ty-e-faturam'); ?></td></tr>
        <?php else : ?>
            <?php foreach ($in_progress as $ty_e_faturam_row) : ?>
                <tr>
                    <td>#<?php echo esc_html((string) $ty_e_faturam_row['order_id']); ?></td>
                    <td><?php echo esc_html(\TYEfaturam\InvoiceRepository::typeLabel((string) $ty_e_faturam_row['invoice_type'])); ?></td>
                    <td><code><?php echo esc_html((string) $ty_e_faturam_row['invoice_uuid']); ?></code></td>
                    <td><?php echo esc_html((string) ($ty_e_faturam_row['gib_status'] !== '' ? $ty_e_faturam_row['gib_status'] : $ty_e_faturam_row['remote_status'])); ?></td>
                    <td><?php echo $orderUi->renderActions((int) $ty_e_faturam_row['order_id'], '', true, true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
