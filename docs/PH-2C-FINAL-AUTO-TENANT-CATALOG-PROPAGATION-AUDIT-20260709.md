# PH-2C Final Auto Tenant Catalog Propagation Audit — 2026-07-09

## 1. Özet
- XML/JSON/CSV senkronizasyonu sonrası uygun ürünlerin tenant catalog katmanına otomatik yansıması mevcut.
- Günlük kullanımda Super Admin'in tek tek Abone Firma seçip `Ürünleri Güncelle` çalıştırması zorunlu değildir.
- Mevcut mimaride asıl akış `source sync -> standard build -> tenant catalog projection -> teklif arama / canlı bilgi` şeklindedir.
- `Abone Firma seç` alanı normal operasyon için değil, seçili tenant üzerinde kontrollü refresh / missing-only repair çalıştırmak için kullanılır.
- Operasyonel tek önemli ön koşul, zamanlanmış otomasyon isteniyorsa Laravel scheduler'ın sunucuda aktif olmasıdır.

## 2. Mevcut Sync Zinciri
- `raw`: Kaynak senkronizasyonu `product-data-hub:sync-sources` komutu ve `SupplierSourceSyncService::syncSource()` üzerinden çalışır. Ana akış [ProductDataHubSyncSourcesCommand.php](/C:/laragon/www/prodelya_core/app/Console/Commands/ProductDataHubSyncSourcesCommand.php:20) ve [SupplierSourceSyncService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/SupplierSourceSyncService.php:240) içinde görünür.
- `standard`: Sync sonrası standard ürün üretimi aynı servis içinde otomatik ilerler. `sync_auto_build` varsayılanı açıktır; testler de bu davranışı doğrular: [ProductDataHubAutoSyncProjectionTest.php](/C:/laragon/www/prodelya_core/tests/Feature/ProductDataHubAutoSyncProjectionTest.php:38).
- `tenant catalog`: Standard ürünlerden tenant catalog projection aynı sync zinciri içinde otomatik tetiklenir. `shouldAutoProject()` varsayılan olarak `sync_auto_project_to_tenant_catalog=true` kabul eder: [SupplierSourceSyncService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/SupplierSourceSyncService.php:480). Full sync tarafında `projectSourceProductsToTenants()` çağrılır: [SupplierSourceSyncService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/SupplierSourceSyncService.php:624).
- `teklif ürün arama`: `/admin/catalog/search` doğrudan `TenantCatalogProduct` okur; current tenant, görünürlük ve supplier access filtreleri burada uygulanır: [CatalogSearchController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/CatalogSearchController.php:19).
- `canlı bilgi endpoint`: `ProductHubLiveProductInfoService` tenant bazlı `TenantCatalogProduct` / `TenantCatalogProductVariant` okur; güncel fiyat/stok/erişim bilgisi tenant catalog üstünden çözülür: [ProductHubLiveProductInfoService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubLiveProductInfoService.php:18).

## 3. Abone Firma Seçme Ekranı Analizi
- Günlük kullanım ekranı değildir; ekran metni normal akışta ayrı aktarım beklenmediğini açıkça söyler: [catalog-output.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/catalog-output.blade.php:38), [catalog-output.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/catalog-output.blade.php:70), [catalog-output.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/catalog-output.blade.php:283).
- Kontrol ve onarım ekranı niteliğindedir. Seçili tenant ile çalışan butonlar:
- `Ürünleri Güncelle`: seçili tenant için `projectForTenant($tenant)` çalıştırır: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:251).
- `Boşlukları Tamamla`: seçili tenant için `missing_only=true` ile projection repair çalıştırır: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:226).
- Tenant seçilmeden bu butonlar çalışmaz; controller güvenli hata ile döner. Bu da ekranın günlük zorunlu akış değil, kontrollü müdahale yüzeyi olduğunu doğrular: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:233), [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:261), [SuperAdminCatalogOutputProjectionActionsTest.php](/C:/laragon/www/prodelya_core/tests/Feature/SuperAdminCatalogOutputProjectionActionsTest.php:111).

## 4. Otomatik Yayın Davranışı
- Tüm tenantlara otomatik fan-out vardır; ancak tüm tenantlar değil, ilgili supplier için erişimi açık olan tenantlar etkilenir.
- `projectDirtyStandardProducts()` içinde tenant listesi `TenantSupplierAccess` tablosundan şu koşullarla alınır:
- `is_active = true`
- `can_view_products = true`
- `visible_in_catalog = true`
- Sonra her uygun tenant için `tenantCatalogProjection->projectForTenant(...)` çağrılır: [SupplierSourceSyncService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/SupplierSourceSyncService.php:629).
- Bu nedenle `Ürünleri Senkronize Et` mantığı seçili tek tenant değil, ilgili supplier'a erişimi olan tüm tenantları etkileyen otomatik projection zincirine sahiptir.
- Delta fiyat/stok akışında da dirty standard product listesi aynı fan-out mantığıyla tenant catalog'a yansıtılır; servis içinde `projection_mode=dirty` ve `affected_tenants_count` istatistikleri tutulur: [SupplierSourceSyncService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/SupplierSourceSyncService.php:629).
- `tenant-supplier-access` ekranı da bunu kullanıcıya açıkça anlatır: erişim açık ve ürün satışa uygunsa ekstra `teklife aktar` adımı yoktur: [tenant-supplier-access/index.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/tenant-supplier-access/index.blade.php:14), [tenant-supplier-access/index.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/tenant-supplier-access/index.blade.php:32).

## 5. Teklif/Sipariş Ürün Seçimi
- Güncel veri tenant catalog katmanından okunur; raw veya standard katman doğrudan teklif arama datasource'u değildir.
- `/admin/catalog/search` tenant bazlı `TenantCatalogProduct` sorgular, sonra supplier access ve quote visibility ile sonuçları süzer: [CatalogSearchController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/CatalogSearchController.php:28).
- `ProductHubLiveProductInfoService` yine tenant catalog ürün/varyant kaydını okuyup güncel fiyat, stok, quote görünürlüğü, supplier access ve `last_synced_at` döndürür: [ProductHubLiveProductInfoService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubLiveProductInfoService.php:31).
- Testler, sync sonrası tenant catalog fiyat/stok değişiminin teklif aramasına yansıdığını doğrular: [ProductDataHubAutoSyncProjectionTest.php](/C:/laragon/www/prodelya_core/tests/Feature/ProductDataHubAutoSyncProjectionTest.php:38).
- Testler, projection yenilemesi sonrası quote search sonucunun güncel fiyat/stok gösterdiğini de doğrular: [SuperAdminCatalogOutputProjectionActionsTest.php](/C:/laragon/www/prodelya_core/tests/Feature/SuperAdminCatalogOutputProjectionActionsTest.php:40).
- Testler, canlı bilgi endpoint'inin tenant catalog verisini tenant sınırları içinde doğru okuduğunu da doğrular: [ProductHubLiveProductInfoEndpointTest.php](/C:/laragon/www/prodelya_core/tests/Feature/ProductHubLiveProductInfoEndpointTest.php:30).

## 6. Eksik Varsa Gerekli Faz
- Küçük backend düzeltme zorunlu görünmüyor; mevcut kod zaten otomatik propagation mantığıyla kurulmuş.
- Queue/job zorunlu değil; mevcut mimari sync sırasında projection çağrısı yapıyor.
- Komut düzenleme zorunlu değil; `product-data-hub:sync-sources` zaten auto-build ve auto-project davranışını destekliyor, `--no-project` ise bilinçli istisna bayrağı: [ProductDataHubScheduledSyncCommandTest.php](/C:/laragon/www/prodelya_core/tests/Feature/ProductDataHubScheduledSyncCommandTest.php:98).
- Gerekli en küçük faz, backend değişikliği değil, operasyon ve konumlandırma netliğidir:
- Sunucuda Laravel scheduler'ın aktif olduğunun doğrulanması
- `Abone Firma seç` alanının kontrol/onarım amacı taşıdığının ürün sahiplerine açık anlatılması
- İstenirse küçük bir UI kopya netleştirme fazı yapılabilir; fakat audit bulgusuna göre otomatik propagation için yeni backend fazı şart değildir.

## 7. Net Karar
- Sistem zaten otomatik çalışıyor; Abone Firma seçimi sadece kontrol ekranı.

Detaylı karar:
- XML/JSON/CSV sync sonrası tenant catalog projection otomatik tetikleniyor.
- Bu tetikleme, ilgili supplier'a erişimi olan tüm uygun Abone Firmalara fan-out ediyor.
- Manuel Abone Firma seçmek günlük kullanım için zorunlu değil.
- `Ürünleri Güncelle` ve `Boşlukları Tamamla` tüm tenantlar için ana yayın mekanizması değil; seçili tenant için kontrollü refresh / repair araçlarıdır.
- Zorunlu manuel adım ancak şu istisna durumlarında ortaya çıkar:
- sync `--no-project` ile bilinçli kapatılmışsa
- source config içinde `sync_auto_project_to_tenant_catalog` kapatılmışsa
- scheduler/otomatik tetikleyici operasyonel olarak çalışmıyorsa
- belirli kayıtlar access, kategori, fiyat veya görünürlük kurallarına takılıp Bekleyen Kontroller / hold-state mantığına düşüyorsa

## 8. Sonraki Öneri
- Product Hub commit hazırlığı

Gerekçe:
- Audit sonucu yeni bir `PH-2F Otomatik Tenant Catalog Propagation` backend fazını zorunlu kılmıyor.
- Eğer kullanıcı algısında hâlâ karışıklık varsa, bu daha çok ürün dili / ekran konumlandırması seviyesinde ele alınmalı.
- Bu nedenle bir sonraki pratik adım, mevcut otomatik davranışı commit seviyesinde sabitlemek ve istenirse ayrı, küçük bir UI açıklaması netleştirme işi açmaktır.
