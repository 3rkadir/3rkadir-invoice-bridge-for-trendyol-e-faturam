<?php
/**
 * Genel Ayarlar sayfası.
 *
 * @var array<string,mixed>  $s
 * @var array<string,string> $statuses
 */

use TYEfaturam\Settings;

defined('ABSPATH') || exit;

$ty_e_faturam_opt = Settings::OPTION;
$ty_e_faturam_selected = is_array($s['order_statuses']) ? $s['order_statuses'] : [];
$ty_e_faturam_account = Settings::account();
$ty_e_faturam_autoRows = [
    __('Company ID', 'ty-e-faturam')  => $ty_e_faturam_account['company_id'],
    __('User ID', 'ty-e-faturam')     => $ty_e_faturam_account['user_id'],
    __('Şirket UUID', 'ty-e-faturam') => $ty_e_faturam_account['company_uuid'],
    __('Şirket Adı', 'ty-e-faturam')  => $ty_e_faturam_account['company_name'],
];
?>
<div class="wrap tef-wrap">
    <h1><?php echo esc_html__('TY E-Faturam — Genel Ayarlar', 'ty-e-faturam'); ?></h1>
    <?php settings_errors(); ?>

    <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
        <?php settings_fields(Settings::GROUP); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="tef_language"><?php echo esc_html__('Dil', 'ty-e-faturam'); ?></label></th>
                <td>
                    <select id="tef_language" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[language]">
                        <option value="auto" <?php selected($s['language'], 'auto'); ?>><?php echo esc_html__('Otomatik (site diline göre)', 'ty-e-faturam'); ?></option>
                        <option value="tr" <?php selected($s['language'], 'tr'); ?>>Türkçe</option>
                        <option value="en" <?php selected($s['language'], 'en'); ?>>English</option>
                    </select>
                    <p class="description"><?php echo esc_html__('Eklenti arayüzünün ve mesajlarının dili. Fatura içeriği (ürün adları, notlar) her zaman Türkçe gönderilir.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_username"><?php echo esc_html__('API E-posta (Bireysel Kullanıcı)', 'ty-e-faturam'); ?></label></th>
                <td><input type="text" class="regular-text" id="tef_username" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[username]" value="<?php echo esc_attr((string) $s['username']); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_password"><?php echo esc_html__('API Şifre', 'ty-e-faturam'); ?></label></th>
                <td>
                    <input type="password" class="regular-text" id="tef_password" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[password]" value="" autocomplete="new-password" placeholder="<?php echo $s['password'] !== '' ? '••••••••' : ''; ?>">
                    <p class="description"><?php echo esc_html__('Şifre şifrelenerek saklanır. Değiştirmek istemiyorsanız boş bırakın.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Ortam', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="radio" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[environment]" value="test" <?php checked($s['environment'], 'test'); ?>> <?php echo esc_html__('Test Ortamı', 'ty-e-faturam'); ?></label><br>
                    <label><input type="radio" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[environment]" value="live" <?php checked($s['environment'], 'live'); ?>> <?php echo esc_html__('Canlı Ortam', 'ty-e-faturam'); ?></label>
                    <p class="description"><?php echo esc_html__('Test: stage-apigateway.trendyolefaturam.com — Canlı: apigateway.trendyolecozum.com. Test ve canlı ortamın kullanıcıları farklı olabilir.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_company_vkn"><?php echo esc_html__('Şirket VKN / TCKN', 'ty-e-faturam'); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="tef_company_vkn" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[company_vkn]" value="<?php echo esc_attr((string) $s['company_vkn']); ?>" maxlength="11" inputmode="numeric">
                    <p class="description"><?php echo esc_html__('Şirket adı, bu numaranın mükellef sorgusundan (getTaxPayersByTaxId) otomatik alınır. Girişte kullanılmaz.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Otomatik Alınan Şirket Bilgileri', 'ty-e-faturam'); ?></th>
                <td>
                    <table class="tef-meta">
                        <?php foreach ($ty_e_faturam_autoRows as $ty_e_faturam_label => $ty_e_faturam_value) : ?>
                            <tr>
                                <th><?php echo esc_html($ty_e_faturam_label); ?></th>
                                <td><?php echo $ty_e_faturam_value !== '' ? esc_html($ty_e_faturam_value) : '<em>' . esc_html__('henüz alınmadı', 'ty-e-faturam') . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <p>
                        <button type="button" class="button" id="tef-test-connection"><?php echo esc_html__('Bağlantıyı Test Et ve Şirket Bilgilerini Getir', 'ty-e-faturam'); ?></button>
                    </p>
                    <p id="tef-test-result" class="tef-msg tef-block"></p>
                    <p class="description"><?php echo esc_html__('Önce ayarları kaydedin. Giriş: POST /api/auth/signin; Company ID ve User ID token (JWT) içinden okunur.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Elle Giriş (isteğe bağlı)', 'ty-e-faturam'); ?></th>
                <td>
                    <p class="description"><?php echo esc_html__('Giriş (signIn) yanıtı bu değerleri döndürmüyorsa buraya yazın. Company ID ve User ID fatura için zorunludur; dolu alanlar otomatik değerlerin yerine kullanılır.', 'ty-e-faturam'); ?></p>
                    <p>
                        <label for="tef_company_id"><?php echo esc_html__('Company ID', 'ty-e-faturam'); ?></label><br>
                        <input type="text" class="regular-text" id="tef_company_id" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[company_id]" value="<?php echo esc_attr((string) $s['company_id']); ?>" inputmode="numeric">
                    </p>
                    <p>
                        <label for="tef_user_id"><?php echo esc_html__('User ID', 'ty-e-faturam'); ?></label><br>
                        <input type="text" class="regular-text" id="tef_user_id" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[user_id]" value="<?php echo esc_attr((string) $s['user_id']); ?>" inputmode="numeric">
                    </p>
                    <p>
                        <label for="tef_company_uuid"><?php echo esc_html__('Şirket UUID', 'ty-e-faturam'); ?></label><br>
                        <input type="text" class="regular-text" id="tef_company_uuid" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[company_uuid]" value="<?php echo esc_attr((string) $s['company_uuid']); ?>">
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Taslak Modu', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[draft_mode]" value="yes" <?php checked($s['draft_mode'], 'yes'); ?>> <?php echo esc_html__('Faturaları doğrudan kesme; önce taslak olarak kaydet, "Onayla ve Gönder" ile göndereyim', 'ty-e-faturam'); ?></label>
                    <p class="description"><?php echo esc_html__('Açıkken sipariş verileri hazırlanır ve doğrulanır ancak TY E-Faturam\'a fatura gönderilmez. Taslaklar "Bekleyen Faturalar" sayfasında ve sipariş ekranında görünür. Otomatik fatura da bu moda uyar.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Otomatik Fatura Oluştur', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[auto_invoice]" value="yes" <?php checked($s['auto_invoice'], 'yes'); ?>> <?php echo esc_html__('Sipariş aşağıdaki durumlardan birine geçince fatura oluştur', 'ty-e-faturam'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Sipariş Durumu Seçimi', 'ty-e-faturam'); ?></th>
                <td>
                    <?php foreach ($statuses as $ty_e_faturam_key => $ty_e_faturam_label) :
                        $ty_e_faturam_slug = preg_replace('/^wc-/', '', (string) $ty_e_faturam_key);
                        ?>
                        <label class="tef-check"><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[order_statuses][]" value="<?php echo esc_attr($ty_e_faturam_slug); ?>" <?php checked(in_array($ty_e_faturam_slug, $ty_e_faturam_selected, true)); ?>> <?php echo esc_html($ty_e_faturam_label); ?></label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_einvoice_prefix"><?php echo esc_html__('e-Fatura Ön Eki', 'ty-e-faturam'); ?></label></th>
                <td><input type="text" id="tef_einvoice_prefix" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[einvoice_prefix]" value="<?php echo esc_attr((string) $s['einvoice_prefix']); ?>" maxlength="3" size="4"> <span class="description"><?php echo esc_html__('Boş bırakın; yalnızca portalda tanımlı 3 harfli seri varsa yazın.', 'ty-e-faturam'); ?></span></td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_earchive_prefix"><?php echo esc_html__('e-Arşiv Ön Eki', 'ty-e-faturam'); ?></label></th>
                <td><input type="text" id="tef_earchive_prefix" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[earchive_prefix]" value="<?php echo esc_attr((string) $s['earchive_prefix']); ?>" maxlength="3" size="4"></td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Ödeme Sayfası Alanları', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[checkout_fields]" value="yes" <?php checked($s['checkout_fields'], 'yes'); ?>> <?php echo esc_html__('Klasik ödeme sayfasına Bireysel / Kurumsal seçimi ile Vergi No / TCKN ve Vergi Dairesi alanlarını ekle', 'ty-e-faturam'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Bireysel / Kurumsal Anahtar Renkleri', 'ty-e-faturam'); ?></th>
                <td>
                    <p>
                        <label for="tef_toggle_active"><?php echo esc_html__('Seçili bölüm rengi', 'ty-e-faturam'); ?></label><br>
                        <input type="text" id="tef_toggle_active" class="tef-color-field" data-default-color="#2271b1" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[toggle_active]" value="<?php echo esc_attr((string) $s['toggle_active']); ?>">
                    </p>
                    <p>
                        <label for="tef_toggle_inactive"><?php echo esc_html__('Seçili olmayan bölüm rengi', 'ty-e-faturam'); ?></label><br>
                        <input type="text" id="tef_toggle_inactive" class="tef-color-field" data-default-color="#e6e8eb" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[toggle_inactive]" value="<?php echo esc_attr((string) $s['toggle_inactive']); ?>">
                    </p>
                    <div class="tef-invoice-fields tef-toggle-preview" style="margin-top:12px;">
                        <span class="tef-toggle-title"><?php echo esc_html__('Önizleme', 'ty-e-faturam'); ?></span>
                        <span class="tef-toggle" role="radiogroup">
                            <input type="radio" class="tef-toggle__input" name="tef_preview" id="tef_preview_individual" value="individual" checked>
                            <input type="radio" class="tef-toggle__input" name="tef_preview" id="tef_preview_corporate" value="corporate">
                            <span class="tef-toggle__thumb" aria-hidden="true"></span>
                            <label class="tef-toggle__option" for="tef_preview_individual"><?php echo esc_html__('Bireysel', 'ty-e-faturam'); ?></label>
                            <label class="tef-toggle__option" for="tef_preview_corporate"><?php echo esc_html__('Kurumsal', 'ty-e-faturam'); ?></label>
                        </span>
                    </div>
                    <p class="description"><?php echo esc_html__('Ödeme sayfasındaki Bireysel / Kurumsal anahtarının renkleri. Yazı renkleri okunabilirlik için otomatik seçilir.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_tax_id_key"><?php echo esc_html__('Vergi No Meta Anahtarı', 'ty-e-faturam'); ?></label></th>
                <td><input type="text" class="regular-text" id="tef_tax_id_key" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[tax_id_key]" value="<?php echo esc_attr((string) $s['tax_id_key']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_tax_office_key"><?php echo esc_html__('Vergi Dairesi Meta Anahtarı', 'ty-e-faturam'); ?></label></th>
                <td><input type="text" class="regular-text" id="tef_tax_office_key" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[tax_office_key]" value="<?php echo esc_attr((string) $s['tax_office_key']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_exemption_code"><?php echo esc_html__('KDV 0 Muafiyet Kodu / Sebebi', 'ty-e-faturam'); ?></label></th>
                <td>
                    <input type="text" id="tef_exemption_code" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[exemption_code]" value="<?php echo esc_attr((string) $s['exemption_code']); ?>" maxlength="4" size="6">
                    <input type="text" class="regular-text" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[exemption_reason]" value="<?php echo esc_attr((string) $s['exemption_reason']); ?>">
                    <p class="description"><?php echo esc_html__('KDV tutarı sıfır olan satırlar için API bu bilgiyi zorunlu tutar (varsayılan: 351 - İstisna Olmayan Diğer). Sıfır KDV beklemiyorsanız mağaza vergi ayarlarınızı kontrol edin.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_fallback_tckn"><?php echo esc_html__('Bireysel Müşteri Varsayılan TCKN', 'ty-e-faturam'); ?></label></th>
                <td>
                    <input type="text" id="tef_fallback_tckn" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[fallback_tckn]" value="<?php echo esc_attr((string) $s['fallback_tckn']); ?>" maxlength="11" size="14">
                    <p class="description"><?php echo esc_html__('Vergi numarası girmeyen bireysel müşteriler için e-Arşiv faturasında kullanılır.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Müşteri Fatura Erişimi', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[account_pdf]" value="yes" <?php checked($s['account_pdf'], 'yes'); ?>> <?php echo esc_html__('"Hesabım > Siparişler" ekranında fatura PDF indirme bağlantısı göster', 'ty-e-faturam'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_customer_email"><?php echo esc_html__('Fatura E-postası', 'ty-e-faturam'); ?></label></th>
                <td>
                    <select id="tef_customer_email" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[customer_email]">
                        <?php
                        $ty_e_faturam_mail_modes = [
                            'off'    => __('Gönderme', 'ty-e-faturam'),
                            'link'   => __('Yalnızca indirme bağlantısı gönder', 'ty-e-faturam'),
                            'attach' => __('PDF ekle ve indirme bağlantısı gönder', 'ty-e-faturam'),
                        ];
                        foreach ($ty_e_faturam_mail_modes as $ty_e_faturam_mode => $ty_e_faturam_mode_label) : ?>
                            <option value="<?php echo esc_attr($ty_e_faturam_mode); ?>" <?php selected($s['customer_email'], $ty_e_faturam_mode); ?>><?php echo esc_html($ty_e_faturam_mode_label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php echo esc_html__('Fatura tamamlandığında müşteriye otomatik e-posta gönderilir. Sipariş ekranından elle de gönderebilirsiniz.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Kaldırırken Veriyi Sil', 'ty-e-faturam'); ?></th>
                <td>
                    <label><input type="checkbox" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[delete_data]" value="yes" <?php checked($s['delete_data'], 'yes'); ?>> <?php echo esc_html__('Eklenti silindiğinde tablolar ve ayarlar da silinsin', 'ty-e-faturam'); ?></label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="tef_extra_payload"><?php echo esc_html__('Gelişmiş: Ek İstek Alanları (JSON)', 'ty-e-faturam'); ?></label></th>
                <td>
                    <textarea id="tef_extra_payload" class="large-text code" rows="3" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[extra_payload]" placeholder='{"alanAdi": "değer"}'><?php echo esc_textarea((string) $s['extra_payload']); ?></textarea>
                    <p class="description"><?php echo esc_html__('Fatura isteğine eklenecek ek alanlar (ör. API dokümanındaki isteğe bağlı bir alan). Yalnızca geçerli bir JSON nesnesi kabul edilir; mevcut alanların üzerine yazar.', 'ty-e-faturam'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php echo esc_html__('Gelişmiş: Uç Nokta Yolları', 'ty-e-faturam'); ?></th>
                <td>
                    <details>
                        <summary><?php echo esc_html__('Yolları göster / değiştir', 'ty-e-faturam'); ?></summary>
                        <p class="description"><?php echo esc_html__('Boş bırakılırsa varsayılan yol kullanılır. Dokümandaki yolu olduğu gibi yazabilirsiniz; {taxId} / :taxId ve {uuid} / :invoiceUuid yer tutucuları desteklenir.', 'ty-e-faturam'); ?></p>
                        <?php
                        $ty_e_faturam_paths = [
                            'path_sign_in'         => [__('Giriş (signIn)', 'ty-e-faturam'), '/api/auth/signin'],
                            'path_taxpayer'        => [__('Mükellef sorgusu (getTaxPayersByTaxId)', 'ty-e-faturam'), '/api/invoice/taxpayers/{taxId}'],
                            'path_create_earchive' => [__('e-Arşiv oluştur (createEArchive)', 'ty-e-faturam'), '/api/invoice/documents/earchive'],
                            'path_create_einvoice' => [__('e-Fatura oluştur (createOutgoingEInvoice)', 'ty-e-faturam'), '/api/invoice/documents/outgoing-einvoice'],
                            'path_status_earchive' => [__('e-Arşiv durum', 'ty-e-faturam'), '/api/invoice/documents/earchive/status/{uuid}'],
                            'path_status_einvoice' => [__('e-Fatura durum', 'ty-e-faturam'), '/api/invoice/documents/outgoing-einvoice/status/{uuid}'],
                        ];
                        foreach ($ty_e_faturam_paths as $ty_e_faturam_key => $ty_e_faturam_def) : ?>
                            <p>
                                <label for="tef_<?php echo esc_attr($ty_e_faturam_key); ?>"><?php echo esc_html($ty_e_faturam_def[0]); ?></label><br>
                                <input type="text" class="large-text code" id="tef_<?php echo esc_attr($ty_e_faturam_key); ?>" name="<?php echo esc_attr($ty_e_faturam_opt); ?>[<?php echo esc_attr($ty_e_faturam_key); ?>]" value="<?php echo esc_attr((string) $s[$ty_e_faturam_key]); ?>" placeholder="<?php echo esc_attr($ty_e_faturam_def[1]); ?>">
                            </p>
                        <?php endforeach; ?>
                    </details>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr>
    <p class="description"><?php echo esc_html__('Geliştirici: Kadir Er — Destek: 3rkadir@gmail.com', 'ty-e-faturam'); ?></p>
    <h2><?php echo esc_html__('Mükellef Sorgusu Testi', 'ty-e-faturam'); ?></h2>
    <p class="description"><?php echo esc_html__('Bir VKN / TCKN için gerçek API yanıtını görün. Yol veya yanıt biçimi farklıysa burada hemen anlaşılır.', 'ty-e-faturam'); ?></p>
    <p>
        <input type="text" id="tef-lookup-taxid" maxlength="11" inputmode="numeric" placeholder="<?php echo esc_attr__('VKN / TCKN', 'ty-e-faturam'); ?>">
        <button type="button" class="button" id="tef-lookup"><?php echo esc_html__('Sorgula', 'ty-e-faturam'); ?></button>
    </p>
    <p id="tef-lookup-result" class="tef-msg tef-block"></p>
    <pre id="tef-lookup-detail" class="tef-pre" hidden></pre>
</div>
