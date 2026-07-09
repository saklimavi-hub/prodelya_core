# UI Preview Reference Lock A1 — 2026-07-09

## 1. Yönetici Özeti

* Bu fazda production ekranları değiştirilmedi.
* `public/css/prodelya-admin.css`, production Blade dosyaları ve controller dosyaları değiştirilmedi.
* Referans HTML proje içinde bulundu ve resmi preview referansı olarak kilitlenebilir durumda.
* Referans HTML ile mevcut teklif detay Blade’i arasında güçlü yapısal benzerlik var; önemli bir kısmı zaten `resources/views/admin/promotion-quotes/show.blade.php` içine taşınmış.
* Ancak preview sınıfları doğrudan production’a kopyalanmamalı.
* Sonraki entegrasyon fazı için temel karar:
  * görsel yapı korunacak
  * font Prodelya standardına dönecek
  * genel sınıflar kontrollü namespace’e alınacak
  * önce teklif detay ekranında kontrollü pilot entegrasyon yapılacak

## 2. Referans HTML Dosya Durumu

* Dosya bulundu mu?
  * Evet
* Dosya yolu:
  * `docs/ui-previews/prodelya_teklif_detay_urun_baski_oncelikli_onizleme.html`
* Klasör durumu:
  * `docs/ui-previews` mevcut
* Ön izleme font durumu:
  * Preview dosyası `Inter` tabanlı font tanımı kullanıyor
  * Bu Prodelya production standardı değildir
* Net yorum:
  * Dosya resmi UI referansı olarak korunabilir
  * Ancak birebir CSS/font kopyası olarak değil, component mapping kaynağı olarak kullanılmalı

## 3. Referans HTML Bölüm Haritası

| Önizleme bölüm adı | HTML class yapısı | Prodelya Blade karşılığı | Yeni Blade component gerekir mi? | Component adı önerisi | CSS namespace önerisi | Gerçek veri kaynağı | Controller data contract | Permission / tenant / finans kuralı | Tekrar kullanılabilir mi? |
|---|---|---|---|---|---|---|---|---|---|
| Sidebar | `.app`, `.side`, `.brand`, `.nav-item` | `resources/views/layouts/prodelya-admin.blade.php` | Hayır, layout seviyesi | `x-pd.sidebar` sadece ileride gerekirse | `.pd-page`, `.pd-sidebar` | `$adminMenu`, tenant context, user | layout payload | tenant context görünür, hassas veri yok | Evet |
| Page header | `.page-head`, `.crumb` | `show.blade.php` `quote-page-head` | Evet | `x-pd.page-head` | `.pd-page`, `.pd-quote-detail` | quote kimliği, ekran başlığı | `$quote`, `$quoteSummary` | tenant erişimi zorunlu | Evet |
| Notice bar | `.notice` | `quote-alert`, `quote-inline-note` | Evet | `x-pd.notice` | `.pd-card`, `.pd-quote-detail` | flash message, uyarı | `$notice`, `$quoteAlertMessage` | hassas bilgi içermez | Evet |
| Quote strip | `.quote-strip`, `.quote-top`, `.status-line` | `quote-strip`, `quote-strip-top`, `quote-strip-chips` | Evet | `x-quote.summary-strip` | `.pd-quote-detail` | quote durum özetleri | `$quote`, `$approvalStatus`, `$sendStatus` | finans görünürlüğü filtrelenir | Evet |
| Top metrics | `.top-metrics`, `.metric` | `quote-top-metrics`, `quote-metric` | Evet | `x-pd.metric-grid` | `.pd-summary`, `.pd-quote-detail` | müşteri, tarih, toplamlar | `$quoteSummary` | toplamlar finans iznine göre maskelenebilir | Evet |
| Main layout | `.layout`, `.right` | `quote-layout`, `quote-main-stack`, `quote-right-stack` | Hayır, sayfa iskeleti | `x-pd.detail-layout` gerekirse | `.pd-page`, `.pd-summary` | layout shell | page view model | summary kolonundaki finans alanı yetkiliye göre | Evet |
| Ürün & Baskı öncelikli blok | `.priority-block`, `.items`, `.item-row`, `.print-line` | `quote-priority-block`, `promotion-quote-lines`, `promotion-quote-line-*` | Evet | `x-quote.product-print-block` | `.pd-product-line`, `.pd-print-line`, `.pd-quote-detail` | order items + prints | `$quoteItems`, `$printLines`, `$productHubWarnings` | maliyet ve supplier raw alanları gizli | Evet |
| Decision grid | `.decision-grid`, `.decision` | `quote-status-grid`, `quote-status-box` | Evet | `x-quote.decision-grid` | `.pd-card`, `.pd-quote-detail` | gönderim/onay/dönüşüm durumu | `$approvalStatus`, `$sendStatus`, `$timeline` | yetkisiz kullanıcıya iç detay verilmez | Evet |
| Action band | `.action-band`, `.action-buttons` | `quote-action-band`, `quote-action-buttons` | Evet | `x-pd.action-band` | `.pd-btn`, `.pd-quote-detail` | CTA seti | `$canSendQuote`, `$canApproveQuote`, `$canConvertQuote` | permission tabanlı buton görünürlüğü | Evet |
| Tabs | `.tabs`, `.tab`, `.panel` | `quote-tabs`, `quote-tab-button`, `quote-tab-panel` | Evet | `x-pd.tabs` | `.pd-tabs` | aktif tab + panel içerikleri | `$tabs`, `$activeTab`, `$tabPanels` | panel bazlı finans görünürlüğü olabilir | Evet |
| Right sticky summary | `.right`, `.summary-card`, `.sum-line`, `.info-line` | `quote-right-stack`, `quote-right-summary`, `quote-summary-line` | Evet | `x-pd.summary-panel` | `.pd-summary`, `.pd-sticky-bar` | özet, hızlı aksiyon, mini log | `$financialSummary`, `$approvalStatus`, `$timeline` | finans özeti yetkisiz kullanıcıya gizlenir | Evet |
| Bottom sticky action bar | `.bottom-bar`, `.bar-actions` | `@section('bottom_actions')`, `quote-bottom-bar` | Evet | `x-pd.bottom-actions` | `.pd-sticky-bar`, `.pd-btn` | ana CTA’lar | `$canSendQuote`, `$canApproveQuote`, `$canEditQuote` | permission tabanlı | Evet |
| Modal | `.modal-backdrop`, `.modal`, `.modal-grid`, `.pill` | `quoteSendModal`, `quoteConvertModal`, `quote-send-modal-*` | Evet | `x-pd.modal`, `x-quote.send-modal` | `.pd-modal`, `.pd-form` | gönderim formu, dönüştürme onayı | `$notificationChannels`, `$publicApprovalLink`, `$quote` | public URL gösterilebilir, secret gösterilmez | Evet |
| Buttons | `.btn`, `.btn.primary`, `.btn.ghost` | `pd-btn`, `pd-btn-primary`, `pd-btn-light`, `pd-btn-success` | Hayır, temel UI primitive | `x-pd.button` | `.pd-btn` | CTA label + action | action config | permission’a bağlı | Evet |
| Chips / badges | `.chip`, `.print-chip`, `.pill` | `pd-badge`, `quote-chip-soft`, `quote-channel-pill` | Evet | `x-pd.chip`, `x-pd.badge` | `.pd-chip` | durum, uyarı, kanal | `$badges`, `$warningBadges` | hassas metin taşımamalı | Evet |
| Cards | `.card`, `.card-pad`, `.summary-card` | `quote-card`, `quote-right-summary` | Evet | `x-pd.card` | `.pd-card` | section shell | section payload | özel kural yok | Evet |
| Form fields | `.field`, `.form-grid`, `.modal-grid` | `quote-send-field`, `quote-send-modal-field` | Evet | `x-pd.form-field` | `.pd-form` | alıcı adı, e-posta, telefon, mesaj | `$notificationChannels`, `$quoteSendForm` | readonly alanlar secret içermez | Evet |
| Responsive davranış | `@media` blokları | mevcut `prodelya-admin.css` quote detail responsive blokları | Hayır, sözleşme seviyesinde | `responsive contract` | `.pd-page`, `.pd-quote-detail`, `.pd-summary` | layout davranışı | viewport contract | mobile’da da finance/permission kuralları korunur | Evet |

## 4. Blade Component Mapping

Önerilen component seti:

* `x-pd.page-head`
* `x-pd.notice`
* `x-pd.metric-grid`
* `x-pd.card`
* `x-pd.button`
* `x-pd.chip`
* `x-pd.tabs`
* `x-pd.summary-panel`
* `x-pd.modal`
* `x-pd.form-field`
* `x-quote.summary-strip`
* `x-quote.product-print-block`
* `x-quote.decision-grid`
* `x-quote.action-band`
* `x-quote.send-modal`
* `x-quote.history-list`
* `x-quote.notes-box`

Özel mapping kararları:

* Preview `.btn` sınıfı production’da doğrudan kullanılmamalı.
  * `pd-btn` primitive’ine bağlanmalı.
* Preview `.chip` production’da doğrudan kullanılmamalı.
  * `pd-chip` veya `pd-badge` primitive’ine bağlanmalı.
* Preview `.card` production’da genel/global ad olduğu için doğrudan taşınmamalı.
  * `pd-card` primitive’ine bağlanmalı.
* Preview `.priority-block` production’da namespace’li hale gelmeli.
  * öneri:
    * `.pd-quote-detail__priority-block`
    * veya `.pd-product-print-block`

## 5. CSS Namespace ve Font Standardı

Net font standardı:

* Production standardı:
  * `Arial, Helvetica, sans-serif`
* Preview durumu:
  * dosya `Inter` kullanıyor
* Net karar:
  * preview görünümü korunacak
  * font production’da `Arial, Helvetica, sans-serif` standardına dönecek

Önerilen namespace standardı:

* `.pd-page`
* `.pd-card`
* `.pd-btn`
* `.pd-chip`
* `.pd-tabs`
* `.pd-table`
* `.pd-form`
* `.pd-summary`
* `.pd-sticky-bar`
* `.pd-modal`
* `.pd-product-line`
* `.pd-print-line`
* `.pd-product-hub`
* `.pd-quote-detail`
* `.pd-order-flow`

Ek karar:

* Mevcut `quote-*` sınıfları çalışan geçiş katmanı olarak kabul edilebilir.
* Ancak yeni entegrasyonlarda bunlar `pd-*` isim alanına taşınmalı veya `pd-*` ile kapsüllenmelidir.
* Global `.btn`, `.card`, `.chip` production’a açılmamalı.

## 6. Data Contract

Teklif detay ekranı için önerilen ana contract:

* `$quote`
* `$quoteSummary`
* `$quoteItems`
* `$printLines`
* `$approvalStatus`
* `$sendStatus`
* `$financialSummary`
* `$canViewFinancialData`
* `$canSendQuote`
* `$canApproveQuote`
* `$publicApprovalLink`
* `$productHubWarnings`
* `$timeline`
* `$notificationChannels`

Mevcut Blade/controller doğrulaması:

* Bugün `show()` view’ine zaten benzer payload’lar gidiyor:
  * `quote`
  * `canViewFinancialData`
  * `itemCount`
  * `printCount`
  * `isConverted`
  * `linkedOrder`
  * `canConvert`
  * `convertIssues`
  * `displayStatusLabel`
  * `latestApprovalRequest`
  * `customerResponseSummary`
  * `sendHistoryRows`
  * `summaryVatRows`
  * `approvalHelperUrl`
  * `sendNotificationSummary`
  * `notificationLogRows`
  * `revisionCompareUrl`

Önerilen sadeleştirilmiş view model:

* `$quoteSummary`
  * `document_number`
  * `customer_name`
  * `quote_date`
  * `valid_until`
  * `item_count`
  * `print_count`
  * `grand_total`
  * `currency`
* `$approvalStatus`
  * `customer_status`
  * `latest_response`
  * `latest_request_status`
  * `can_convert`
* `$sendStatus`
  * `last_channel`
  * `last_sent_at`
  * `whatsapp_ready`
  * `public_link_available`
* `$financialSummary`
  * `product_total`
  * `print_total`
  * `subtotal`
  * `vat_total`
  * `grand_total`
  * `visible`

Ürün satırı contract:

* `line_no`
* `product_name`
* `product_code`
* `quantity`
* `unit`
* `unit_price`
* `line_total`
* `stock_warning`
* `price_snapshot_status`
* `price_changed_since_snapshot`
* `warning_badges`
* `warning_messages`

Baskı satırı contract:

* `parent_line_no`
* `print_name`
* `print_method`
* `print_description`
* `print_quantity`
* `print_unit_price`
* `print_total`

## 7. Permission / Tenant / Finans Contract

Tenant ve permission kuralları:

* Teklif detayı tenant scope dışında açılamaz.
* Buton görünürlüğü permission ve akış durumuna göre hesaplanmalı.
* Finansal toplamlar `canViewFinancialData` üzerinden korunmalı.
* Public approval bağlantısı yalnız uygun durumda görünmeli.
* Siparişe çevirme yalnız uygun statü ve yetkide açılmalı.

Yetkisiz veya müşteri/public görünümde gösterilmeyecek alanlar:

* supplier cost
* alış fiyatı
* maliyet
* tedarikçi teknik raw alanları
* Product Hub internal id
* `group_code`
* `file_path`
* `physical_path`
* `token`
* `api_key`
* `smtp_password`
* `secret`

Finans kuralı:

* `financialSummary.visible = false` ise finans satırları maskelenmeli veya hiç render edilmemeli.

## 8. Product Hub Canlı Bilgi Contract

Bu fazda endpoint yazılmayacak.

Önerilen contract:

* `GET /admin/product-hub/live-product-info?tenant_catalog_product_id=...`

Önerilen response alanları:

* `product_name`
* `variant_name`
* `current_stock`
* `current_price`
* `currency`
* `last_synced_at`
* `is_sellable`
* `supplier_access_active`
* `tenant_catalog_active`
* `stock_warning`
* `price_changed_since_snapshot`
* `product_inactive_warning`
* `alternative_available`
* `public_safe_message`

Contract kuralları:

* endpoint raw supplier payload döndürmez
* tenant dışı ürün bilgisi döndürmez
* yalnız public-safe açıklama metni verir
* supplier teknik detayları ve secret alanlar response’a girmez

## 9. HTML Preview → Blade Entegrasyon Checklist

1. Referans HTML dosyası korunacak.
2. Her bölüm için Blade karşılığı belirlenecek.
3. Her bölüm için component gereksinimi yazılacak.
4. Her component için namespace kararı verilecek.
5. Data contract üretilecek.
6. Permission / tenant / finans görünürlüğü bağlanacak.
7. JS davranışı proje standardına çevrilecek.
8. Responsive davranış admin layout ile birlikte test edilecek.
9. Global `.btn`, `.card`, `.chip` türü sınıflar production’a açılmayacak.
10. Font Prodelya standardına çevrilecek.

## 10. Hassas Veri ve Güvenlik Kuralları

* Preview içinde görülen örnek alanlar production’da gerçek secret veya teknik ham alanlarla beslenmeyecek.
* Public link gösterilebilir, fakat token ham olarak ayrıca sergilenmez.
* Product Hub internal kimlikleri kullanıcı-facing satırlara taşınmaz.
* Supplier raw mapping ve sync detayları teklif detay ekranına inmez.
* Phone, email, link gibi alanlar mevcut permission ve sanitize kurallarıyla gösterilir.

## 11. Test Contract

Sonraki entegrasyon fazı için zorunlu test eksenleri:

* Blade render testi
* no-sensitive-leak testi
* tenant isolation testi
* finance permission testi
* Türkçe terminoloji testi
* product live info endpoint testi
* quote snapshot price warning testi
* CSS namespace smoke testi
* public approval leak testi

Ek öneri:

* preview section mapping regression testi
* modal open/close JS davranış testi
* mobile responsive smoke testi

## 12. Sonraki Uygulama Fazı Önerisi

Önerilen sonraki faz:

* Teklif detay ekranında kontrollü pilot entegrasyon

Bu fazın hedefleri:

* referans HTML görünümüne yakınlığı korumak
* mevcut Blade veri akışını bozmamak
* sınıfları namespace’li hale getirmek
* component’leri kademeli çıkarmak
* Product Hub canlı bilgi entegrasyonunu ayrı fazda bırakmak

Net kararlar:

* Bu HTML production’a doğrudan kopyalanmayacak.
* Görsel yapı korunacak.
* Font Prodelya standardına dönecek:
  * `Arial, Helvetica, sans-serif`
* Global `.btn`, `.card`, `.chip` gibi sınıflar kontrollü namespace’e alınacak.
* Önce teklif detay ekranında kontrollü pilot entegrasyon yapılacak.
* Product Hub canlı bilgi entegrasyonu ayrı fazda yapılacak.
