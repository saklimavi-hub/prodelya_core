# LIVE-B1-M10 TEMPLATE-A — Local Product Surfaces Report
Date: 2026-07-17
Status: IMPLEMENTED — LOCAL PRODUCT PRODUCTION TEMPLATES AND SHARED FIELD CONTRACT — MANUAL SMOKE REQUIRED

## Scope
This phase implemented only the approved Local Product production surfaces:
- T1 product detail compact gallery template
- T2 own list + supplier stock list
- T3 create/edit shared form template + field catalog + image upload/image URL
- T4 CSV import template using the same shared field catalog

No staging or commit was performed.
No stock/reservation/procurement/quote pricing behavior was intentionally changed.

## Implemented
- Added `app/Services/TenantCatalog/LocalProductFieldCatalogService.php` as the shared label and CSV alias contract.
- Adapted `resources/views/admin/catalog/show.blade.php` to the approved compact gallery-oriented product detail surface.
- Adapted `resources/views/admin/catalog/local-products-index.blade.php` to the approved own-product list family.
- Adapted `resources/views/admin/catalog/local-products-supplier-stock.blade.php` to the approved supplier-local stock list family.
- Adapted `resources/views/admin/catalog/local-products-create.blade.php` and `resources/views/admin/catalog/partials/_local-product-form.blade.php` to the approved create/edit surface with shared fields.
- Adapted `resources/views/admin/catalog/local-products-import.blade.php` to the approved CSV-only import surface.
- Updated `resources/views/admin/catalog/partials/_local-products-subnav.blade.php` to keep one consistent local-product subnavigation.
- Added narrow `pd-local-product-*` and `pd-catalog-detail-*` styles in `public/css/prodelya-admin.css`.
- Extended `app/Http/Controllers/Admin/TenantCatalogController.php` to support:
  - shared CSV template headers from the field catalog service
  - safe `http/https` image URL validation
  - JPG/PNG/WEBP upload to tenant-scoped public storage
  - upload priority over URL
  - safe owned-file cleanup on replacement/removal
  - tenant-safe product image relation sync for local product uploads

## Targeted Tests
Passed:
- `TenantCatalogProductDetailTemplateTest`
- `LocalProductsTemplateContractTest`
- `SupplierLocalStockTemplateContractTest`
- `LocalProductCreateEditFieldParityTest`
- `LocalProductImageUploadTest`
- `LocalProductImportTemplateContractTest`
- `TenantAdvancedCatalogTest`

## Regression Locks
Passed:
- `PromotionQuoteWorkspaceJavascriptContractTest`
- `PromotionQuoteCompactLocalStockLabelTest`
- `PromotionQuoteMetadataHydrationParityTest`

## Broad Gates
Passed:
- `TenantCatalog`
- `CatalogSearch`
- `Stock`
- `LocalProducts`
- `PromotionQuote`
- `AdminSmokeTest`
- `php artisan view:cache`

## Manual Smoke
Pending:
- Product detail compact gallery and sticky summary
- Own list / supplier-local list visual parity
- Create/edit upload + URL preview and save flow
- Import CSV-only wording and preview flow

## Notes
- Import UI no longer presents unsupported spreadsheet/XML capabilities.
- Existing CSV aliases are preserved through the shared field catalog service.
- The accepted Promotion Quote compact metadata regression lock remains intact.

## Manual PASS Addendum
- 2026-07-17 itibarıyla Promotion Quote workspace ve compact stock metadata görünümü kullanıcı tarafından MANUAL PASS kabul edildi.
- Bu görünüm regression lock olarak korunmalıdır; local product visual fidelity çalışmaları bu kabul edilmiş yüzeyi değiştirmemelidir.
