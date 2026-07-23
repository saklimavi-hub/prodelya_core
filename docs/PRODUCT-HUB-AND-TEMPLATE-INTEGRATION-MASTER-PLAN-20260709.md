# Product Hub ve Template Integration Master Plan — 2026-07-09

## 1. Yönetici Özeti

* Bu fazda kod değişikliği yapılmadı; yalnızca analiz ve plan üretildi.
* Product Data Hub bugün iki ayrı gerçeklik taşıyor:
  * Super Admin tarafında güçlü ama fazla teknik ve parçalı bir veri/senkron yönetim alanı var.
  * Tenant tarafında gerçek satış akışı aslında `ProductDataHubController` üzerinden değil, `TenantCatalogController` ve `CatalogSearchController` üzerinden ilerliyor.
* En önemli tespit:
  * “Product Hub” ile “satışta kullanılan katalog” aynı kavram gibi görünse de kodda ayrı katmanlar halinde yaşıyor.
  * Bu ayrım bugün bazı yerlerde faydalı, ama menü ve ekran kurgusunda karmaşa üretiyor.
* Önerilen yön:
  * Product Hub global veri omurgası olarak korunmalı.
  * Tenant tarafında kullanıcıya yalnızca sade “satışa uygun katalog” gösterilmeli.
  * Teklif/sipariş ekranı canlı bilgiyi doğrudan raw/global tablolardan değil tenant catalog projection/read model katmanından okumalı.
* HTML preview entegrasyonunda yeni standart:
  * Standalone HTML bundan sonra doğrudan kopyalanmayacak.
  * Bölüm haritası + Blade component listesi + data contract + permission contract + CSS contract + JS contract ile teslim edilecek.

## 2. Mevcut Product Hub Sorunları

### 2.1 Yapısal karmaşa

* Super Admin Product Hub çok geniş alan kaplıyor:
  * `app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php` yaklaşık `2151` satır
  * ayrıca ayrı controller kümeleri var:
    * `SuperAdminSupplierSourceController`
    * `SuperAdminCategoryMappingController`
    * `SuperAdminFieldMappingController`
    * `CategoryCleanupController`
    * `CategoryReviewBatchController`
    * `SuperAdminRawProductController`
    * `SuperAdminStandardProductController`
* Bu yapı işlevsel olsa da kullanıcı zihninde tek bir “ürün merkezi” yerine çok sayıda teknik alt panel oluşturuyor.

### 2.2 Tenant tarafında eski/yanıltıcı panel izi

* `app/Http/Controllers/Admin/ProductDataHubController.php` yaklaşık `425` satır ama önemli kısmı placeholder/demo ağırlıklı.
* Somut bulgular:
  * `abortTenantAccess()` birçok ekranda doğrudan erişimi kesiyor.
  * `sources()`, `fieldMappings()`, `categoryMappings()`, `productMappings()`, `tenantAccess()`, `exports()`, `logs()` gibi alanlarda placeholder ve demo veri kalıntıları var.
  * `Demo Tedarikçi`, `Demo JSON API`, placeholder açıklamaları doğrudan kodda bulunuyor.
* Sonuç:
  * Tenant için Product Hub teknik paneli gerçekte temel satış yüzeyi değil.
  * Asıl canlı ürün deneyimi başka yerde.

### 2.3 Gerçek tenant satış akışı başka controller’da yaşıyor

* Gerçek tenant katalog akışı:
  * `app/Http/Controllers/Admin/TenantCatalogController.php`
  * `app/Http/Controllers/Admin/CatalogSearchController.php`
* Bu katmanlar:
  * ürün listesi
  * ürün paneli
  * supplier/local ürün ayrımı
  * görünürlük
  * warning ekranı
  * local ürün yönetimi
  * teklif arama endpoint’i
  üzerinden çalışıyor.
* Yani bugünkü satış gerçekliği:
  * “Product Data Hub” değil
  * “Tenant Catalog + Catalog Search + Promotion Quote form workspace”
  kombinasyonu.

### 2.4 Teklif ekranında canlı veri var ama controller içinde gömülü

* `app/Http/Controllers/Admin/PromotionQuoteController.php` yaklaşık `2654` satır.
* `_form-workspace.blade.php` yaklaşık `3684` satır.
* Ürün arama, snapshot, warning badge, stock/price payload ve catalog identity mantığı bu iki dosyada yoğunlaşmış durumda.
* Bu yüzden Product Hub karmaşıklığı kullanıcıya doğrudan görünmese de geliştirme maliyeti teklif ekranına taşınmış durumda.

### 2.5 Dosya ve menü karmaşası

* Super Admin menüsünde `Product Data Hub` grubu çok geniş.
* Tenant menüsünde ayrıca `Ürün ve Katalog` var.
* Teknik olarak mantıklı, ama kullanıcıya “hangi ekran veri kaynağı, hangi ekran satış kataloğu?” sorusunu net cevaplamıyor.

## 3. Yeni Sade Product Hub Akışı

Önerilen sade akış iki katmanlı olmalı:

### 3.1 Super Admin tarafı

1. Kaynaklar
* XML / JSON / CSV / API kaynakları
* bağlantı durumu
* son okuma
* hata/uyarı özeti
* son fiyat/stok değişim özeti

2. Önizleme ve eşleme
* 5-10 ürün örnek önizleme
* alan eşleme
* kategori eşleme
* görsel/fiyat/stok kalite kontrolü
* kritik teknik hata varsa açık uyarı

3. Ürün havuzu
* standart ürün
* varyant
* kategori
* fiyat/stok
* uyarı durumu
* “satışa uygun / kontrol gerekli / bloklu” kararı

4. Güncelleme ayarları
* senkron sıklığı
* yalnız fiyat/stok uygula
* yeni ürün uyar
* pasife düşen ürün uyar
* görsel değişti uyar
* manuel kontrol gerektiren değişiklikleri ayır

5. Tenant yayını
* hangi tenant hangi tedarikçiyi görüyor
* projection sayıları
* satışa açık ürün sayısı
* teklif ekranına açık ürün sayısı

### 3.2 Tenant tarafı

1. Ürün ara
* yalnız satışa uygun ürünler
* teknik alanlar gizli
* stok/fiyat/görsel/varyant/kategori/uyarı görünür

2. Canlı bilgi kutusu
* güncel stok
* güncel fiyat
* son güncelleme
* satışa uygunluk
* tedarikçi erişim durumu
* kritik stok/fiyat uyarısı
* katalog aktiflik durumu
* muadil önerisi

3. Teklif satırına aktarım
* seçim anında snapshot
* teklif fiyatı korunur
* güncel veri sonradan uyarı olarak gösterilir

## 4. Teklif/Siparişe Canlı Bilgi Aktarım Planı

Bugünkü durum:

* Teklif araması `admin.catalog.search` endpoint’i üzerinden gidiyor.
* `CatalogSearchController` tenant catalog ürünlerini JSON olarak döndürüyor.
* Dönen payload zaten şunları içeriyor:
  * `product_snapshot`
  * `price_snapshot`
  * `stock_snapshot`
  * `warning_badges`
  * `warning_messages`
  * `visible_stock_quantity`
  * `display_price`
  * `supplier_name`
  * `category_name`
* `PromotionQuoteController::resolveCatalogItemPayload()` bu seçimleri doğrulayıp teklif satırına snapshot olarak yazıyor.

Önerilen mimari:

* Global Product Hub:
  * raw + standard veri tutar
* Tenant Catalog:
  * satışa uygun projection/read model olur
* Teklif arama:
  * yalnız tenant catalog üzerinden çalışır
* Sync sonrası:
  * sadece değişen ürünler için projection yenilenir
* Teklif formu:
  * seçim anında canlı endpoint’ten son bilgiyi alır
  * kayıtta snapshot’a dönüştürür
* Eski teklif:
  * otomatik değişmez
  * “güncel fiyat değişti / stok değişti” uyarısı gösterir

Karşılaştırma:

* Direkt Product Hub tablolarından okuma:
  * hızlı başlangıç sağlar
  * ama tenant görünürlüğü, satış kuralları ve warning filtreleri dağılır
* Tenant catalog projection/read model:
  * daha doğru katman
  * bugünkü kod zaten buna yakın
* Cache + invalidation:
  * arama yükünde faydalı
  * ama önce projection katmanı netleşmeli
* Event/job tabanlı güncelleme:
  * sync sonrası projection refresh için doğru yaklaşım
* AJAX canlı sorgu:
  * teklif ekranı için gerekli
  * ama yalnızca read model’e karşı çalışmalı

Önerilen sade yaklaşım:

* Teklif ekranı canlı bilgiyi `TenantCatalogProduct` ve `TenantCatalogProductVariant` projection katmanından okuyacak.
* Raw/standard veri teklif ekranına doğrudan açılmayacak.
* Snapshot modeli korunacak.
* “Canlı bilgi” ve “teklifte saklanan fiyat” bilerek ayrılacak.

## 5. Product Hub Veri Modeli ve Projection Yaklaşımı

Bugünkü servis zinciri:

* `SourceFetchService`
* `SourceParserService`
* `PreviewParserService`
* `RawProductStagingService`
* `StandardProductBuilderService`
* `TenantCatalogProjectionService`
* `ProductHubSellableTruthService`
* `SupplierSourceSyncService`

Bu zincir mevcutta şu veri akışını işaret ediyor:

1. Kaynak oku
2. Parse et
3. Preview çıkar
4. Raw ürünleri stage et
5. Standard ürün ve varyant üret
6. Tenant projection üret
7. Tenant katmanında satış gerçeğini hesapla

Önerilen model:

* Layer 1: Raw Source
  * SupplierSource
  * SupplierProductRaw
  * SupplierProductVariantRaw
* Layer 2: Standard Pool
  * StandardProduct
  * StandardProductVariant
  * standart kategori ve görsel ilişkileri
* Layer 3: Tenant Catalog Projection
  * TenantCatalogProduct
  * TenantCatalogProductVariant
  * görünürlük, satış uygunluğu, tenant fiyat çarpanı, safe stock
* Layer 4: Quote Snapshot
  * OrderItem product/price/stock snapshot alanları

Net kural:

* Raw veri operasyon veri katmanıdır.
* Standard veri normalizasyon katmanıdır.
* Tenant Catalog satış katmanıdır.
* Quote Snapshot belge bütünlüğü katmanıdır.

## 6. Super Admin Ekran Planı

Önerilen sade ekran seti:

### 6.1 Ürün Veri Merkezi / Kaynaklar

* kaynak listesi
* bağlantı durumu
* son okuma
* son değişiklik özeti
* hata/uyarı sayısı
* hızlı aksiyonlar

### 6.2 Kaynak Detayı

* bağlantı bilgisi
* örnek çekim
* alan eşleme
* kategori eşleme
* test preview
* güvenlik politikası özeti

### 6.3 Ürün Havuzu

* standart ürünler
* varyantlar
* durum
* tedarikçi
* son güncelleme
* satışa uygunluk

### 6.4 Değişiklikler

* fiyat değişti
* stok değişti
* yeni ürün
* pasife düştü
* manuel kontrol gerekli

### 6.5 Tenant Yayını

* tenant bazlı tedarikçi erişimi
* projection sayıları
* teklif görünürlüğü
* blokaj nedenleri

Sadeleştirme önerisi:

* Günlük operatör için 5 ana ekran yeterli.
* Kategori cleanup, feature templates, raw detail, standard detail gibi derin ekranlar “ileri araçlar” altında toplanmalı.

## 7. Tenant / Teklif Ürün Seçim Ekranı Planı

Bugünkü gerçek temel:

* `TenantCatalogController`
* `CatalogSearchController`
* `promotion-quotes/_form-workspace.blade.php`

Önerilen tenant ekran seti:

### 7.1 Ürün Seçim Paneli

* sade arama
* ürün adı
* kod / varyant
* görsel
* satış fiyatı
* görünür stok
* kategori
* warning chip’leri

### 7.2 Canlı Bilgi Kutusu

Ürün seçildiğinde gösterilecek:

* güncel fiyat
* güncel stok
* son senkron zamanı
* fiyat değişim uyarısı
* stok kritik uyarısı
* katalog aktiflik durumu
* satışa uygunluk
* supplier erişim durumu

### 7.3 Teklif/Sipariş Uyarıları

* fiyat değişti
* stok azaldı
* ürün feed’den çıktı
* kategori bekliyor
* muadil önerisi var

Önemli sınır:

* Tenant kullanıcıya supplier raw alanları, mapping detayları, teknik projection reason payload’ları, import profili detayları açılmamalı.

## 8. HTML Önizleme Entegrasyon Sorunu

Referans dosya notu:

* `prodelya_teklif_detay_urun_baski_oncelikli_onizleme(3).html` dosyası erişilebilir workspace veya kullanıcı dizinlerinde doğrudan bulunamadı.
* Bu nedenle birebir HTML dosya içeriği satır satır okunamadı.
* Ancak mevcut `resources/views/admin/promotion-quotes/show.blade.php` ve ona bağlı CSS sınıfları, bu önizlemenin önemli kısmının Blade’e taşınmış halini zaten yansıtıyor.

Ana problem başlıkları:

* Standalone HTML tek sayfalık izole ortamda çalışır.
* Blade gerçek admin layout içine oturur.
* HTML dummy veri taşır.
* Blade gerçek permission, tenant, finance ve workflow kurallarına bağlıdır.
* HTML’in spacing/class/token kararları merkezi sisteme bağlanmazsa birebir taşıma kırılgan olur.

Sonuç:

* Sorun “tasarım kötü” değil.
* Sorun “uygulanabilir entegrasyon sözleşmesi olmadan görsel dosya üretilmiş olması”.

## 9. Referans HTML Bölüm Haritası

Aşağıdaki harita mevcut Blade/CSS karşılıklarından çıkarılmıştır.

### 9.1 Sidebar

* Blade karşılığı:
  * `resources/views/layouts/prodelya-admin.blade.php`
* Component olmalı mı?
  * Hayır, layout seviyesinde kalmalı.
* CSS standardı:
  * `.pd-page`, `.pd-layout`, `.pd-sidebar`
* Veri kaynağı:
  * `$adminMenu`, tenant context, user info
* Tekrar kullanım:
  * Tüm admin ekranlarında ortak

### 9.2 Page header

* Blade karşılığı:
  * `promotion-quotes/show.blade.php` içindeki `quote-page-head`
* Component önerisi:
  * `x-pd.page-head`
* CSS:
  * `.pd-page-head`
* Veri:
  * başlık, alt açıklama, üst aksiyonlar

### 9.3 Notice bar

* Blade karşılığı:
  * `quote-alert`
* Component önerisi:
  * `x-pd.notice`
* CSS:
  * `.pd-notice`
* Veri:
  * flash message, permission uyarısı, işlem sonucu

### 9.4 Quote strip

* Blade karşılığı:
  * `quote-strip`, `quote-strip-top`, `quote-strip-chips`
* Component önerisi:
  * `x-quote.summary-strip`
* CSS:
  * `.pd-summary-strip`
* Veri:
  * belge no, müşteri, badge seti, source order context

### 9.5 Top metrics

* Blade karşılığı:
  * `quote-top-metrics`, `quote-metric`
* Component önerisi:
  * `x-pd.metric-grid`
* CSS:
  * `.pd-metric-grid`, `.pd-metric-card`
* Veri:
  * müşteri, tarih, geçerlilik, kalem sayısı, toplam

### 9.6 Main layout

* Blade karşılığı:
  * `quote-layout`, `quote-main-stack`, `quote-right-stack`
* Component önerisi:
  * sayfa düzeyi layout parçası
* CSS:
  * `.pd-detail-layout`
* Veri:
  * ana panel + sağ özet

### 9.7 Priority product/print block

* Blade karşılığı:
  * `quote-priority-block`, ürün/baskı satırları
* Component önerisi:
  * `x-quote.product-print-block`
* CSS:
  * `.pd-product-line`, `.pd-print-line`
* Veri:
  * `$quoteItems`, `$printLines`

### 9.8 Decision grid

* Blade karşılığı:
  * karar bandı, durum kartları, approval/send/history blokları
* Component önerisi:
  * `x-quote.decision-grid`
* CSS:
  * `.pd-decision-grid`
* Veri:
  * `$approvalStatus`, `$nextAction`, `$convertSummary`

### 9.9 Action band

* Blade karşılığı:
  * `quote-action-band`
* Component önerisi:
  * `x-pd.action-band`
* CSS:
  * `.pd-action-band`
* Veri:
  * açıklama, CTA butonları

### 9.10 Tabs

* Blade karşılığı:
  * `quote-tabs`, `quote-tab-button`, `quote-tab-panel`
* Component önerisi:
  * `x-pd.tabs`
* CSS:
  * `.pd-tabs`
* Veri:
  * tab labels, active state, panel contents

### 9.11 Right sticky summary

* Blade karşılığı:
  * quote sağ kolon summary blokları
* Component önerisi:
  * `x-pd.summary-panel`
* CSS:
  * `.pd-summary`, `.pd-summary-card`, `.pd-sticky-bar`
* Veri:
  * finans, approval, toplam, hızlı aksiyon

### 9.12 Bottom sticky action bar

* Blade karşılığı:
  * `quote-bottom-bar`
* Component önerisi:
  * `x-pd.bottom-actions`
* CSS:
  * `.pd-sticky-bar`
* Veri:
  * ana CTA’lar ve durum özeti

### 9.13 Modal

* Blade karşılığı:
  * send modal yapısı
* Component önerisi:
  * `x-pd.modal`
* CSS:
  * `.pd-modal`
* Veri:
  * form alanları, submit aksiyonu, açıklamalar

### 9.14 Buttons / Chips / Cards / Form fields

* Component önerileri:
  * `x-pd.button`
  * `x-pd.chip`
  * `x-pd.card`
  * `x-pd.field`
* CSS namespace:
  * `.pd-btn`
  * `.pd-chip`
  * `.pd-card`
  * `.pd-form`

### 9.15 Responsive davranış

* Gerçek karşılık:
  * `public/css/prodelya-admin.css` içindeki quote detail responsive blokları
* Eksik sözleşme:
  * preview seviyesinde “desktop-only görünüm” ile yetinilmemeli
  * admin layout ile birlikte test edilmeli

## 10. Yeni CSS / Font / UI Standardı

Net standart:

* Ana font:
  * `Arial, Helvetica, sans-serif`
* Bu standart zaten mevcut ana CSS içinde yaygın şekilde kullanılıyor.
* Standalone HTML’de farklı font kullanılsa bile Prodelya’ya taşınırken bu standarda uyarlanmalı.

CSS stratejisi:

* `public/css/prodelya-admin.css` tamamen tek parça büyümemeli.
* Üstte merkezi token alanı korunmalı ve genişletilmeli:
  * renk
  * border
  * radius
  * shadow
  * spacing
  * font size
  * button ölçüsü
* Modül bazlı açık namespace kullanılmalı.

Önerilen namespace seti:

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

Not:

* Bugün quote detail alanında `quote-*` ve `promotion-quote-detail` sınıfları var.
* Bunlar çalışıyor, fakat yeni taşımalarda `pd-*` sözleşmesine kademeli geçiş tercih edilmeli.

## 11. Blade Component Standardı

Her yeni HTML preview için aşağıdaki Blade bileşenleri hedeflenmeli:

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
* `x-quote.product-line`
* `x-quote.print-line`
* `x-quote.action-band`
* `x-quote.decision-grid`
* `x-product-hub.source-status-card`
* `x-product-hub.change-badge`

Standart:

* Her component tek başına namespace korumalı olmalı.
* Her component dummy veriyle değil gerçek data contract ile beslenmeli.
* Her component permission-aware olmalı.

## 12. HTML Preview → Blade Entegrasyon Checklist

Her preview teslimatında zorunlu maddeler:

1. Görsel HTML
* standalone preview dosyası

2. Bölüm haritası
* her blok adı
* Blade karşılığı

3. Component listesi
* card
* button
* badge/chip
* tabs
* summary
* product row
* print row
* modal

4. Data contract
* örnek değişkenler:
  * `$quoteSummary`
  * `$quoteItems`
  * `$printLines`
  * `$approvalStatus`
  * `$financialSummary`
  * `$canViewFinancialData`
  * `$productHubWarnings`

5. Permission contract
* kim fiyat görür
* kim maliyet görmez
* kim onay butonu görür
* kim teknik Product Hub warning görür

6. CSS contract
* kullanılacak class isimleri
* token gereksinimleri
* global çakışma kontrolü

7. JS contract
* tab
* modal
* AJAX live lookup
* search dropdown
* warning refresh

8. Test contract
* render testi
* no-sensitive-leak testi
* tenant isolation testi
* permission testi
* Türkçe terminoloji testi

## 13. Permission / Tenant / Finans Görünürlük Kuralları

Tenant tarafına kesinlikle gitmemesi gerekenler:

* raw supplier payload
* source parser detayları
* field mapping / category mapping teknik alanları
* supplier özel teknik notları
* `group_code`
* `file_path`
* `physical_path`
* token / api key / auth header benzeri alanlar
* satın alma maliyeti ve supplier cost türevleri

Tenant teklif ekranına gitmesi gerekenler:

* ürün adı
* varyant bilgisi
* kategori
* görsel
* satışta kullanılacak fiyat
* görünür stok
* uyarı badge’leri
* son güncelleme / stale bilgisi

Finans görünürlüğü:

* müşteri-facing ve yetkisiz kullanıcı-facing ekranlarda maliyet görünmez
* teklif snapshot toplamları korunur
* canlı Product Hub verisi “uyarı” üretir, geriye dönük belge değerini sessizce değiştirmez

## 14. Güvenlik ve Hassas Veri Standardı

Standard:

* Product Hub kaynak tanımlarındaki gizli bilgiler hiçbir preview veya kullanıcı-facing Blade’e taşınmayacak.
* Admin kaynak düzenleme ekranlarında maskeleme devam etmeli.
* Catalog search ve quote payload yalnız satış için gerekli alanları taşımalı.
* HTML preview sözleşmesinde “dummy veri” bile gerçek secret formatını taklit etmemeli.

Özel not:

* `config/prodelya_product_data_hub.php` içinde fetch güvenliği için timeout, redirect, private network bloklama ve query masking kuralları var.
* Bu yaklaşım korunmalı.

## 15. Test Planı

Korunması gereken test eksenleri:

* Product Hub source preview parsing
* delta sync ve price/stock apply
* tenant projection
* quote visibility
* freshness/stale bilgisi
* catalog search sonucu
* no-sensitive-leak
* tenant isolation
* permission
* Türkçe terminoloji
* quote render
* responsive görünüm

Yeni planlanan test türleri:

* canlı bilgi kutusu render testi
* teklif snapshot vs güncel fiyat uyarısı testi
* supplier access kapalı ürünlerin aramada görünmemesi
* projection sonrası sadece değişen ürünlerin güncellenmesi
* HTML preview section map doğrulama checklist testi

## 16. Uygulama Faz Planı

### Faz PH-0

* mevcut veri modeli ve akışın dondurulması
* Product Hub / Tenant Catalog / Quote Snapshot sınırlarının yazılı hale getirilmesi

### Faz PH-1

* Super Admin bilgi mimarisi sadeleştirme
* menü ve ekran isimleri düzeni

### Faz PH-2

* Tenant catalog projection/read model netleştirme
* projection karar kodlarının sadeleştirilmesi

### Faz PH-3

* teklif ürün arama canlı endpoint standardı
* response contract stabilizasyonu

### Faz PH-4

* ürün seçimi sade UI entegrasyonu
* canlı bilgi kutusu

### Faz PH-5

* sync sonrası değişikliklerin teklif ekranına uyarı olarak yansıması

### Faz UI-0

* design token ve font standardı

### Faz UI-1

* HTML preview bölüm haritası ve component contract standardı

### Faz UI-2

* teklif detay HTML örneğini gerçek Blade’e kontrollü taşıma

### Faz UI-3

* global CSS riskini azaltma
* namespace standardı

### Faz UI-4

* görsel regression checklist ve test

## 17. Riskler

Yüksek risk:

* `PromotionQuoteController` ve `_form-workspace.blade.php` içine gömülü katalog davranışı
* global CSS büyüklüğü
* Product Hub ile Tenant Catalog kavramlarının menüde ve akışta karışması

Orta risk:

* tenant ProductDataHub placeholder ekranlarının yanlış ürün algısı yaratması
* Super Admin tarafında çok sayıda derin ekranın operatör yükü oluşturması
* preview → Blade taşımalarında component sözleşmesi olmadan tekrar çalışma riski

Düşük risk:

* menüde İngilizce teknik modül adının kalması
* mevcut font standardının preview dosyalarıyla birebir uyuşmaması

## 18. Net Kararlar

* Product Hub yeniden sadeleştirilecek mi?
  * Evet. Sadeleştirilecek.
* Kullanıcı-facing menüde Product Data Hub adı kalacak mı?
  * Teknik modül adı korunabilir.
  * Kullanıcı-facing başlıkta Türkçe karşılık değerlendirilmeli.
  * En güçlü aday: `Ürün Veri Merkezi`
* Teklif ekranı canlı bilgiyi nereden okuyacak?
  * `Tenant Catalog projection/read model` katmanından.
* Teklif fiyatı snapshot olarak mı kalacak?
  * Evet. Belge bütünlüğü için snapshot kalacak.
* HTML önizlemeler doğrudan kopyalanacak mı?
  * Hayır. Component contract ile uygulanacak.
* Ana font standardı ne olacak?
  * `Arial, Helvetica, sans-serif`
* Global CSS nasıl kontrol altına alınacak?
  * token + namespace + modül bazlı sorumluluk ayrımı ile
* Tenant tarafına teknik Product Hub ekranı açık kalacak mı?
  * Hayır. Tenant satış akışı `Katalog` ve `Teklif ürün seçimi` üzerinden sadeleşmeli.

## 19. Sonraki En Mantıklı Uygulama Fazı

En mantıklı bir sonraki uygulama fazı:

* `PH-1 + UI-1 birleşik başlangıç fazı`

Bu fazda yapılması gereken ilk işler:

* Product Hub / Katalog / Teklif ürün seçimi sınırlarının kod öncesi isimlendirme ve ekran mimarisiyle netleştirilmesi
* `Ürün Veri Merkezi` menü dili kararının verilmesi
* Catalog search response contract’ının sabitlenmesi
* HTML preview teslim şablonunun standart hale getirilmesi

Bu yapıldıktan sonra:

* `PH-3` canlı endpoint stabilizasyonu
* `UI-2` quote detail controlled integration
* `UI-3` CSS namespace cleanup
en sağlıklı sıradır.
