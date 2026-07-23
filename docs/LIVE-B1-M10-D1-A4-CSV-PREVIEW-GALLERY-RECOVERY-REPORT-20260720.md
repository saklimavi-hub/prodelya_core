# LIVE-B1-M10-D1-A4 CSV PREVIEW GALLERY RECOVERY REPORT - 2026-07-20

## Status
RECOVERED - LOCAL PRODUCT CSV PREVIEW AND GALLERY - MANUAL SMOKE REQUIRED

## Scope
- `POST /admin/catalog/local-products/import/preview` CSV preview parser 500 recovery
- `GET /admin/catalog/local-products/{product}` local product detail gallery recovery
- No schema, no Product Hub, no stock/purchase, no quote/order business logic changes
- No staging or commit

## CSV Root Cause
- Failing code path was `app/Services/TenantCatalog/TenantLocalProductCsvImportService.php`
- Previous parser used:
  - `fgetcsv(..., ',')`
  - `array_combine($headers, array_pad($data, count($headers), null))`
- `array_pad()` only pads missing cells; it does not truncate rows with more cells than headers.
- When a row had more parsed cells than the header row, `array_combine()` could throw `ValueError`.

## D1 CSV Audit
Exact D1 smoke file audited:
- File: `.tmp/D1-SMOKE-20260720061347.csv`
- Delimiter: comma
- Header count: 13
- Row 2 count: 13
- Row 3 count: 13
- Row 4 count: 13
- Result: current D1 smoke file itself is structurally valid

Conclusion:
- The blocker was a parser safety gap, not a proven malformed D1 CSV row.
- Parser now safely handles valid D1 CSV plus malformed extra-column edge cases without 500.

## CSV Recovery
Implemented in `TenantLocalProductCsvImportService`:
- Delimiter detection supports comma, semicolon and tab
- UTF-8 BOM stripped from first header cell
- Blank rows skipped
- Missing cells padded with null
- Empty trailing extra cells trimmed
- Extra non-empty cells become row-level validation errors
- Duplicate or empty headers become preview errors, not 500
- Preview and apply keep the same normalized preview payload contract

UI recovery in `resources/views/admin/catalog/local-products-import.blade.php`:
- Preview shows parser delimiter badge
- Row-level error text rendered in preview table
- Explicit Apply disabled when preview errors exist
- Preview remains no-write

## Gallery Root Cause
Read-only audit for local product `18277` showed:
- `image_url`: `http://localhost/storage/tenants/2/catalog/products/18277/...png`
- `primary_image`: same localhost storage URL
- tenant attribute gallery image: same localhost storage URL
- image record: same localhost storage URL

Conclusion:
- Browser on tenant host receives an absolute `http://localhost/storage/...` image source.
- This produces a broken image state in real tenant browsing.
- Broken `<img>` and fallback were visible together because the DOM contract did not hide the failed image cleanly.

## Gallery Recovery
Implemented in `resources/views/admin/catalog/local-products/show.blade.php`:
- Localhost storage URLs normalize to safe relative `/storage/...` paths at render time
- No DB image rewrite
- Main image uses hidden-first contract
- Main image fallback is a single clean placeholder
- Thumbnail images use hidden-first contract with dedicated 54x54 fallback
- Duplicate gallery URLs are deduplicated before rendering
- Scoped visual layer only inside local-product detail view
- No physical path leak

## Files Changed
- `app/Services/TenantCatalog/TenantLocalProductCsvImportService.php`
- `resources/views/admin/catalog/local-products-import.blade.php`
- `resources/views/admin/catalog/local-products/show.blade.php`
- `tests/Feature/LocalProductCsvPreviewParsingTest.php`
- `tests/Feature/LocalProductDetailGalleryTest.php`

## Tests
Passed:
- `php artisan test --filter=LocalProductCsvPreviewParsingTest --stop-on-failure`
- `php artisan test --filter=LocalProductDetailGalleryTest --stop-on-failure`
- `php artisan test --filter=LocalProduct --stop-on-failure`
- `php artisan test --filter=TenantCatalog --stop-on-failure`
- `php artisan test --filter=CatalogSearch --stop-on-failure`
- `php artisan test --filter=StockPurchase --stop-on-failure`
- `php artisan test --filter=PromotionQuote --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`
- `php artisan view:cache`

## Manual Smoke Needed
CSV:
- Upload valid D1 CSV
- Confirm preview 200
- Confirm 3 rows visible
- Confirm no DB write on preview
- Confirm malformed extra-column CSV shows row error and disabled apply

Gallery:
- Open local product detail on tenant host
- Confirm valid image shows inside 330px contain area
- Confirm invalid image shows only clean placeholder
- Confirm no broken image icon or alt text visible
- Confirm thumbnails are 54x54 and clean

## Worktree / Git
- No staging
- No commit
- Unrelated dirty worktree untouched

## Note
- `docs/LIVE-B1-M10-D1-A-OWN-PRODUCTS-MANUAL-SMOKE-ACCEPTANCE-REPORT-20260717.md` does not currently exist in the repo, so it was not updated.

## Final
RECOVERED - LOCAL PRODUCT CSV PREVIEW AND GALLERY - MANUAL SMOKE REQUIRED
