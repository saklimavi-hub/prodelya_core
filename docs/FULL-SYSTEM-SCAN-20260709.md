# Prodelya Full System Scan — 2026-07-09

## 1. Yönetici Özeti

* Geri alma yapılmayacak. Mevcut yerel değişiklikler korunmalı.
* Çalışma ağacında ciddi kapsamda ilerleme var: 38 modified, 120 untracked dosya.
* En önemli kazanımlar: sipariş/teklif liste ayrımı, sipariş detay akış merkezi, revizyon altyapısı, tekrar sipariş kopyalama, public teklif onayı, bildirim ve WhatsApp hazırlıkları, kapsamlı test koruması.
* En önemli riskler: `PromotionQuoteController` dosyasının aşırı büyümesi, `public/css/prodelya-admin.css` içindeki global etki alanı, `OrderController` içindeki placeholder/demo kalıntıları, full test paketinin timeout olması.
* Önerilen sonraki faz: rollback düşünmeden, önce cleanup ve hardening odaklı bir “FULL-SCAN-A sonrası toparlama” fazı.

## 2. Git ve Working Tree Durumu

* Branch: `feature/master-restructure-phase-2-order-flow`
* HEAD: `8eafa19`
  `phase 1: restructure tenant admin menu and layout`
* Checkpoint durumu: HEAD zaten `checkpoint-master-restructure-phase-1-menu-layout-20260707-1818` etiketi üzerinde.
* Working tree özeti:
  * 38 modified dosya
  * 120 untracked dosya
  * Staged değişiklik yok
* `git diff --stat`: `38 files changed, 4234 insertions(+), 1157 deletions(-)`

Değişiklik kümeleri:

* Sipariş detay / sipariş akışı merkezi:
  * `app/Http/Controllers/Admin/OrderController.php`
  * `resources/views/admin/orders/index.blade.php`
  * `resources/views/admin/orders/show.blade.php`
  * ilgili UX testleri
* Teklif detay / teklif listesi:
  * `app/Http/Controllers/Admin/PromotionQuoteController.php`
  * `resources/views/admin/promotion-quotes/index.blade.php`
  * `resources/views/admin/promotion-quotes/show.blade.php`
  * `resources/views/admin/promotion-quotes/edit.blade.php`
* Sipariş revizyon sistemi:
  * `app/Models/OrderRevision.php`
  * `app/Models/OrderRevisionChange.php`
  * `app/Services/OrderRevision*.php`
  * `resources/views/admin/promotion-quotes/revision-compare.blade.php`
  * `database/migrations/2026_07_08_150000_create_order_revisions_tables.php`
* Revize 1 / tekrar sipariş / kopya sipariş sistemi:
  * `app/Services/OrderQuoteDraftCloneService.php`
  * `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
  * `app/Models/Order.php`
* Public teklif onayı:
  * `app/Http/Controllers/PublicQuoteApprovalController.php`
  * `app/Services/QuoteApprovalService.php`
  * `app/Mail/QuoteCustomerApprovalMail.php`
  * `resources/views/public/quotes/approval/show.blade.php`
  * `resources/views/emails/quote-customer-approval.blade.php`
* Grafik public onay:
  * `resources/views/public/graphics/approval/show.blade.php`
  * route testleri
* E-posta / bildirim / WhatsApp:
  * `app/Services/Notifications/*`
  * `app/Services/PhoneNumberNormalizer.php`
* Cari / finans etkisi:
  * sipariş gösterim, revizyon karar matrisi, current account tabloları, ödeme/tahsilat ilişkileri
* Product Data Hub etkisi:
  * tenant teklif ürün seçimi, warning badge ve görünürlük mantığı
* Menü / layout / CSS:
  * `config/admin_menu.php`
  * `resources/views/layouts/prodelya-admin.blade.php`
  * `public/css/prodelya-admin.css`
* Migration / model / service:
  * additive schema ve yeni relation/helper alanları
* Test dosyaları:
  * yoğun şekilde yeni feature testleri
* Dokümantasyon:
  * `docs/10.15.18-C-revizyonu-uygula-teknik-karar-plani.md`
  * `docs/SAFE-ROLLBACK-AUDIT-20260709.md`

## 3. Korunması Gereken Önemli Değişiklikler

### 3.1 Sipariş ve teklif liste ayrımı

* İlgili dosyalar:
  * `app/Http/Controllers/Admin/OrderController.php:127`
  * `app/Models/Order.php`
  * ilgili liste testleri
* Ne işe yarıyor?
  * Teklif ve sipariş akışını birbirinden ayırıyor.
  * Siparişe dönüşmüş teklifleri aktif teklif listesinden dışlıyor.
  * Tamamlanmış siparişleri aktif sipariş listesinden ayırıyor.
  * En yeni kayıtların üstte görünmesini koruyor.
* Tamamlanma seviyesi: yüksek
* Risk seviyesi: orta
* Test var mı?
  * Evet. `ActiveQuotesHideConvertedOrdersTest`, `ConvertedQuotesListTest`, `ActiveOrdersHideCompletedOrdersTest`, `CompletedOrdersListTest`, `QuoteAndOrderSortingNewestFirstTest`, sayaç testleri.
* Tekrar yapılmaması için not:
  * Liste ayrımı ve sayaç mantığı yeniden tasarlanmamalı; mevcut davranışlar testle korunmuş.

### 3.2 Sipariş detay akış merkezi

* İlgili dosyalar:
  * `app/Http/Controllers/Admin/OrderController.php:299`
  * `resources/views/admin/orders/show.blade.php`
* Ne işe yarıyor?
  * Sipariş detayını sekmeli, akış odaklı ve operasyon/finans görünürlüğü kontrollü hale getiriyor.
* Tamamlanma seviyesi: orta-yüksek
* Risk seviyesi: orta
* Test var mı?
  * Evet. `OrderDetailOperationalFlowUxTest`, `OrderShowTabbedLayoutTest`.
* Tekrar yapılmaması için not:
  * Operasyon kartları, sekmeler ve akış özeti yeniden sıfırdan kurulmasın.

### 3.3 Revizyon sistemi

* İlgili dosyalar:
  * `app/Services/OrderRevisionApplyService.php:16`
  * `app/Services/OrderRevisionRecordService.php:16`
  * `app/Services/OrderRevisionComparisonService.php`
  * `resources/views/admin/promotion-quotes/revision-compare.blade.php`
* Ne işe yarıyor?
  * Revizyon taslağı üretimi, karşılaştırma, karar matrisi, kontrollü uygulama ve idempotency koruması sağlıyor.
* Tamamlanma seviyesi: yüksek ama tam final değil
* Risk seviyesi: orta
* Test var mı?
  * Evet. Çok geniş revizyon test seti mevcut.
* Tekrar yapılmaması için not:
  * Bu altyapı en büyük kazanımlardan biri; geri alınmamalı ve yeniden keşfedilmemeli.

### 3.4 Tekrar sipariş / kopya sipariş

* İlgili dosyalar:
  * `app/Services/OrderQuoteDraftCloneService.php:38`
  * `app/Models/Order.php:14`
  * `database/migrations/2026_07_08_120000_add_order_copy_metadata_to_orders_table.php`
* Ne işe yarıyor?
  * Kaynak siparişten güvenli teklif taslağı üretiyor, revizyon ve tekrar sipariş akışını metadata ile ayırıyor.
* Tamamlanma seviyesi: yüksek
* Risk seviyesi: düşük-orta
* Test var mı?
  * Evet. repeat/revision draft, tenant, finance, no-sensitive-leak testleri mevcut.
* Tekrar yapılmaması için not:
  * Kopyalama blacklist mantığı ve metadata şeması korunmalı.

### 3.5 Public teklif onayı

* İlgili dosyalar:
  * `app/Http/Controllers/PublicQuoteApprovalController.php`
  * `app/Services/QuoteApprovalService.php`
  * `app/Mail/QuoteCustomerApprovalMail.php`
* Ne işe yarıyor?
  * Müşteriye token bazlı teklif görüntüleme, onay, revizyon talebi ve ret akışı sağlıyor.
* Tamamlanma seviyesi: canlıya yakın
* Risk seviyesi: orta
* Test var mı?
  * Evet. public quote approval ve mail/UX regression testleri mevcut.
* Tekrar yapılmaması için not:
  * Snapshot tabanlı public çıktı ve sanitization mantığı korunmalı.

### 3.6 Bildirim ve WhatsApp hazırlıkları

* İlgili dosyalar:
  * `app/Services/Notifications/TenantWhatsappLinkService.php:166`
  * `app/Services/PhoneNumberNormalizer.php`
  * `app/Services/Notifications/TenantSmtpMailerService.php`
* Ne işe yarıyor?
  * Telefon normalize ediyor, public link üretimini güvenli hale getiriyor, hassas ifadeleri mesajdan temizliyor.
* Tamamlanma seviyesi: orta-yüksek
* Risk seviyesi: düşük-orta
* Test var mı?
  * Evet. telefon ve WhatsApp ilgili testler mevcut.
* Tekrar yapılmaması için not:
  * Normalizasyon ve sanitize kuralları yeniden bozulmamalı.

## 4. Sipariş / Teklif Akışı Durumu

* Teklif listesi aktif, dönüştürülmüş ve arşivlenmiş teklif ayrımıyla çalışıyor.
* Sipariş listesi açık/aktif siparişler ile tamamlanmış siparişleri ayrı ele alıyor.
* Siparişe dönüşmüş teklifler aktif teklif listesinde saklanmıyor. Bu davranış `Order` model scope’ları ve liste testleriyle korunuyor.
* Tamamlanmış siparişler normal aktif sipariş listesinden ayrılıyor. Ayrı görünüm ve sayaç testleri var.
* Sıralama yeni kayıt üstte olacak şekilde kurgulanmış.
* Sipariş detay ekranında yeni UX kazanımları:
  * sekmeli yapı
  * operasyon akış kartları
  * finans görünürlüğü kontrollü veri
  * revizyon taslağı oluşturma
  * tekrar sipariş taslağı oluşturma
* Korunması gerekenler:
  * teklif/sipariş ayrımı
  * sayaç mantığı
  * sekmeli sipariş detay deneyimi
  * aynı ekrandan revizyon ve tekrar sipariş başlatma
* Karışık veya riskli alanlar:
  * `OrderController` içinde hâlâ devre dışı bırakılmış akışlar var:
    * `create/store/edit/update/destroy/updateStatus`
  * placeholder örnekler:
    * `app/Http/Controllers/Admin/OrderController.php:49` TODO middleware
    * `app/Http/Controllers/Admin/OrderController.php:855` demo statistics
    * `app/Http/Controllers/Admin/OrderController.php:293`
    * `app/Http/Controllers/Admin/OrderController.php:592`
    * `app/Http/Controllers/Admin/OrderController.php:611`
    * `app/Http/Controllers/Admin/OrderController.php:847`
* Sonuç:
  * çekirdek akış mantığı faydalı ve korunmalı
  * placeholder kalan sipariş CRUD/durum değiştirme alanları commit öncesi ayrıca toparlanmalı

## 5. Revizyon Sistemi Durumu

* Seviye değerlendirmesi:
  * Basit hazırlık aşamasını geçmiş durumda.
  * Uçtan uca altyapı büyük ölçüde kurulmuş.
* Revizyon taslağı oluşturma:
  * Var. `OrderController` üzerinden revizyon taslağı başlatılıyor.
  * Kopyalama servisi kaynak siparişten revision draft üretiyor.
* Revizyon karşılaştırma ekranı:
  * Var. `PromotionQuoteController.php:1919`
  * Blade ekranı ayrıca mevcut: `resources/views/admin/promotion-quotes/revision-compare.blade.php`
* Revizyon uygulama mantığı:
  * Var. `app/Services/OrderRevisionApplyService.php:26`
  * Sadece güvenli ticari alanları ve seçili teslimat bilgisini uyguluyor.
* Finans/cari etkisi:
  * Kontrollü.
  * Cari hareket ve ödeme kayıtlarını doğrudan mutate etmiyor.
  * Finans kapısı karar matrisine dahil.
* Operasyon kayıtları korunuyor mu?
  * Evet. Procurement/production/delivery alanları için lock/manual review yaklaşımı var.
* Duplicate apply / idempotency:
  * Var. `ALREADY_APPLIED_MESSAGE` ve durum kontrolü ile korunuyor.
* Tenant scope:
  * Var. revision/order/revisionQuote tenant eşleşmesi kontrol ediliyor.
* Tamamlanmış kısımlar:
  * taslak üretimi
  * karşılaştırma ekranı
  * karar matrisi
  * no-sensitive sanitization
  * permission ve tenant korumaları
  * idempotent apply koruması
* Eksik / henüz tam final olmayan kısımlar:
  * tüm yaşam döngüsünün daha görünür yönetimi
  * audit trail TODO kalıntıları
  * controller/service parçalanması ihtiyacı
* Genel karar:
  * Revizyon sistemi rollback ile kaybedilmemesi gereken en önemli geliştirmelerden biri.

## 6. Tekrar Sipariş / Kopya Sipariş Durumu

* Tekrar sipariş sistemi var.
* Revizyon ile tekrar sipariş ayrımı doğru modellenmiş:
  * `copy_type = revision`
  * `copy_type = repeat_order`
* `orders` üzerinde takip edilen metadata:
  * `source_order_id`
  * `copy_type`
  * `revision_number`
  * `copied_by_user_id`
  * `copied_at`
* Servis davranışı:
  * Revizyon draft için artan `revision_number`
  * Tekrar siparişte revizyon numarası yok
  * finans/cari/payments geçmişi taşınmıyor
  * operasyonel üretim notu ve operasyon statüleri sıfırlanıyor
* Tenant riski:
  * Düşük. kaynak ve hedef bağları tenant içinde korunuyor.
* Finans riski:
  * Düşük. blacklist ile current account/payment türevi alanlar taşınmıyor.
* Operasyon riski:
  * Düşük-orta. operasyon geçmişi kopyalanmıyor; bu doğru koruma.
* Korunması gereken kodlar:
  * `OrderQuoteDraftCloneService` içindeki snapshot blacklist
  * `Order` modelindeki copy metadata helper’ları
  * tekrar sipariş / revizyon görünürlük testleri

## 7. Public Onay ve Bildirim Durumu

* Public teklif onay ekranı güçlü bir seviyeye gelmiş.
* Route yapısı mevcut:
  * `routes/web.php:104`
  * token bazlı show/approve/revision/reject akışı
* Müşteriye giden mail şablonu var:
  * `app/Mail/QuoteCustomerApprovalMail.php`
  * `resources/views/emails/quote-customer-approval.blade.php`
* `QuoteApprovalService` snapshot tabanlı çalışıyor:
  * public çıktı live iç model yerine gönderim snapshot’ından kuruluyor
  * önceki açık approval request kayıtları kapatılıyor
* WhatsApp link ve telefon normalizasyon etkisi:
  * mevcut
  * sabit hat ve mobil normalize ediliyor
  * güvenli public link sanitization var
* Public ekranda hassas veri sızıntısı:
  * inceleme sonucunda doğrudan sızıntı bulgusu yok
  * controller içinde sanitize katmanı var
  * `show_price_details` mantığı kontrollü
  * KDV/toplam gösterimi müşteri-facing ticari görünüm dahilinde
  * maliyet, supplier cost, group_code, path, token gibi alanlar sanitize ediliyor
* Canlıya yakın mı?
  * Evet, canlıya yakın.
  * Yine de SMTP/notification konfigürasyonu ve uç kanal güvenilirliği ayrıca doğrulanmalı.

## 8. Grafik Onay Durumu

* Grafik public onay route’u mevcut:
  * `routes/web.php:110`
* Public grafik onay ekranı kullanıcı-facing sade bir akış veriyor.
* İncelenen değişiklikler, fiyat veya teknik tedarik detaylarını public tarafa taşımıyor.
* Güvenlik değerlendirmesi:
  * fiyat/maliyet/cari/PDH teknik veri sızıntısı açısından belirgin bir bulgu yok
* Bu alanın mevcut değişim hacmi, teklif public onayı kadar büyük değil.
* Sonuç:
  * Grafik public onay tarafı korunmalı, fakat quote public approval kadar geniş test/hardening tekrar gözden geçirilebilir.

## 9. Cari / Finans Etki Analizi

* `current_accounts` ve `current_account_transactions` tabloları mevcut.
* Sipariş detay ekranında finans görünürlüğü yetkiye bağlı.
* Sipariş tamamlanınca finans takibinin sürmesi için test koruması var:
  * `CompletedOrderStillKeepsFinanceTrackingTest.php`
* Revizyon uygulama finans/cari hareketlerini doğrudan değiştirmiyor.
* Revizyon taslağı ve tekrar sipariş kopyası finans geçmişini taşımıyor.
* Yetkisiz kullanıcılara ödeme/bakiye/fiyat sızıntısı:
  * doğrudan kritik sızıntı bulgusu görülmedi
  * `canViewFinancialData` tarzı görünürlük kurgusu korunmuş görünüyor
* Risk seviyesi:
  * düşük-orta
* Not:
  * finansla temas eden controller/view dosyaları büyük olduğu için regresyon riski hâlâ var; ama mevcut yön doğru.

## 10. Product Data Hub Etki Analizi

* Son yerel değişiklikler PDH çekirdeğini doğrudan baştan yazmıyor.
* Esas etki tenant teklif ürün seçimi ve görünürlük katmanında.
* Ürün seçim ekranında:
  * projection/standard/tenant catalog ilişkileri kullanılıyor
  * warning badge / warning message mantığı bulunuyor
* Tenant tarafa gereksiz supplier teknik alanı sızıntısı:
  * doğrudan kritik bulgu görülmedi
  * birçok yeni test “no technical leak” yaklaşımını destekliyor
* PDH tarafında korunması gerekenler:
  * tenant ürün seçim sadeleştirmeleri
  * teknik alanları saklama yaklaşımı
  * teklif detay ekranındaki gereksiz bilgi azaltımı
* Risk seviyesi:
  * orta
* Not:
  * `PromotionQuoteController` içindeki PDH ile ilgili kod yoğunluğu bakım riskini artırıyor.

## 11. Menü / Layout / CSS Etki Analizi

* Phase 1 menü/layout değişiklikleri korunuyor.
* Türkçe odaklı yönelim güçlü:
  * `Gösterge Paneli`
  * `Satış ve Sipariş`
  * `Cari Kartlar`
  * `Cari Hareketler`
  * `Abone Firmalar`
* Hâlâ standarda tam uymayan kullanıcı-facing terimler var:
  * `config/admin_menu.php:372` `Tenant Hizmetleri`
  * `config/admin_menu.php:405`
  * `config/admin_menu.php:411` `Product Data Hub`
* Layout tarafında görünür metin büyük ölçüde Türkçe standarda yakın.
* CSS etkisi:
  * `public/css/prodelya-admin.css` çok büyük ve çok ekrana temas ediyor
  * teklif workspace, sipariş görünümü, revizyon compare, tenant ve dashboard bölümlerini aynı dosyada topluyor
* Fazla global CSS riski:
  * evet, orta-yüksek
  * küçük bir değişiklik çok sayıda ekranı etkileyebilir
* Sonuç:
  * Menü/layout kazanımları korunmalı
  * commit öncesi terminoloji cleanup ve CSS alan daraltma iyi bir sonraki adım olur

## 12. Migration ve DB Uyumu

* `php artisan migrate:status` sonucuna göre pending migration yok.
* Mevcut DB’de doğrulanan tablolar:
  * `orders`
  * `order_revisions`
  * `order_revision_changes`
  * `current_accounts`
  * `current_account_transactions`
  * `product_data_hub_sync_runs`
  * `product_data_hub_sync_changes`
  * `tenant_accounts`
  * `packages`
  * `tenant_modules`
* `orders` içinde doğrulanan yeni alanlar:
  * `source_order_id`
  * `copy_type`
  * `revision_number`
  * `copied_by_user_id`
  * `copied_at`
* Additive schema mevcut.
* Kodun beklediği ana revizyon/copy metadata alanları DB’de var.
* DB’de olup kodda henüz tam kullanılmayan alanlar olabilir, ancak gözlenen ana risk bir eksik kolon/tablo değil.
* Kod/DB uyumsuzluğu riski:
  * düşük
* Sonuç:
  * rollback ya da DB restore gerektiren bir schema kırılması görünmüyor.

## 13. Test Kapsamı

* Güçlü şekilde testle korunan yeni davranışlar:
  * teklif/sipariş liste ayrımı
  * tamamlanmış siparişlerin ayrılması
  * en yeni üstte sıralama
  * revizyon compare/apply mantığı
  * repeat order ve revision draft üretimi
  * tenant isolation
  * no-sensitive-leak
  * public quote approval ekranı ve aksiyonları
  * Türkçe terminoloji beklentileri
  * WhatsApp ve telefon normalizasyonu
* Özellikle korunması gereken test kümeleri:
  * tüm `OrderRevision*`
  * `RepeatOrder*`
  * `RevisionRepeatOrder*`
  * `PublicQuoteApproval*`
  * `QuoteOrderList*`
  * `PromotionQuoteDetail*`
  * `AdminMenuVisibilityTest`
  * `CurrentAccount` odaklı testler
* Testi zayıf veya yeniden ele alınması faydalı alanlar:
  * controller büyüklüğü nedeniyle `PromotionQuoteController` üzerinde senaryo bazlı entegrasyon testleri daha da artırılabilir
  * global CSS görsel regresyon alanı test dışı kalabilir
  * grafik public approval tarafı quote public approval kadar derin korunmuyor olabilir

## 14. Güvenlik ve Veri Sızıntısı Kontrolü

Kritik risk:

* İnceleme sırasında doğrudan kritik veri sızıntısı veya tenant scope kırılması gösteren net bulguya rastlanmadı.

Orta risk:

* `PromotionQuoteController` içinde çok fazla sorumluluk toplanmış olması
* Global CSS ve büyük Blade dosyalarının dolaylı davranış/regresyon riski
* `OrderController` içindeki placeholder/devre dışı akışların yanlış anlaşılması

Düşük risk:

* Menüde terminoloji tutarsızlıkları
* Demo/placeholder kalıntıları

Olumlu güvenlik bulguları:

* tenant scope kontrolleri yaygın
* permission kontrolleri mevcut
* public quote approval snapshot bazlı
* WhatsApp mesaj sanitization var
* revizyon kayıt ve apply katmanlarında forbidden key temizliği var

Özel arama sonuçları:

* `dd(` / `var_dump(` kaynakta kritik debug kalıntısı olarak tespit edilmedi
* görülen `dump()` eşleşmesi `resources/views/admin/promotion-quotes/_form-workspace.blade.php` içindeki JavaScript method adı; PHP debug dump değil
* geçici test route’u niteliğinde belirgin bir public route bulgusu yok
* demo/placeholder kalıntıları var:
  * `app/Http/Controllers/Admin/OrderController.php:855`
  * aynı dosyada aktif değil mesajları
* ayrıca cleanup adayı TODO’lar:
  * `app/Http/Controllers/Admin/OrderController.php:49`
  * `app/Http/Controllers/Admin/PromotionQuoteController.php:1779`
  * `app/Http/Controllers/Admin/PromotionQuoteController.php:2546`
  * `app/Http/Controllers/Admin/PromotionQuoteController.php:2713`
  * `app/Http/Controllers/Admin/PromotionQuoteController.php:2754`

## 15. Türkçe UI / Terminoloji Kontrolü

* Genel yön doğru; kullanıcı-facing alanların büyük bölümü Türkçeleşmiş.
* Düzeltilmesi gereken görünen örnekler:
  * `config/admin_menu.php:372` `Tenant Hizmetleri`
  * `config/admin_menu.php:405` `Product Data Hub`
  * `config/admin_menu.php:411` `Product Data Hub`
* Placeholder/mesajlarda kullanıcı-facing ama geçici kalan ifadeler:
  * `aktif değildir`
  * `henüz aktif değildir`
  * `Demo statistics`
* Teknik/modül adı olarak korunabilecek kelimeler:
  * `Product Data Hub` teknik modül adı olarak korunabilir, ancak menüde Türkçeleştirilmiş karşılık değerlendirilmelidir
* Sonuç:
  * `Dashboard` yerine `Gösterge Paneli` yaklaşımı doğru
  * `Tenant` yerine `Abone Firma` standardı büyük ölçüde korunuyor, ama tamamlanmış değil

## 16. Eksik veya Riskli Alanlar

* `OrderController` içinde devre dışı bırakılmış sipariş CRUD/durum değiştirme akışları
* `OrderController::getOrderStats()` demo placeholder içermesi
* `PromotionQuoteController` dosyasının aşırı büyümesi ve sorumluluk yoğunluğu
* Global CSS dosyasının geniş etki alanı
* Full test paketinin timeout olması nedeniyle tüm proje için temiz koşu doğrulanamamış olması
* Audit trail TODO kalıntıları

## 17. Tekrar Yapılmaması Gereken İşler

Bu bölüm kritik.

* Sipariş ve teklif listelerini yeniden ayırma işi zaten yapılmış durumda.
* Sipariş detay ekranını yeniden sekmeli akış merkezi haline getirme işi büyük ölçüde yapılmış durumda.
* Revizyon draft, karşılaştırma ve kontrollü apply altyapısını yeniden tasarlama işi yapılmış durumda.
* Tekrar sipariş ve kopya metadata altyapısını yeniden kurma işi yapılmış durumda.
* Public teklif onay ekranı, token route yapısı ve mail template omurgası zaten kurulmuş durumda.
* Hassas alanları public/WhatsApp çıktılarından temizleme işi zaten yapılmış durumda.
* Telefon normalizasyon ve WhatsApp link hazırlığı işi zaten yapılmış durumda.
* Bu alanlarda rollback veya yeniden başlatma yüksek maliyetli tekrar iş üretir; mevcut kazanımlar korunarak ilerlenmeli.

## 18. Önerilen Sonraki Faz

Önerilen net faz:

* `FULL-SCAN-A sonrası cleanup ve hardening`

Bu fazın odakları:

* `OrderController` placeholder/devre dışı akış cleanup
* `PromotionQuoteController` parçalama planı
* Menü terminoloji standardizasyonu
* Global CSS etki alanını daraltma
* Public approval son hardening
* Full test koşusunu timeout yaşamadan tamamlayacak test stratejisi

Bu fazdan sonra mantıklı devam:

* revizyon geçmişi görünümü
* sipariş/teklif akışı küçük UX temizlikleri
* ürün seçim sadeleştirmesi

## 19. Çalıştırılan Komutlar ve Test Sonuçları

Çalıştırılan ana komutlar:

* `git status --short`
* `git branch --show-current`
* `git log --oneline --decorate -n 20`
* `git diff --stat`
* `git diff --name-status`
* `git ls-files --others --exclude-standard`
* `php artisan migrate:status`
* DB tablo/kolon varlık doğrulamaları
* dar kapsamlı `rg` taramaları

Test sonuçları:

* Odaklı test komutu:
  * `php artisan test --filter="OrderRevision|PromotionQuote|PublicQuoteApproval|AdminMenuVisibility|CurrentAccount|ProductDataHub"`
* Sonuç:
  * geçti
  * `525` test
  * `4050` assertion
  * yaklaşık `65.8s`
* Full test komutu:
  * `php artisan test`
* Sonuç:
  * environment/runtime timeout
  * yaklaşık `124s` sonra kesildi
  * bu fazda düzeltme yapılmadı, sadece raporlandı

## 20. Sonuç

* Net karar: geri alma yok.
* Korunması gereken ana değişiklikler:
  * teklif/sipariş liste ayrımı
  * sipariş detay akış merkezi
  * revizyon sistemi
  * tekrar sipariş / kopya metadata sistemi
  * public teklif onayı ve bildirim hazırlıkları
  * no-sensitive-leak ve tenant isolation korumaları
  * yeni test koruması
* Commit öncesi toparlanması gereken alanlar:
  * `OrderController` placeholder/devre dışı kalan akışlar
  * `PromotionQuoteController` cleanup / parçalama planı
  * menü terminoloji standardizasyonu
  * global CSS riskinin azaltılması
  * full test timeout nedeninin operasyonel olarak ele alınması
* Dokunulmaması gereken alanlar:
  * revizyon apply güvenlik mantığı
  * repeat/revision copy metadata yapısı
  * public approval sanitization yaklaşımı
  * tenant ve finans görünürlük korumaları

Bu raporun sonucuna göre sistem silinmemeli, geri alınmamalı ve mevcut kazanımlar korunarak kontrollü cleanup fazına girilmelidir.
