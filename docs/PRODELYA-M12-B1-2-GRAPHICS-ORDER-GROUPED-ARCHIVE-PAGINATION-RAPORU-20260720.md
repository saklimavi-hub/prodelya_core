# PRODELYA M12-B1.2

## Kapsam

Bu fazda yalnız `/admin/graphics` sipariş bazlı gruplanmış liste, tamamlananlar arşiv görünümü ve grup bazlı sayfalama ele alındı.

Dokunulan alanlar:

- `app/Services/GraphicModuleDataBuilder.php`
- `app/Http/Controllers/Admin/GraphicController.php`
- `resources/views/admin/graphics/index.blade.php`
- `public/css/prodelya-admin.css`
- ilgili graphics-index testleri

Kesinlikle dokunulmayan kabul edilmiş alanlar:

- grafik detay
- public approval
- secure file route
- mail / recipient
- large preview

Staging veya commit yapılmadı.

## Terminal Status Audit

Audit sonucu bu fazda grup terminal kararı canonical grafik durumlarından türetildi.

Kullanılan kural:

- `production_ready` terminal kabul edildi
- `approved` tek başına terminal kabul edilmedi
- kısmi tamamlanmış grup aktif listede kaldı
- yalnız gruptaki tüm exact grafik operasyonları terminal ise grup `Tamamlananlar` görünümüne taşındı

Yeni `archive` kolonu, migration veya yeni enum eklenmedi.

## Group Key

Grup anahtarı:

- `order_id`

Grup içi exact satır kimliği korunur:

- exact `order_item`
- exact print row `1a / 1b / 1c`
- exact `order_item_print_graphic`

Bir sipariş aynı listede tek kart altında görünür.

## Derived Archive Rule

Varsayılan aktif görünüm:

- en az bir non-terminal grafik operasyonu olan sipariş grupları

Tamamlananlar görünümü:

- tüm grafik operasyonları terminal olan sipariş grupları

`approved` durumu tek başına grubu arşive taşımaz.

Grafik operasyonu olmayan siparişler grafik iş listesine alınmaz.

## Group and Order Sorting

Aktif liste:

- mevcut canonical queue mantığı korunarak grup seviyesinde filtre uygulandı

Tamamlananlar:

- en son terminal tarih üstte olacak şekilde türetilmiş tamamlanma tarihine göre sıralandı

Grup içi satır sırası:

- order item
- print sequence
- graphic id

## Pagination Query

Sayfalama artık grafik satırına değil sipariş grubuna göre çalışır.

Kural:

- varsayılan `per_page=10`
- whitelist: `10`, `20`, `50`
- aynı `order_id` farklı sayfalara bölünmez

Pagination birimi:

- sipariş grubu

## Query Preservation

Sayfalamada query korunur:

- `queue`
- `q`
- `status`
- `approval_status`
- `customer_visible_visual`
- `per_page`

Kullanım:

- `withQueryString()` tabanlı mevcut Laravel paginator akışı

## Pastel Palette

Sipariş kartları için yalnız görsel ayırıcı pastel sınıflar eklendi:

- `pd-graphic-order-group--blue`
- `pd-graphic-order-group--green`
- `pd-graphic-order-group--sand`
- `pd-graphic-order-group--lavender`

Atama:

- stabil `order_id % 4`

Bu renklerin status anlamı yoktur. Status yalnız badge ile ifade edilir.

## UI Davranışı

Yeni görünüm:

- sipariş bazlı pastel kartlar
- kart içinde exact operasyon satırları
- aktif satırlarda tek primary CTA
- tamamlanan gruplarda tek primary CTA: `Kaydı Aç`
- `Tamamlananlar` sekmesi archive-like görünüm olarak açılır
- `per_page` seçici eklendi
- pagination grup sayısına göre özet verir

## Testler

Yeni / güncellenen test kapsamı:

- `GraphicsIndexGroupedArchivePaginationTest`
- `GraphicsIndexUiV1RegressionTest`
- `GraphicListNextActionRegressionTest`
- `GraphicCustomerApprovalUxTest`
- `GraphicPreviewSizeUiTest`

Çalıştırılan kapılar:

- `php artisan view:cache`
- `php artisan test --filter=GraphicsIndexGroupedArchivePaginationTest --stop-on-failure`
- `php artisan test --filter=GraphicsIndexUiV1RegressionTest --stop-on-failure`
- `php artisan test --filter=GraphicListNextActionRegressionTest --stop-on-failure`
- `php artisan test --filter=GraphicCustomerApprovalUxTest --stop-on-failure`
- `php artisan test --filter=GraphicPreviewSizeUiTest --stop-on-failure`
- `php artisan test --filter=GraphicsIndex --stop-on-failure`
- `php artisan test --filter=Graphic --stop-on-failure`
- `php artisan test --filter=PublicGraphicApproval --stop-on-failure`
- `php artisan test --filter=Order --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

Son durum:

- tüm yukarıdaki kapılar PASS

## Değişen Dosyalar

Bu faz kapsamında doğrudan ilgili dosyalar:

- `app/Services/GraphicModuleDataBuilder.php`
- `app/Http/Controllers/Admin/GraphicController.php`
- `resources/views/admin/graphics/index.blade.php`
- `public/css/prodelya-admin.css`
- `tests/Feature/GraphicsIndexGroupedArchivePaginationTest.php`
- `tests/Feature/GraphicsIndexUiV1RegressionTest.php`
- `tests/Feature/GraphicListNextActionRegressionTest.php`
- `tests/Feature/GraphicCustomerApprovalUxTest.php`
- `tests/Feature/GraphicPreviewSizeUiTest.php`

Not:

- worktree’de bu dosyalar dışında çok sayıda önceki faz değişikliği de bulunuyor
- bu rapor yalnız grouped graphics archive/pagination kapsamını ayırır

## Worktree / Staging / Commit

Durum:

- worktree kirli
- bu fazda yalnız ilgili graphics hunk’ları geliştirildi
- staging yapılmadı
- commit yapılmadı

## Manuel Smoke

Bu fazın manuel smoke kapısı henüz kullanıcı onayı bekler.

Kontrol edilmesi gerekenler:

- aynı siparişe ait exact grafik satırlarının tek kart altında görünmesi
- kısmen tamamlanan grubun aktif listede kalması
- tümü terminal grupların `Tamamlananlar` sekmesine gitmesi
- `approved` tek başına arşivlememesi
- pagination’ın 10 grup bazında çalışması
- aynı siparişin farklı sayfaya bölünmemesi
- filtrelerin sayfa değişiminde korunması

## Sonuç

READY — GRAPHICS ORDER-GROUPED ARCHIVE AND PAGINATION — MANUAL SMOKE REQUIRED

## 10.20.8 Turkish Pagination Labels Hotfix

Bu dar hotfixte yalnız `/admin/graphics` paginator kullanıcı-facing etiketleri Türkçeleştirildi.

Uygulanan davranış:

- `Previous` yerine `Geri`
- `Next` yerine `İleri`
- sayfa numaraları korundu
- disabled state korundu
- query string korundu
- grup bazlı pagination, archive ve `per_page` davranışı değişmedi

Dokunulan dar alanlar:

- `resources/views/admin/graphics/index.blade.php`
- `resources/views/vendor/pagination/graphics-turkish.blade.php`
- `tests/Feature/GraphicsIndexGroupedArchivePaginationTest.php`

Bu hotfix sonrası çalıştırılan kapılar:

- `php artisan view:clear`
- `php artisan view:cache`
- `php artisan test --filter=GraphicsIndexGroupedArchivePaginationTest --stop-on-failure`
- `php artisan test --filter=Graphic --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

Sonuç:

- hedefli Turkish pagination etiketi testi PASS
- broad `Graphic` PASS
- `AdminSmokeTest` PASS

Final durum:

MANUAL PASS — GRAPHICS ORDER-GROUPED ARCHIVE AND PAGINATION
