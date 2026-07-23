# PH-0 Product Hub Information Architecture Freeze

Tarih: 2026-07-15
Kapsam: Read-only audit + docs freeze
Kaynak kanıtları:
- `config/admin_menu.php`
- `app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php`
- `app/Http/Controllers/SuperAdmin/SuperAdminSupplierSourceController.php`
- `app/Http/Controllers/Admin/ProductDataHubController.php`
- `php artisan route:list`
- `docs/PRODUCT-DATA-HUB-PROCESS-AND-SIMPLIFICATION-MAP-20260715.html`

## 1. Executive decision

Bu turda Product Hub bilgi mimarisi dondurulmuştur. Kanıtlanan omurga şudur:

`supplier_sources` -> `supplier_products_raw` / `supplier_product_variants_raw` -> `standard_products` / `standard_product_variants` -> `tenant_catalog_products` / `tenant_catalog_product_variants` -> quote search

Kararları:

- Raw veya standard katmanda veri görünmesi, tenant katalog görünürlüğü anlamına gelmez.
- Tenant tarafı ürünleri ancak projection sonrası görür.
- `category_pending` varsayılan olarak non-blocking kabul edilir.
- Gerçek bir `Common Products` modeli yoktur; mevcut `common-products` route’u redirect-only davranır.
- Export akışı tamamlanmış değildir; karar etiketi `PARTIAL` olarak dondurulmuştur.
- Günlük kullanıcı menüsü altı ana gruba sabitlenmiştir:
  1. Kaynaklar ve İlk Aktarım
  2. Eşlemeler
  3. Ürün Havuzu
  4. Abone Katalogları
  5. Senkronizasyon ve Çıkışlar
  6. Gelişmiş İşlemler
- PH-1 yalnız kaynaklar, preview, field mapping ve ilk aktarım sadeleştirmesiyle sınırlıdır.

## 2. Current menu inventory

Mevcut super admin menü envanteri ve freeze kararı:

| Mevcut menü | Route | Gerçek iş | Nihai grup | Karar |
| --- | --- | --- | --- | --- |
| Genel Bakış | `admin.super.product-data-hub.index` | Durum özeti, istatistik, son senkron, uyarı sinyalleri | Senkronizasyon ve Çıkışlar | `RENAME` |
| Akış Kontrol | `admin.super.product-data-hub.pipeline` | Teknik pipeline görünümü | Gelişmiş İşlemler | `MOVE TO ADVANCED` |
| Tedarikçi Kaynakları | `admin.super.product-data-hub.sources.index` | Kaynak CRUD, bağlantı, preview giriş noktası | Kaynaklar ve İlk Aktarım | `KEEP` |
| Ürün Paneli | `admin.super.product-data-hub.product-panel` | Günlük ürün inceleme ve operasyon kuyruğu | Ürün Havuzu | `KEEP` |
| Standart Ürünler (Teknik) | `admin.super.product-data-hub.standard-products.index` | Standard store teknik inceleme | Ürün Havuzu | `RENAME` |
| Standart Kategori Ağacı | `admin.super.standard-categories.index` | Prodelya kategori ağacı | Eşlemeler | `KEEP` |
| Kategori Eşleme | `admin.super.product-data-hub.category-mappings.index` | Supplier -> standard kategori bağlama | Eşlemeler | `KEEP` |
| Kategori Temizlik | `admin.super.product-data-hub.category-cleanup.index` | Review batch, cleanup, export | Eşlemeler | `KEEP` |
| Özellik Şablonları | `admin.super.product-data-hub.category-feature-templates.index` | Kategori özellik standardı | Eşlemeler | `KEEP` |
| Abone Firma Katalog Çıkışları | `admin.super.product-data-hub.catalog-output` | Projection durumu, eksik push, refresh | Abone Katalogları | `KEEP` |
| Abone Firma Tedarikçi Erişimleri | `admin.super.tenant-supplier-access.index` | Tenant source erişim matrisi | Abone Katalogları | `KEEP` |
| Senkron ve Raporlar | `admin.super.product-data-hub.sources.sync-reports` | Sync raporları, başarısız koşular | Senkronizasyon ve Çıkışlar | `KEEP` |
| Profil Karşılaştırma | `admin.super.product-data-hub.profile-comparison` | Teknik mapping/profile fark analizi | Gelişmiş İşlemler | `MOVE TO ADVANCED` |

Ek teknik ekranlar:

| Teknik ekran | Route | Karar |
| --- | --- | --- |
| Raw Products | `admin.super.product-data-hub.raw-products.index` | `MOVE TO ADVANCED` |
| Supplier Products | `admin.super.product-data-hub.supplier-products` | `MOVE TO ADVANCED` |
| Field Mappings | `admin.super.product-data-hub.field-mappings.index` | `KEEP` |
| Category Mapping Center | `admin.super.product-data-hub.category-mapping-center` | `MERGE` into Kategori Eşleme |
| Common Products | `admin.super.product-data-hub.common-products` | `HIDE`, redirect-only, PH-3 planlı |

Tenant `admin.product-data-hub.*` tarafı ayrı bir günlük ürün merkezi değildir. `Admin\ProductDataHubController` içinde çok sayıda placeholder/demo/`abort(403)` davranışı bulunduğu için bu route grubu günlük menüde gösterilmemelidir.

## 3. Final six menu groups

### 3.1 Kaynaklar ve İlk Aktarım

Amaç: Yeni tedarikçi kaynağı ekleme, bağlantıyı doğrulama, preview alma, alan eşleme ve ilk aktarım kararını aynı iş akışında toplamak.

Bu gruba bağlanan ekranlar:

- `admin.super.product-data-hub.sources.index`
- `admin.super.product-data-hub.sources.create`
- `admin.super.product-data-hub.sources.edit`
- `admin.super.product-data-hub.sources.preview`
- `admin.super.product-data-hub.sources.test`
- `admin.super.product-data-hub.sources.stage-preview`
- `admin.super.product-data-hub.field-mappings.index`
- `admin.super.product-data-hub.field-mappings.source`

### 3.2 Eşlemeler

Amaç: Supplier alanlarını ve kategorilerini Prodelya standardına bağlamak.

Bu gruba bağlanan ekranlar:

- `admin.super.product-data-hub.category-mappings.index`
- `admin.super.product-data-hub.category-mapping-center`
- `admin.super.standard-categories.index`
- `admin.super.product-data-hub.category-cleanup.index`
- `admin.super.product-data-hub.category-feature-templates.index`
- `admin.super.product-data-hub.categories.search`
- `admin.super.product-data-hub.category-review-batches.show`

### 3.3 Ürün Havuzu

Amaç: Ham ve standard ürünleri günlük operasyonel gözle incelemek, kalite ve kategori uyarılarını görmek.

Bu gruba bağlanan ekranlar:

- `admin.super.product-data-hub.product-panel`
- `admin.super.product-data-hub.standard-products.index`

Teknik alt yüzeyler günlük menüde değil, yalnız advanced erişimle açılır:

- `admin.super.product-data-hub.raw-products.index`
- `admin.super.product-data-hub.supplier-products`

### 3.4 Abone Katalogları

Amaç: Hangi tenant hangi supplier’ı görüyor, projection eksikleri neler, kataloga ne çıktı, bunu yönetmek.

Bu gruba bağlanan ekranlar:

- `admin.super.tenant-supplier-access.index`
- `admin.super.tenant-supplier-access.edit`
- `admin.super.product-data-hub.catalog-output`

Tenant katalog route’ları bilgi mimarisinde bu grubun tüketici tarafıdır:

- `admin.catalog.index`
- `admin.catalog.product-panel`
- `admin.catalog.supplier-products`
- `admin.catalog.visibility`
- `admin.catalog.warnings`
- `admin.catalog.show`
- `admin.catalog.project`

### 3.5 Senkronizasyon ve Çıkışlar

Amaç: Güncel durum, son koşular, sync raporları ve kısmi export yüzeylerini tek yerde toplamak.

Bu gruba bağlanan ekranlar:

- `admin.super.product-data-hub.index`
- `admin.super.product-data-hub.sources.sync-reports`
- `admin.super.product-data-hub.catalog-output`
- `admin.super.product-data-hub.category-cleanup.export`
- `admin.super.product-data-hub.category-review-batches.export`

### 3.6 Gelişmiş İşlemler

Amaç: Günlük kullanıcıyı boğmayan, teknik ekip veya kontrollü operasyon için saklanacak ekranlar.

Bu gruba bağlanan ekranlar:

- `admin.super.product-data-hub.pipeline`
- `admin.super.product-data-hub.profile-comparison`
- `admin.super.product-data-hub.raw-products.index`
- `admin.super.product-data-hub.supplier-products`
- `admin.super.product-data-hub.sources.delta-dry-run`
- `admin.super.product-data-hub.sources.sync`
- `admin.super.product-data-hub.sources.build-standard-products`
- `admin.super.product-data-hub.sources.apply-price-stock`
- `admin.super.product-data-hub.sources.apply-price-stock-project-dirty`
- `admin.super.product-data-hub.sources.archive`
- `admin.super.product-data-hub.sources.deactivate`

## 4. Route-to-menu matrix

| Route | Controller | Günlük etiket | Nihai grup | Karar |
| --- | --- | --- | --- | --- |
| `admin.super.product-data-hub.index` | `SuperAdminProductDataHubController@index` | Hub Durumu | Senkronizasyon ve Çıkışlar | `RENAME` |
| `admin.super.product-data-hub.pipeline` | `SuperAdminProductDataHubController@pipeline` | Teknik Akış Şeması | Gelişmiş İşlemler | `MOVE TO ADVANCED` |
| `admin.super.product-data-hub.sources.index` | `SuperAdminSupplierSourceController@index` | Tedarikçi Kaynakları | Kaynaklar ve İlk Aktarım | `KEEP` |
| `admin.super.product-data-hub.sources.create` | `SuperAdminSupplierSourceController@create` | Yeni Kaynak | Kaynaklar ve İlk Aktarım | `KEEP` |
| `admin.super.product-data-hub.sources.edit` | `SuperAdminSupplierSourceController@edit` | Kaynağı Düzenle | Kaynaklar ve İlk Aktarım | `KEEP` |
| `admin.super.product-data-hub.sources.preview` | `SuperAdminSupplierSourceController@preview` | Kaynağı Önizle | Kaynaklar ve İlk Aktarım | `RENAME` |
| `admin.super.product-data-hub.sources.test` | `SuperAdminSupplierSourceController@test` | Bağlantıyı Kontrol Et | Kaynaklar ve İlk Aktarım | `RENAME` |
| `admin.super.product-data-hub.sources.stage-preview` | `SuperAdminSupplierSourceController@stagePreview` | İlk Aktarıma Hazırla | Kaynaklar ve İlk Aktarım | `RENAME` |
| `admin.super.product-data-hub.field-mappings.index` | `SuperAdminSupplierFieldMappingController@index` | Alan Eşlemeleri | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.field-mappings.source` | `SuperAdminSupplierFieldMappingController@editForSource` | Kaynak Alan Eşlemeleri | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.category-mappings.index` | `SuperAdminSupplierCategoryMappingController@index` | Kategori Eşlemeleri | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.category-mapping-center` | `SuperAdminProductDataHubController@categoryMappingCenter` | Kategori Merkezi | Eşlemeler | `MERGE` |
| `admin.super.standard-categories.index` | `SuperAdminStandardCategoryController@index` | Standart Kategori Ağacı | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.category-cleanup.index` | `SuperAdminCategoryCleanupController@index` | Kategori Temizlik | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.category-feature-templates.index` | `SuperAdminCategoryFeatureTemplateController@index` | Özellik Şablonları | Eşlemeler | `KEEP` |
| `admin.super.product-data-hub.product-panel` | `SuperAdminProductDataHubController@productPanel` | Ürün Paneli | Ürün Havuzu | `KEEP` |
| `admin.super.product-data-hub.standard-products.index` | `SuperAdminStandardProductController@index` | Standart Ürün Havuzu | Ürün Havuzu | `RENAME` |
| `admin.super.product-data-hub.raw-products.index` | `SuperAdminRawProductController@index` | Ham Ürünler | Gelişmiş İşlemler | `MOVE TO ADVANCED` |
| `admin.super.product-data-hub.supplier-products` | `SuperAdminProductDataHubController@supplierProducts` | Supplier Ham Görünüm | Gelişmiş İşlemler | `MOVE TO ADVANCED` |
| `admin.super.tenant-supplier-access.index` | `TenantSupplierAccessController@index` | Abone Tedarikçi Erişimleri | Abone Katalogları | `KEEP` |
| `admin.super.product-data-hub.catalog-output` | `SuperAdminProductDataHubController@catalogOutput` | Katalog Çıkış Durumu | Abone Katalogları | `KEEP` |
| `admin.super.product-data-hub.catalog-output.project-missing` | `SuperAdminProductDataHubController@catalogOutputProjectMissing` | Boşlukları Tamamla | Abone Katalogları | `RENAME` |
| `admin.super.product-data-hub.catalog-output.project-refresh` | `SuperAdminProductDataHubController@catalogOutputProjectRefresh` | Ürünleri Güncelle | Abone Katalogları | `RENAME` |
| `admin.super.product-data-hub.sources.sync-reports` | `SuperAdminSupplierSourceController@syncReports` | Senkron Raporları | Senkronizasyon ve Çıkışlar | `KEEP` |
| `admin.super.product-data-hub.profile-comparison` | `SuperAdminProductDataHubController@profileComparison` | Profil Karşılaştırma | Gelişmiş İşlemler | `MOVE TO ADVANCED` |
| `admin.super.product-data-hub.common-products` | `SuperAdminProductDataHubController@commonProducts` | Common Products | HIDDEN | `HIDE` |
| `admin.product-data-hub.index` | `Admin\ProductDataHubController@index` | Tenant Hub Demo | HIDDEN | `HIDE` |
| `admin.product-data-hub.exports` | `Admin\ProductDataHubController@exports` | Tenant Export Demo | HIDDEN | `HIDE` |
| `admin.product-data-hub.logs` | `Admin\ProductDataHubController@logs` | Tenant Log Demo | HIDDEN | `HIDE` |
| `admin.product-data-hub.product-mappings` | `Admin\ProductDataHubController@productMappings` | Tenant Product Mapping Placeholder | HIDDEN | `HIDE` |
| `admin.product-data-hub.raw-products` | `Admin\ProductDataHubController@rawProducts` | Tenant Raw Placeholder | HIDDEN | `HIDE` |
| `admin.product-data-hub.standard-products` | `Admin\ProductDataHubController@standardProducts` | Tenant Standard Placeholder | HIDDEN | `HIDE` |
| `admin.product-data-hub.tenant-access` | `Admin\ProductDataHubController@tenantAccess` | Tenant Access Placeholder | HIDDEN | `HIDE` |
| `admin.product-data-hub.sync` | `Admin\ProductDataHubController@sync` | Tenant Sync Placeholder | HIDDEN | `HIDE` |
| `admin.catalog.index` | `TenantCatalogController@index` | Abone Katalog Listesi | Abone Katalogları | `KEEP` |
| `admin.catalog.product-panel` | `TenantCatalogController@productPanel` | Abone Ürün Paneli | Abone Katalogları | `KEEP` |
| `admin.catalog.project` | `TenantCatalogController@project` | Kataloğa Yansıt | Abone Katalogları | `KEEP` |
| `admin.product-hub.live-product-info` | `ProductHubLiveProductInfoController@show` | Canlı Ürün Bilgisi | Ürün Havuzu | `KEEP` |

## 5. Action dictionary

| Mevcut aksiyon | Route | Gerçek backend işi | Günlük kullanıcı etiketi | Karar |
| --- | --- | --- | --- | --- |
| Preview | `sources.preview` | Kaynağı parse edip yazmadan örnek veri gösterir | Kaynağı Önizle | `RENAME` |
| Test | `sources.test` | Bağlantı/erişim doğrular | Bağlantıyı Kontrol Et | `RENAME` |
| Stage Preview | `sources.stage-preview` | İlk aktarım öncesi staged örnek çıkarır | İlk Aktarıma Hazırla | `RENAME` |
| Build Standard Products | `sources.build-standard-products` | Raw veriden standard store üretir | Standard Havuzu Oluştur | `MOVE TO ADVANCED` |
| Sync | `sources.sync` | Kaynağı yeniden içeri alır | Kaynağı Yeniden Çek | `MOVE TO ADVANCED` |
| Delta Dry Run | `sources.delta-dry-run` | Değişiklik etkisini yazmadan hesaplar | Değişiklikleri Önizle | `MOVE TO ADVANCED` |
| Apply Price/Stock | `sources.apply-price-stock` | Onaylı fiyat/stok farkını uygular | Onaylı Fiyat/Stok Güncelle | `MOVE TO ADVANCED` |
| Apply Price/Stock + Project Dirty | `sources.apply-price-stock-project-dirty` | Apply sonrası tenant projection dirty set eder | Abone Kataloglarına Yansıt | `MOVE TO ADVANCED` |
| Project Missing | `catalog-output.project-missing` | Eksik projection kayıtlarını tamamlar | Boşlukları Tamamla | `RENAME` |
| Project Refresh | `catalog-output.project-refresh` | Mevcut tenant katalog kayıtlarını günceller | Ürünleri Güncelle | `RENAME` |
| Override Category | `supplier-products.override-category` | Tekil raw kategori override yazar | Kategoriyi Elle Düzelt | `MOVE TO ADVANCED` |
| Auto Approve Mapping | `category-mappings.auto-approve` | Güvenli mappingleri toplu kabul eder | Güvenli Eşlemeleri Onayla | `MOVE TO ADVANCED` |
| Bulk Apply Mapping | `category-mappings.bulk-apply` | Toplu kategori uygulaması | Toplu Eşlemeyi Uygula | `MOVE TO ADVANCED` |
| Review Export | `category-mappings.review-export` | İnceleme listesi export alır | İnceleme Listesini Dışa Aktar | `PARTIAL` |
| Cleanup Export | `category-cleanup.export` | Temizlik çıktısı üretir | Temizlik Çıktısı Al | `PARTIAL` |
| Archive Source | `sources.archive` | Kaynağı arşiv durumuna alır | Kaynağı Arşivle | `MOVE TO ADVANCED` |
| Deactivate Source | `sources.deactivate` | Kaynağı pasifleştirir | Kaynağı Pasifleştir | `MOVE TO ADVANCED` |

## 6. Daily vs advanced visibility

Günlük kullanıcıya açık kalacak yüzeyler:

- Tedarikçi Kaynakları
- Yeni Kaynak / Kaynağı Düzenle
- Kaynağı Önizle
- Bağlantıyı Kontrol Et
- Alan Eşlemeleri
- Kategori Eşlemeleri
- Standart Kategori Ağacı
- Kategori Temizlik
- Özellik Şablonları
- Ürün Paneli
- Standart Ürün Havuzu
- Abone Tedarikçi Erişimleri
- Katalog Çıkış Durumu
- Senkron Raporları

Günlük menüden saklanacak veya advanced altında tutulacak yüzeyler:

- Teknik pipeline
- Raw products
- Supplier products
- Profile comparison
- Delta dry run
- Build standard products
- Apply price/stock
- Apply + project dirty
- Kaynak archive/deactivate
- Placeholder tenant product-data-hub ekranları
- Common Products

Kural:

- Günlük kullanıcı etiketinde `raw`, `stage`, `delta`, `common`, `project dirty`, `pipeline` gibi teknik terimler görünmez.
- Teknik aksiyonlar ancak kontrollü yetki veya advanced yüzey altında kalır.

## 7. Common Product temporary decision

Freeze kararı:

- `Common Products` gerçek bir model değildir.
- `admin.super.product-data-hub.common-products` route’u bağımsız bir domain ekranı değildir; redirect-only davranır.
- Günlük menüden çıkarılır.
- Bilgi mimarisinde `PH-3 planlı` olarak tutulur.
- PH-1 veya PH-2 kapsamına alınmaz.
- `common_products` benzeri olmayan bir şemayı varmış gibi sunan UI üretilmez.

## 8. Export temporary decision

Freeze kararı:

- Export yeteneği `PARTIAL` durumundadır.
- Tam ürün dışa aktarım sözleşmesi bu turda sabitlenmez.
- Şu anki güvenli kapsam:
  - category cleanup export
  - review export
  - rapor odaklı sınırlı çıktılar
- Günlük kullanıcıya export, “tam katalog teslimi” gibi sunulmaz.
- Menü ve kopyada `PARTIAL` veya `kısmi çıktı` beklentisi korunur.

## 9. Dashboard contract

Dashboard sözleşmesi:

- Tek amaç “bugün ne aksiyon almalıyım” sorusunu yanıtlamak olmalıdır.
- Dashboard, kaynak sağlık durumu, son sync, kritik uyarılar, projection bekleyen tenant etkileri ve inceleme kuyruğunu göstermelidir.
- Birincil CTA: `İnceleme Kuyruğunu Aç`
- İkincil CTA: `Kaynakları Kontrol Et`
- Dashboard teknik tablo çöplüğüne dönmemelidir.
- Common Product, multi-supplier offer, tam export gibi henüz gerçek olmayan kavramlar dashboard vaatlerine yazılmaz.

## 10. PH-1 scope

PH-1 bu freeze’e göre açık olan kapsam:

- Kaynak listesi sadeleştirmesi
- Kaynak oluştur / düzenle akışı
- Bağlantıyı Kontrol Et
- Kaynağı Önizle
- Alan eşlemeleri
- İlk aktarım karar yüzeyi
- İlk aktarım sonrası kullanıcının anlayacağı sınırlı sonuç özeti

PH-1 sınırı:

- “Kaynaklar ve İlk Aktarım” odağından çıkılmaz.
- Projection, export motoru, common product, multi-supplier teklifleme ve tenant placeholder ekranları PH-1’e alınmaz.

## 11. PH-1 excluded scope

PH-1 dışında kalacak alanlar:

- Common Product domain modeli
- Multi-supplier offer matrisi
- Export motorunun tamamlanması
- Tenant `admin.product-data-hub.*` placeholder setinin canlandırılması
- Projection algoritması redesign
- Sync/apply backend davranış değişikliği
- Product price truth, procurement price truth veya current-account hesap mantığı
- Yeni route/menu rollout
- Sync/import/apply/project komut çalıştırma

## 12. Test gates

PH-1 başlamadan veya biterken korunması gereken test kapıları:

- Source CRUD yetki ve tenant sınırı
- Preview no-write sözleşmesi
- Test connection no-mutation sözleşmesi
- Field mapping required alan doğruluğu
- Profile-specific field mapping doğruluğu
- İlk aktarım önizleme sonucu ile gerçek işlem sınırının ayrılması
- Category pending non-blocking davranışı
- Raw/standard görünürlüğünün tenant görünürlüğü sanılmaması
- Tenant/super admin boundary
- Product Hub live product info endpoint davranışı
- Admin smoke içinde Product Hub entry noktaları

## 13. Manual smoke gates

Manual smoke checklist:

- Kaynak listesi günlük kullanıcı tarafından anlaşılır olmalı
- Kaynağı Önizle salt-okuma olmalı
- Bağlantıyı Kontrol Et salt-okuma olmalı
- Alan Eşleme ekranı profile özel alanları anlaşılır göstermeli
- İlk aktarım kararı ile gerçek apply/sync aksiyonu birbirine karışmamalı
- Common Products günlük menüde görünmemeli
- Export tam teslim gibi sunulmamalı; kısmi beklenti korunmalı
- Tenant placeholder Product Data Hub linkleri günlük menüye sızmamalı

## 14. Worktree/staging/commit

Preflight kanıtı:

- `git diff --cached --stat`: boş, staged alan yok
- `git status --short`: worktree çok kirli, Product Hub dışı çok sayıda değişiklik ve untracked rapor/test dosyası mevcut
- `git diff --stat`: 93 dosyada kapsamlı değişiklik var, bu fazın dışında kalan quote/procurement/process-depth/currency alanları da kirli
- `git log -15 --oneline`: en yakın commit’ler currency ve process-depth akışlarına ait, Product Hub IA freeze henüz commitlenmemiş

Bu faz kararı:

- Staging yapılmadı
- Commit yapılmadı
- Production/test kodu değiştirilmedi
- DB, sync, import, apply, projection, route/menu rollout çalıştırılmadı
- Yalnız bu doküman yazıldı

## 15. Stop-line

Bu freeze ile birlikte stop-line kuralları:

- PH-1 yalnız kaynaklar-preview-field mapping-ilk aktarım sadeleştirmesiyle ilerler.
- Common Product günlük menüye geri sokulmaz.
- Export “tamamlandı” diye sunulmaz; `PARTIAL` kalır.
- Tenant placeholder Product Data Hub ekranları ürünleşmiş gibi açılmaz.
- Raw/standard/store/projection farkı bulanıklaştırılmaz.
- Sync/apply/project komutları audit fazında çalıştırılmaz.

Son karar:

- Product Hub mevcut 22 ekran ve yaklaşık 50 aksiyon yeni altı ana grup altında yeniden sınıflandırıldı.
- Günlük kullanıcı sözlüğü ile teknik aksiyon sözlüğü ayrıldı.
- PH-1 kapsamı daraltıldı ve güvenli hale getirildi.

FROZEN — PRODUCT HUB INFORMATION ARCHITECTURE AND ACTION DICTIONARY READY — PH-1 GATE OPEN
