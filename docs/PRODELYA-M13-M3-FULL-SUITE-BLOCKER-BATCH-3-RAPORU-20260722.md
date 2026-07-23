# PRODELYA M13-M3 Full-Suite Blocker Batch 3 Raporu

Tarih: 2026-07-22

Durum: READY -- FULL-SUITE BLOCKER BATCH 3 CLEARED -- TENANT ADVANCED CATALOG REGRESSIONS REMAIN

## Kapsam

- Permanent category selection ve archived/pasif kategori dışlama korundu.
- Local ürün ekranında aktif kalıcı kategori seçimi ve CSV Import görünürlüğü sağlandı.
- Product Hub günlük dilinde Abone Firma / ön kontrol / otomatik yansıma terminolojisi güncellendi.
- Tedarikçi kaynak akışı sekiz adımlı hale getirildi: Kaynak Bilgileri, Bağlantı / Dosya Kontrolü, Alan Eşleme, Kategori Eşleme, Örnek Ürün Ön Kontrolü, Ürünleri Senkronize Et, Otomatik Güncelleme Ayarları, Bekleyen Kontroller.
- Kaynak kartlarında tek ana aksiyon korunarak otomatik katalog mesajı netleştirildi.

## Korunan Sınırlar

- TenantAdvancedCatalog kodu/testleri değiştirilmedi.
- Schema, global CSS, M14, staging ve commit yapılmadı.
- Archived kategori seçim ve write path üzerinden reddediliyor.
- Kaynak gizliliği korunuyor; liste kartları tam URL/path veya secret göstermiyor.

## Test Sonuçları

- Targeted Batch 3:
  - `php artisan test tests\Feature\PermanentCategoryBackboneLockTest.php tests\Feature\ProductDataHubFinalUiCleanupTest.php tests\Feature\ProductHubFinalUiTerminologyRadiusTest.php tests\Feature\ProductHubSupplierFlowStepperTest.php tests\Feature\ProductHubTemplateCleanupTest.php`
  - Sonuç: 21 passed, 147 assertions.
- Broad filter:
  - `php artisan test --filter='ProductHub|ProductDataHub|Category|SupplierFlow|TenantCatalog|PromotionQuote|Procurement|AdminSmokeTest'`
  - Sonuç: 791 passed, 6396 assertions.
- Full suite JUnit:
  - `php artisan test --log-junit .tmp\m13-m3-full-suite.xml`
  - Sonuç: 2213 tests, 2211 passed, 2 failed, 0 errors.

## Kalan Full-Suite Failures

- `Tests\Feature\TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product`
  - Beklenen `stock_quantity=15`, mevcut `stock_quantity=50`.
- `Tests\Feature\TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price`
  - `tenant_supplier_purchase_entries` tablosunda beklenen satın alma kaydı oluşmuyor.

Bu iki failure Batch 3 kapsamı dışında bırakıldı ve prompttaki beklenen kalan TenantAdvancedCatalog regresyonlarıyla uyumlu.

## Git Durumu

- Staged area kontrol edildi: boş.
- Commit, tag, reset, restore, stash veya clean yapılmadı.
