# PH-2E Category Pending Non-Blocking + Metric Fix Raporu — 2026-07-09

## 1. Özet
- `category_pending` / kategori eşleşmemiş durumu satış blokajı gibi yorumlanan alanlar düzeltildi.
- Arama, canlı ürün bilgisi, Product Hub karar ekranı ve metrik kartları aynı iş kuralına hizalandı.
- Değişen ana dosyalar:
  - [ProductHubLiveProductInfoService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubLiveProductInfoService.php:18)
  - [CatalogSearchController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/CatalogSearchController.php:19)
  - [TenantCatalogListRowQueryService.php](/C:/laragon/www/prodelya_core/app/Services/TenantCatalog/TenantCatalogListRowQueryService.php:160)
  - [ProductHubFreshnessDiagnosticService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubFreshnessDiagnosticService.php:390)
  - [ProductHubSyncDecisionService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubSyncDecisionService.php:14)
  - [ProductHubReviewQueueService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubReviewQueueService.php:10)
  - [ProductHubOperationFlowService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubOperationFlowService.php:12)
  - [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:607)
  - [catalog-output.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/catalog-output.blade.php:25)
  - [product-panel.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/product-panel.blade.php:50)
- İş kuralı değişmedi; kod gerçek istenen iş kuralına getirildi:
  - kategori eşleşmemiş = bilgi/uyarı
  - satış blokajı değil
- Migration yok.
- Bu fazda manuel DB write yok.

## 2. Eski Sorun
- `category_pending` ürünler bazı yerlerde ayrı uyarı olmaktan çıkıp review/pending gibi sayılıyordu.
- Özellikle şu problemler vardı:
  - Product Hub `review_queue` toplamı içine `category_waiting` dahil ediliyordu: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:607)
  - `review_queue` filtre akışı category_waiting satırlarını da çekiyordu: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:946)
  - Sync decision özetinde `projection.blocked_missing_category` projection pending sayısına katılıyordu: [ProductHubSyncDecisionService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubSyncDecisionService.php:55)
  - UI metinleri `Kategori Bekliyor` / `Satış listesine yansıma bekleyen` gibi daha bloklayıcı algı üretiyordu.
- Teklif/sipariş çıkışının asıl bloklandığı yer `category_pending` değildi; buna rağmen sayaç ve ekran dili kullanıcıyı yanlış yöne itiyordu.

## 3. Yeni İş Kuralı
- Kategori eşleşmemiş satışa engel değildir.
- Sadece bilgi/uyarıdır.
- Teklif/sipariş aramada görünür.
- Canlı bilgi endpoint’te uygun kalır.
- Yeni kullanıcı-facing dil:
  - `Kategori eşleşmemiş`
  - `Genel kategori henüz bağlanmadı`
  - `Kategori uyarısı`

## 4. Arama / Endpoint / Metrik Tutarlılığı
- Catalog search:
  - `missing_category` badge dili `Kategori eşleşmemiş` + `Kategori uyarısı` olarak güncellendi.
  - Ürün aramadan çıkarılmıyor; yalnız uyarı bilgisi üretiliyor.
  - Kaynak: [CatalogSearchController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/Admin/CatalogSearchController.php:487)
- Live product info endpoint:
  - `category_pending` durumunda ürün `ok=true` ve `public_safe_message=Urun guncel ve teklif icin uygun.` kalıyor.
  - Kategori uyarısı ayrı warning olarak ekleniyor; `aktif değil` uyarısı üretilmiyor.
  - Kaynak: [ProductHubLiveProductInfoService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubLiveProductInfoService.php:88)
- Product Hub Bekleyen Kontroller:
  - `category_waiting` ayrı bilgi kartı olarak korunuyor.
  - Ana `İnceleme Gerekenler` toplamından çıkarıldı.
  - `review_queue` filtre akışı artık category_waiting satırlarını çekmiyor.
  - Kaynak: [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:607), [SuperAdminProductDataHubController.php](/C:/laragon/www/prodelya_core/app/Http/Controllers/SuperAdmin/SuperAdminProductDataHubController.php:946)
- catalog-output metrikleri:
  - `Satış listesine yansıma bekleyen` etiketi `Projection boşluğu` olarak netleştirildi.
  - Böylece category_pending ile projection eksikliği aynı şeymiş gibi görünmüyor.
  - Kaynak: [catalog-output.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/catalog-output.blade.php:25)
- sources list metrikleri:
  - Sync decision summary artık category waiting yüzünden projection pending göstermiyor.
  - Kaynak: [ProductHubSyncDecisionService.php](/C:/laragon/www/prodelya_core/app/Services/ProductDataHub/ProductHubSyncDecisionService.php:50)

## 5. UI Metinleri
- `Kategori Bekliyor` ağırlıklı dil yerine daha açık metinler kullanıldı.
- Kullanıcıya satışa engel olmadığı anlatıldı:
  - `Genel kategori henüz bağlanmadı. Ürün teklif aramasında görünmeye devam eder.`
  - `Ürün teklif/sipariş ekranında görünür; yalnız genel kategori eşlemesi eksik.`
- İlgili yüzeyler:
  - [resources/views/admin/catalog/product-panel.blade.php](/C:/laragon/www/prodelya_core/resources/views/admin/catalog/product-panel.blade.php:16)
  - [resources/views/super-admin/product-data-hub/product-panel.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/product-panel.blade.php:50)
  - [resources/views/super-admin/product-data-hub/category-cleanup.blade.php](/C:/laragon/www/prodelya_core/resources/views/super-admin/product-data-hub/category-cleanup.blade.php:106)

## 6. Test Sonuçları
- Geçti:
  - `php artisan test --filter="ProductHubLiveProductInfoEndpointTest|PromotionQuoteLiveProductInfoUiTest|PromotionQuoteHasPrintFirstRowQuantityRegressionTest"`
  - Sonuç: `15/15` test geçti
- Geçti:
  - `php artisan test --filter="ProductHub|ProductDataHub|TenantCatalog|SupplierAccess|PromotionQuoteDetailCssNamespaceSmokeTest"`
  - Sonuç: `366/366` test geçti
- Geçti:
  - `php artisan test --filter="PromotionQuote|PublicQuoteApproval|OrderRevision|RepeatOrder"`
  - Sonuç: `192/192` test geçti
- Ayrıca güncellenen/eklenen test kapsaması:
  - category_pending ürün live endpoint’te uygun kalır
  - category_pending ürün review kuyruğunu şişirmez
  - warning badge dili yeni terminolojiye uyar
  - catalog output / source stepper metinleri yeni sınıflandırmaya uyar

## 7. Manuel Smoke Sonucu
- Bu oturumda in-app browser erişimi açılamadı.
- Deneme sonucu: `Browser is not available: iab`
- Bu yüzden gerçek tıklamalı manuel smoke bu turda tamamlanamadı.
- Yerine UI/feature testiyle aşağıdaki davranışlar doğrulandı:
  - create/edit quote ekranında live product info bileşeni render oluyor
  - live product info endpoint category_pending ürünü uygun döndürüyor
  - Product Hub panelinde category_pending ayrı akışta duruyor, review kuyruğuna girmiyor

## 8. Kalan Riskler
- Bu fazda metin ve sınıflandırma hizası düzeltildi; üretim ortamında gerçek kullanıcı tarayıcısında manuel quote-create akışı ayrıca gözle kontrol edilirse iyi olur.
- Repo içinde hâlâ bazı teknik/yardımcı ekranlarda eski `Kategori Bekliyor` benzeri stringler bulunabilir; bu fazın odak yüzeyleri düzeltildi.
- `PromotionQuoteController.php` dosyasında kullanıcıdan gelen başka mevcut değişiklikler de var; bu faz yalnız category warning badge metnini ilgili bölümde güncelledi.

## 9. Sonraki Öneri
- Product Hub commit hazırlığı

Ana karar:
- kategori eşleşmemiş ürün, diğer şartları uygunsa teklif/sipariş ekranında görünmeye devam eder.
- kategori eşleme kalite/raporlama uyarısıdır, satışa çıkış blokajı değildir.
