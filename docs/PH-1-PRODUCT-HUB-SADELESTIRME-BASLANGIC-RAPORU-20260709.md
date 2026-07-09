# PH-1 Product Hub Sadeleştirme Başlangıcı Raporu — 2026-07-09

## 1. Özet

* PH-1 kapsamında mevcut Product Hub / Product Data Hub / Tenant Catalog / teklif ürün arama hattı tarandı ve sadeleştirme yönü yazılı hale getirildi.
* Kod değişti: evet, ancak yalnız düşük riskli kullanıcı-facing menü etiketi sadeleştirmesi ve hafif UI namespace hook'u eklendi.
* Menü/label değişti: evet. Super Admin menüsündeki `Product Data Hub` grup ve accordion etiketi `Ürün Veri Merkezi` olarak sadeleştirildi.
* Endpoint yazıldı mı: hayır.
* Migration var mı: hayır.
* Sync/import/build/projection iş kurallarına dokunuldu mu: hayır.
* Tenant catalog verisi mutate edildi mi: hayır.

## 2. Mevcut Product Hub Ekran Envanteri

Mevcut yapı route düzeyinde dağınık olsa da pratikte iki ayrı dünyaya bölünüyor:

* Super Admin teknik Product Hub alanı: yaklaşık `16+` ana ekran kümesi ve `20+` route.
* Tenant satış/katalog alanı: yaklaşık `7` görünür ana ekran + `catalog/search` JSON arama hattı.
* Tenant `ProductDataHubController`: gerçek satış yüzeyi değil; büyük ölçüde kısıtlayıcı/placeholder kabuk.

| Ekran | Route | Blade | Amaç | Kullanıcı tipi | Karar |
| --- | --- | --- | --- | --- | --- |
| Genel Bakış / Durum Merkezi | `admin.super.product-data-hub.index` | `resources/views/super-admin/product-data-hub/index.blade.php` | Günlük operasyon özeti, aksiyon kuyruğu, kaynak ve yayın durumu | Super Admin operasyon | Kalacak, ana giriş ekranı |
| Akış Kontrol | `admin.super.product-data-hub.pipeline` | `resources/views/super-admin/product-data-hub/pipeline.blade.php` | Teknik veri hattı sırası ve bakım görünümü | Super Admin teknik | Kalacak, teknik/diagnostic |
| Abone Katalog Yayını | `admin.super.product-data-hub.catalog-output` | `resources/views/super-admin/product-data-hub/catalog-output.blade.php` | Projection ve tenant kataloğa yansıtma durumu | Super Admin operasyon | Kalacak |
| Ürün Paneli | `admin.super.product-data-hub.product-panel` | `resources/views/super-admin/product-data-hub/product-panel.blade.php` | Satışa uygunluk, stale durum, review queue | Super Admin operasyon/diagnostic | Kalacak |
| Tedarikçi Kaynakları | `admin.super.product-data-hub.sources.*` | `resources/views/super-admin/product-data-hub/sources/*` | Kaynak tanımı, preview, edit, sync raporu | Super Admin teknik | Kalacak, "Kaynaklar" çatısı altında gruplanmalı |
| Alan Eşlemeleri | `admin.super.product-data-hub.field-mappings.*` | `resources/views/super-admin/product-data-hub/field-mappings/*` | Kaynak alanlarının standart alana eşlenmesi | Super Admin teknik | Kalacak, teknik kalmalı |
| Kategori Eşlemeleri | `admin.super.product-data-hub.category-mappings.*` | `resources/views/super-admin/product-data-hub/category-mappings.blade.php` | Supplier kategori -> standart kategori eşleme | Super Admin teknik/operasyon | Kalacak |
| Standart Kategori Ağacı | `admin.super.standard-categories.*` | ilgili standard category view'ları | Global kategori omurgası | Super Admin teknik | Kalacak |
| Kategori Temizlik | `admin.super.product-data-hub.category-cleanup.index` | `resources/views/super-admin/product-data-hub/category-cleanup.blade.php` | Review batch/cleanup | Diagnostic | Kalacak, teknik kalmalı |
| Özellik Şablonları | `admin.super.product-data-hub.category-feature-templates.index` | `resources/views/super-admin/product-data-hub/category-feature-templates.blade.php` | Kategori bazlı attribute şablonları | Super Admin teknik | Kalacak |
| Category Review Batch | `admin.super.product-data-hub.category-review-batches.show` | `resources/views/super-admin/product-data-hub/category-review-batch.blade.php` | Batch karar ekranı | Diagnostic | Kalacak |
| Supplier Products / Raw yaklaşımı | `admin.super.product-data-hub.supplier-products` | `resources/views/super-admin/product-data-hub/supplier-products.blade.php` | Supplier raw kayıtlarının operasyonel görünümü | Super Admin teknik | Kalacak, teknik kalmalı |
| Raw Products | `admin.super.product-data-hub.raw-products.index` | `resources/views/super-admin/product-data-hub/raw-products.blade.php` | Ham staging kayıtları | Super Admin teknik | Gizlenmez ama teknik kalmalı |
| Standard Products | `admin.super.product-data-hub.standard-products.index` | `resources/views/super-admin/product-data-hub/standard-products.blade.php` | Projection öncesi global temiz ürün havuzu | Super Admin teknik | Kalacak |
| Profil Karşılaştırma | `admin.super.product-data-hub.profile-comparison` | `resources/views/super-admin/product-data-hub/profile-comparison.blade.php` | Supplier profil/field karşılaştırma | Diagnostic | Kalacak, teknik kalmalı |
| Tenant Supplier Access | `admin.super.tenant-supplier-access.*` | `resources/views/super-admin/tenant-supplier-access/*` | Tenant bazlı supplier erişim/yayın kapısı | Super Admin operasyon | Kalacak |
| Katalog Ürünleri | `admin.catalog.index` | `resources/views/admin/catalog/index.blade.php` | Tenant'a açılmış satış katalog yüzeyi | Tenant satış | Kalacak |
| Tenant Product Panel | `admin.catalog.product-panel` | `resources/views/admin/catalog/product-panel.blade.php` | Tenant görünür ürün listesi | Tenant satış/operasyon | Kalacak |
| Tenant Supplier Products | `admin.catalog.supplier-products` | `resources/views/admin/catalog/supplier-products.blade.php` | Supplier projection görünümü | Tenant satış/operasyon | Kalacak, ama teknik Product Hub diye sunulmamalı |
| Local Products | `admin.catalog.local-products*` | `resources/views/admin/catalog/local-products.blade.php` | Tenant kendi ürünlerini yönetir | Tenant satış/operasyon | Kalacak |
| Visibility | `admin.catalog.visibility` | `resources/views/admin/catalog/visibility.blade.php` | Katalog/teklif görünürlüğü | Tenant operasyon | Kalacak |
| Warnings | `admin.catalog.warnings` | `resources/views/admin/catalog/warnings.blade.php` | Uyarılı ürünler | Tenant operasyon | Kalacak |
| Catalog Search JSON | `admin.catalog.search` | controller JSON çıktısı | Teklif ürün arama kaynağı | Tenant satış | Kalacak, kritik |
| Tenant Product Data Hub placeholder | `admin.product-data-hub.*` | `resources/views/admin/product-data-hub/*` | Tarihsel/placeholder kabuk | Tenant için kapalı | Gizli kalmalı, satış yüzeyi olarak öne çıkarılmamalı |

Kafa karışıklığı yaratan başlıca alanlar:

* `Product Data Hub` adı hem Super Admin teknik omurgayı hem de tenant ürün akışını çağrıştırıyor.
* Tenant tarafında gerçek satış akışı `catalog/*` altında iken tarihsel `product-data-hub/*` route'ları kavramsal gürültü yaratıyor.
* `common-products`, `standard-products`, `supplier-products`, `raw-products`, `product-panel` birbirine yakın ama farklı soyutlama seviyelerinde.

Korunması gereken sınır:

* Super Admin teknik omurga = kaynak, mapping, standard pool, projection, tenant access.
* Tenant satış yüzeyi = tenant catalog + catalog search.

## 3. Yeni Sade Product Hub Bilgi Mimarisi

Super Admin tarafı için önerilen sade yapı:

1. Kaynaklar
   * Supplier source tanımları
   * bağlantı durumu
   * preview
   * sync raporu
2. Önizleme ve Eşleme
   * field mappings
   * category mappings
   * preview parsing
   * profil karşılaştırma
3. Ürün Havuzu
   * standard products
   * supplier/raw products
   * sellable truth / warning queue
4. Değişiklikler
   * stale price/stock
   * pending review
   * projection warning
5. Tenant Yayını
   * tenant supplier access
   * catalog output
   * projection refresh / missing projection tamamlama

Tenant / teklif tarafı:

* Teknik Product Hub panelini görmez.
* Sadece tenant catalog projection/read model ürünlerini görür.
* Teklif ürün araması `CatalogSearchController` üzerinden `TenantCatalogProduct` ve `TenantCatalogProductVariant` okur.

## 4. Menü ve Terminoloji Önerisi

Bu fazda uygulanan düşük riskli sadeleştirme:

* Super Admin menü grup etiketi: `Product Data Hub` -> `Ürün Veri Merkezi`
* Super Admin accordion etiketi: `Product Data Hub` -> `Ürün Veri Merkezi`

Değerlendirme:

* `Ürün Veri Merkezi`: en dengeli seçenek. Teknik omurgayı anlatır, tenant satış kataloğu ile daha az karışır.
* `Tedarikçi Ürün Havuzu`: daha dar, mapping/projection/tenant publish katmanlarını tam kapsamaz.
* `Ürün Aktarım Merkezi`: sync/projection tonunu iyi verir ama standard pool ve review alanını eksik anlatır.

Standart öneri:

* Kullanıcı-facing ana terim: `Ürün Veri Merkezi`
* Teknik alt ekranlarda mevcut Product Data Hub terimi gerektiği yerde metin içinde kalabilir.

## 5. Tenant Catalog Projection Yaklaşımı

Kod okumasına göre hedef zincir zaten büyük ölçüde mevcut:

* Raw supplier data -> `SupplierProductRaw` / `SupplierProductVariantRaw`
* Standard pool -> `StandardProduct` / `StandardProductVariant`
* Tenant publish/read model -> `TenantCatalogProduct` / `TenantCatalogProductVariant`
* Quote ürün seçimi -> `CatalogSearchController`
* Quote snapshot -> teklif satırında `price_snapshot` ve `product_snapshot`

Kanıt noktaları:

* `TenantCatalogProjectionService` tenant katalog ürünlerini standard product'tan üretir.
* `CatalogSearchController` doğrudan `TenantCatalogProduct` sorgular.
* `ProductHubSellableTruthService` teklifte görünen efektif fiyat/stok durumunu tenant catalog katmanından çözer.
* `resources/views/admin/promotion-quotes/_form-workspace.blade.php` teklif satırlarında `tenant_catalog_product_id` ve `price_snapshot` taşır.

Snapshot mantığı:

* Ürün seçildiğinde teklif satırına snapshot alınır.
* Geçmiş teklif sessizce mutasyona uğramaz.
* Canlı veri ile snapshot farkı ileride uyarı üretmelidir; bu fazda endpoint yazılmadı.

## 6. Canlı Ürün Bilgisi Contract

Önerilen endpoint:

`GET /admin/product-hub/live-product-info?tenant_catalog_product_id=...`

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

Güvenlik kuralları:

* Raw supplier payload dönülmeyecek.
* `group_code`, supplier internal id, maliyet/alış fiyatı, dosya yolu, token/secrets dönülmeyecek.
* Tenant tarafına yalnız satış için güvenli özet bilgi verilecek.

Not:

* Bu endpoint bu fazda yazılmadı.

## 7. Mevcut Kopukluklar

1. Sync sonrası global veri güncelleniyor mu?
   * Evet, Product Hub zincirinde standard pool ve projection güncelleme akışı mevcut.
   * `SuperAdminCatalogOutputProjectionActionsTest` stale projection'ın refresh ile düzeldiğini doğruluyor.

2. Tenant catalog projection güncelleniyor mu?
   * Evet, `TenantCatalogProjectionService::projectForTenant()` ile güncelleniyor.
   * Ancak refresh işlemi bugün daha çok operasyonel komut/aksiyon mantığında; kullanıcı-facing canlı okuma endpoint'i yok.

3. Teklif ürün seçimi güncel veriyi görüyor mu?
   * Evet, arama katmanı tenant catalog projection'dan okuyor.
   * Ancak bu "güncel canlı bilgi uyarısı" değil; arama sonucu anlık projection durumunu kullanıyor.

4. Eski tekliflerde fiyat değişiklik uyarısı var mı?
   * Bu fazda doğrudan kullanıcı-facing canlı fark uyarısı bulunmuyor.
   * Snapshot korunuyor; fakat canlı değişim farkını gösteren ayrı endpoint/UI başlangıcı henüz yok.

5. Tenant supplier access kapanırsa etkisi ne?
   * `CatalogSearchController`, `TenantSupplierAccess` üzerinden supplier görünürlüğünü filtreliyor.
   * Tenant erişimi kapalıysa ilgili supplier ürünleri arama sonucundan düşebilir.

6. Ürün pasife düşerse teklif ekranında uyarı var mı?
   * Projection ve sellable truth tarafında `catalog_status`, `is_active`, `visible_in_quote` gibi alanlar var.
   * Ancak teklif satırında standartlaşmış "ürün pasife düştü" canlı uyarı contract'ı ayrı faza kalıyor.

Sorumlu ana servisler:

* `App\Services\ProductDataHub\TenantCatalogProjectionService`
* `App\Services\ProductDataHub\ProductHubSellableTruthService`
* `App\Http\Controllers\Admin\CatalogSearchController`
* `App\Http\Controllers\Admin\TenantCatalogController`

Mevcut testler:

* `SuperAdminCatalogOutputProjectionActionsTest`
* `TenantCatalogProjectionBackfillCommandTest`
* `TenantCatalogSupplierVisibilityTest`
* `TenantCatalogContextAndSupplierFilterTest`
* `TenantAdvancedCatalogTest`
* `QuoteApprovalCoreTest`
* `RevisionRepeatOrderNoSensitiveLeakTest`
* `SupplierProductVisibilityAuditTest`

Eksik test ihtiyacı:

* Snapshot ile ileride gelecek live-info endpoint fark uyarısının UI davranışı
* Supplier access kapandıktan sonra mevcut teklif satırında gösterilecek güvenli warning davranışı
* Pasife düşen tenant catalog ürününün mevcut teklif edit ekranındaki uyarı dili

## 8. UI-3 Design System Uyum Hazırlığı

Bu fazda izlenen namespace standardı:

* `.pd-product-hub`
* `.pd-card`
* `.pd-btn`
* `.pd-chip`
* `.pd-tabs`
* `.pd-summary`
* `.pd-table`
* `.pd-form`

Yapılan düşük riskli hook:

* `resources/views/super-admin/product-data-hub/index.blade.php`
* `resources/views/super-admin/product-data-hub/pipeline.blade.php`

Bu iki root wrapper'a `.pd-product-hub` sınıfı eklendi.

Daha geniş redesign neden yapılmadı:

* PH-1 kapsamı hazırlık + güvenli sadeleştirme.
* Büyük view refactor bu faz için gereksiz risk üretirdi.

## 9. Güvenlik ve Hassas Veri Kontrolü

Kod okuması ve mevcut testlere göre tenant/teklif/public katmana sızmaması gereken alanlar için koruyucu yapı mevcut:

* `CatalogSearchController::stripTenantHiddenGroupFields()` tenant arama çıktısından `supplier_group_code` türevlerini temizliyor.
* `QuoteApprovalCoreTest` snapshot içinde `group_code`, `raw_payload`, `raw_price_snapshot`, `pdh_internal`, `source_summary` sızmadığını doğruluyor.
* Birçok smoke/regression testi `group_code`, `file_path`, `physical_path` gibi alanların görünmemesini kontrol ediyor.

PH-1 kapsamında yeni hassas veri yüzeyi eklenmedi.

## 10. Test Sonuçları

Çalıştırılan odak:

* `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
* `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`

Sonuç:

* İlk filtre seti: `353` test, `353` geçti, `2376` assertion, yaklaşık `49.4 sn`
* İkinci filtre seti: `190` test, `190` geçti, `1610` assertion, yaklaşık `55.2 sn`
* Toplam: `543` test geçti

Doğrulanan ana noktalar:

* Menü sadeleştirmesi route isimlerini kırmadı.
* Tenant menü sadeleştirme beklentileri korundu.
* Super Admin menü etiketi `Ürün Veri Merkezi` olarak doğrulandı.
* Tenant catalog projection, Product Hub ekranları ve teklif/sipariş odaklı ilgili regresyon filtreleri temiz geçti.

## 11. Kalan Riskler

* Super Admin içinde hâlâ çok sayıda teknik ekran aynı çatı altında duruyor; sadece etiket sadeleşmesi bilgi mimarisi karmaşasını tamamen çözmez.
* Tenant tarafında tarihsel `product-data-hub` placeholder yapısı kavramsal borç olarak duruyor.
* Canlı bilgi endpoint'i olmadığı için snapshot ile güncel durum arasındaki fark kullanıcıya standartlaştırılmış biçimde yansımıyor.
* Projection refresh bugün daha çok operasyonel aksiyon düzeyinde; otomatik/ince warning katmanı sonraki faz ister.

## 12. PH-2 İçin Net Öneri

Öneri: `PH-2B Tenant Catalog read model netleştirme`

Gerekçe:

* Asıl satış/teklif yüzeyi zaten tenant catalog projection üzerinden çalışıyor.
* Canlı bilgi contract'ının en güvenli başlangıç noktası tenant catalog read model katmanı.
* Super Admin ekran sadeleştirmesinden önce satış yüzeyinin veri sınırını iyice netleştirmek daha yüksek değer üretir.
