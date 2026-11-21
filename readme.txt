=== TY E-Faturam ===
Contributors: 3rkadir
Tags: e-invoice, e-fatura, e-arşiv, trendyol e faturam, e-archive, invoice, turkey, orders
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.4.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

TY E-Faturam API’sini kullanarak mağaza siparişlerinden e-Fatura / e-Arşiv faturası oluşturur, gönderilmeden önce onay isteyen bir taslak modu vardır.

== Description ==

Bu eklenti, hizmet sağlayıcının resmi bir ürünü değildir; TY E-Faturam API'sini kullanan bağımsız bir entegrasyondur.

* Oturum açma ve erişim belirteci önbelleğe alma (Geçici Veri API'si); Şirket Kimliği ve Kullanıcı Kimliği belirteçten okunur.
* E-Fatura/e-Arşiv türünü otomatik olarak belirlemek için mükellef sorgulama
* **Taslak modu:** Faturalar önce taslak olarak kaydedilir ve "Onayla ve Gönder" ile gönderilir
* Sipariş listesinde "Fatura" sütunu ve sipariş ekranında meta kutusu (AJAX)
* Bekleyen/gönderilen fatura listeleri ve filtrelenebilir API kayıtları (30 gün saklanır)
* ** Müşteri erişimi: ** Hesabım> Siparişler altındaki ve sipariş ayrıntıları sayfasındaki "Fatura (PDF)" düğmesi; PDF siteniz üzerinden yayınlanır ve sipariş anahtarı ile korunur
* ** Müşteri e-postası: ** fatura tamamlandığında isteğe bağlı olarak indirme bağlantısı veya ekli PDF içeren bir e-posta gönderir; sipariş ekranından manuel olarak da gönderilebilir
* ** Toplu faturalama: ** siparişler listesindeki toplu işlemler (faturalar oluşturun, taslakları onaylayın ve gönderin, siparişleri kontrol edin).
This plugin is not an official product of the service provider; it is an independent integration that uses the TY E-Faturam API.

* Sign-in and access token caching (Transients API); Company ID and User ID are read from the token
* Taxpayer lookup to automatically determine the e-Invoice / e-Archive type
* **Draft mode:** invoices are first saved as drafts and sent with "Approve and Send"
* "Invoice" column in the order list and a meta box on the order screen (AJAX)
* Lists of pending / sent invoices and filterable API logs (kept for 30 days)
* **Customer access:** "Invoice (PDF)" button under My Account > Orders and on the order details page; the PDF is streamed through your site and protected by the order key
* **Customer email:** optionally sends an email with a download link, or with the PDF attached, when the invoice is completed; can also be sent manually from the order screen
* **Bulk invoicing:** bulk actions on the orders list (create invoices, approve and send drafts, check status) and a bulk create button with progress on the Pending Invoices page
* Hourly cron job that checks the status of incomplete invoices
* Compatible with High-Performance Order Storage (HPOS)
* Turkish / English interface: choose the language in Settings (Automatic, Turkish, English)

Developer: Kadir Er. Support: 3rkadir@gmail.com

== External services ==

This plugin connects to the TY E-Faturam API to create invoices and query their status:

* Test: https://stage-apigateway.trendyolefaturam.com
* Live: https://apigateway.trendyolecozum.com

Data sent: your API email and password (for sign-in only); when creating an invoice, the customer name, company, tax number / national ID, tax office, address, phone, email and order items from the order; for the taxpayer lookup, the tax number / national ID. The invoice PDF is downloaded from the file address returned by the API, only when a customer or administrator requests it or when an email with an attachment is sent. Data is sent only when you create an invoice, when an order status changes while automatic invoicing is enabled, or when the status check runs. In draft mode, no invoice data is sent until the invoice is approved (except for the taxpayer lookup).

Service provider: TY E-Faturam, https://developers.trendyolefaturam.com/docs

== Installation ==

1. Upload the folder to `wp-content/plugins/` and activate it (an e-commerce platform plugin is required).
2. On the TY E-Faturam > General Settings page, enter your API email and password and choose the environment.
3. Use the "Test Connection and Fetch Company Details" button; Company ID and User ID are retrieved automatically.
4. First try it with a test order while the Test Environment and Draft Mode are enabled.

== Frequently Asked Questions ==

= Where does the tax number come from? =
From the meta key set in the settings (default `_billing_tax_number`). On the classic checkout page, above the order notes, the plugin can add an Individual / Corporate choice: Individual shows a National ID field pre-filled with the default value from the settings (the customer can change it), Corporate shows Tax No / National ID and Tax Office fields. These fields do not appear on the block-based checkout.

= Can I change the endpoint paths? =
Yes. Under Settings > Advanced, or with the `ty_e_faturam_endpoint_paths` / `ty_e_faturam_invoice_payload` filters.

= Is my data deleted? =
When the plugin is deleted, tables and settings are removed only if "Delete Data on Uninstall" is selected.

== Changelog ==

= 1.4.3 =
* The invoice details chosen at checkout (Individual / Corporate, National ID or Tax No, Tax Office) are now shown on the admin order screen (and editable there), on the customer order pages and in order emails.

= 1.4.2 =
* Fixed a fatal error on load (missing class import in the order UI) that caused a blank site and admin.

= 1.4.1 =
* Fixed a fatal error that left the Pending Invoices and Sent Invoices pages blank.

= 1.4.0 =
* Bulk invoice creation: new bulk actions on the orders list (up to 20 orders per run) and a select-and-process option with live progress on the Pending Invoices page.

= 1.3.5 =
* Settings: color pickers (with live preview) for the selected and unselected sides of the Individual / Corporate toggle.

= 1.3.4 =
* Classic checkout: invoice fields are now shown right above the order notes section.

= 1.3.3 =
* Classic checkout: invoice fields are now shown at the bottom of the checkout form, and the Individual / Corporate toggle was rebuilt with its own markup and styles.

= 1.3.2 =
* Classic checkout: the Individual / Corporate choice is now displayed as a toggle switch.

= 1.3.1 =
* Classic checkout: Individual / Corporate selection that shows the matching National ID or Tax No and Tax Office fields.

= 1.3.0 =
* Customer invoice access: PDF download button in My Account > Orders and order details.
* Optional invoice email to the customer (download link or PDF attachment), with a manual send / resend action on the order screen.

= 1.2.0 =
* Language setting: Automatic / Turkish / English. English translation added.

= 1.1.0 =
* Draft mode: invoices can be saved as drafts awaiting approval.
* Additional request fields (JSON) setting.
* Log entries are deleted automatically after 30 days; privacy policy text added.
* Exemption code/reason for zero VAT.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.0 =
The My Account invoice button is enabled by default. The customer invoice email is off by default; enable it under Settings > Invoice Email.

= 1.2.0 =
The interface language can be selected under Settings > Language. The default is "Automatic": Turkish if the site language is Turkish, otherwise English.

= 1.1.0 =
Draft mode is enabled by default; invoices are sent with "Approve and Send". To send directly, disable Draft Mode in Settings.
