# LIVE-B1-M10-D1 OWN PRODUCTS EXACT VARIANT CREATE / EDIT / CSV REPORT - 2026-07-17

## Status
READY - OWN PRODUCTS EXACT VARIANT CREATE / EDIT / CSV IMPORT - MANUAL SMOKE REQUIRED

## Audit
- `admin/catalog/local-products` yüzeyi artık dedicated `LocalProductController` üzerinden çalışıyor.
- `admin/catalog/local-products/import*` yüzeyi dedicated `LocalProductImportController` üzerinden çalışıyor.
- Own product detail için dedicated `resources/views/admin/catalog/local-products/show.blade.php` kullanılıyor.
- Generic `/admin/catalog/{product}` ve `/admin/catalog/{product}/variants/{variant}` istekleri own product ise dedicated local-product detail view'a delegate ediliyor.
- Parent/group ürünler create/edit/import sözleşmesinde sellable exact item değil; varyantlı ürünlerde exact truth `tenant_catalog_product_variants` düzeyinde kalıyor.

## Controller / View Split
- Eklendi:
  - `app/Http/Controllers/Admin/LocalProductController.php`
  - `app/Http/Controllers/Admin/LocalProductImportController.php`
  - `app/Services/TenantCatalog/TenantLocalProductWriteService.php`
  - `app/Services/TenantCatalog/TenantLocalProductCsvImportService.php`
  - `resources/views/admin/catalog/local-products/show.blade.php`
- Route ownership:
  - `admin.catalog.local-products*` -> `LocalProductController`
  - `admin.catalog.local-products.import*` -> `LocalProductImportController`
- `TenantCatalogController` içinden route almayan local-product create/store/update/import public metotları kaldırıldı.
- Generic catalog detail flow, own product için dedicated local-product detail view'a route-level delegation ile ayrıldı.

## Flat / Variant Identity
- Flat own product:
  - `tenant_catalog_product`
  - sellable exact item
  - product-level stock scope
- Variant own product:
  - parent/group `tenant_catalog_product`
  - exact sellable rows `tenant_catalog_product_variants`
  - varyant SKU, fiyat, görünürlük, görsel ve stok exact row üzerinde
- Parent/group row quote-search veya stock-entry hedefi olarak kullanılmıyor.

## Create / Edit
- Shared field catalog form contract korundu.
- Flat ve varyantlı ürün tipi tek form içinde seçilebiliyor.
- Edit ekranında stock overwrite yok:
  - read-only stock summary
  - `Stok Girişi / Satın Alma` linki
- Create ve update sonrası kullanıcı canonical `Kendi Ürünlerim` listesine dönüyor.

## SKU Normalization
- Write service tenant içi SKU uniqueness guard uyguluyor.
- Flat exact SKU ve variant exact SKU aynı canonical write service içinde doğrulanıyor.
- Duplicate exact SKU import/create seviyesinde bloklanıyor.

## Initial Stock
- Optional initial stock create/import sırasında canonical opening-stock service üzerinden işleniyor.
- Parent/group için stock row açılmıyor.
- Edit ekranında stock input ile overwrite yapılmıyor.

## Image Fallback
- Ana ürün görseli ve varyant görseli destekleniyor.
- Variant image yoksa parent/main image fallback korunuyor.
- Public disk upload sözleşmesi ve existing image URL preservation korunuyor.

## CSV Preview / Apply
- CSV-only contract korundu.
- Preview write yapmıyor.
- Apply explicit onay ile çalışıyor.
- `group_code` parent oluşturuyor; her satır exact variant ya da flat item üretiyor.
- Shared field catalog import labels import ekranında kullanılıyor.

## Catalog / Quote Integration
- Own product exact rows mevcut catalog / quote identity zinciriyle uyumlu kalıyor.
- Dedicated local-product detail route eklense de route name sözleşmeleri korunuyor.
- Generic catalog detail own product isteklerinde yanlış yüzey yerine dedicated local-product view kullanılıyor.

## Tests
- Passed:
  - `php artisan test --filter=LocalProduct --stop-on-failure`
  - `php artisan test --filter=TenantCatalog --stop-on-failure`
  - `php artisan test --filter=CatalogSearch --stop-on-failure`
  - `php artisan test --filter=StockPurchase --stop-on-failure`
  - `php artisan test --filter=Stock --stop-on-failure`
  - `php artisan test --filter=PromotionQuote --stop-on-failure`
  - `php artisan test --filter=AdminMenu --stop-on-failure`
  - `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - `php artisan view:cache`
- Yeni dedicated split coverage:
  - `LocalProductRoutesUseDedicatedControllersTest`
  - `TenantCatalogControllerDoesNotOwnLocalProductWritesTest`
  - `LocalProductDetailUsesDedicatedViewTest`

## Manual Smoke
- Henüz bu fazda yapılmadı.
- Beklenen checklist:
  - flat create
  - variant group create
  - edit without stock overwrite
  - CSV preview no-write
  - CSV apply exact parent/variant creation
  - no 404/405/500

## Remaining Mixed Worktree
- Worktree genel olarak hâlâ geniş ve kirli.
- Bu fazda staging / commit yapılmadı.
- Unrelated dirty worktree alanlarına dokunulmadı.
- `resources/views/admin/catalog/show.blade.php` generic catalog yüzeyi olarak kaldı; own product istekleri artık dedicated local-product detail view'a düşüyor.

## Final
READY - OWN PRODUCTS EXACT VARIANT CREATE / EDIT / CSV IMPORT - MANUAL SMOKE REQUIRED

---

## Addendum - 2026-07-20 CSV Preview and Gallery Recovery
- `POST /admin/catalog/local-products/import/preview` için parser safety gap kapatıldı.
- Delimiter detection artık comma / semicolon / tab destekliyor.
- UTF-8 BOM, blank row, missing-cell pad, empty trailing extra-cell trim ve extra non-empty column row-error sözleşmesi eklendi.
- Preview no-write kontratı korundu; preview error varsa explicit apply disable oldu.
- Local product detail gallery için `localhost/storage/...` kaynakları safe relative `/storage/...` render path'ine normalize edildi.
- Broken image ile fallback aynı anda görünmeyecek hidden-first DOM kontratı eklendi.
- Thumbnails 54x54 fallback kontratına taşındı ve duplicate görseller dedupe edildi.
- New tests:
  - `LocalProductCsvPreviewParsingTest`
  - `LocalProductDetailGalleryTest`
- Passed gates:
  - `php artisan test --filter=LocalProductCsvPreviewParsingTest --stop-on-failure`
  - `php artisan test --filter=LocalProductDetailGalleryTest --stop-on-failure`
  - `php artisan test --filter=LocalProduct --stop-on-failure`
  - `php artisan test --filter=TenantCatalog --stop-on-failure`
  - `php artisan test --filter=CatalogSearch --stop-on-failure`
  - `php artisan test --filter=StockPurchase --stop-on-failure`
  - `php artisan test --filter=PromotionQuote --stop-on-failure`
  - `php artisan test --filter=AdminSmokeTest --stop-on-failure`
  - `php artisan view:cache`
- Manual smoke for CSV preview and product detail gallery remains required.

## Addendum - 2026-07-20 A7 Manual Acceptance Status
- Final A7 browser/manual acceptance could not be completed in this run because the required tenant-admin browser credential was unavailable to the safe Playwright acceptance script.
- Verified blocker evidence:
  - tenant acceptance script exists: `.tmp/d1_own_products_acceptance_smoke.cjs`
  - environment preflight: `D1_TENANT_ADMIN_PASSWORD=False`
- No application/controller/view/service/test change was made in this acceptance-only turn.
- Status moved from MANUAL SMOKE REQUIRED to exact blocker attribution:
  - `BLOCKED - tenant browser auth credential unavailable for A7 manual acceptance`
