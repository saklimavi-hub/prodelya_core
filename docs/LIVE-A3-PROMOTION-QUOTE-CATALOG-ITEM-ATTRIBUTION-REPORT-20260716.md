# LIVE-A3 Promotion Quote Catalog Item Attribution Report — 2026-07-16

## 1. Executive result
- Status: RECOVERED IN CODE/TEST, manual browser resmoke still pending.
- P0 root cause 1: `resources/views/admin/promotion-quotes/_form-workspace.blade.php` içinde `collectItems()` DOM'dan okuduğu `product_snapshot` alanını normalized item state'e geri taşımıyordu.
- P0 root cause 2: aynı Blade dosyasında `normalizeItem()` fallback `selected_catalog_identity` nesnesi eski çalışan checkpoint (`8eafa19`, `b8729d5`) sözleşmesinden sapmıştı.
- Historical comparison: hidden field name contract eski checkpointlerle aynı kaldı; kayıp render değil, hydration/remount zincirindeydi.

## 2. Exact backend error branch
- File: `app/Http/Controllers/Admin/PromotionQuoteController.php`
- Method: `resolveCatalogItemPayload()`
- Exact reject branches:
  - malformed `product_snapshot` -> `items.{n}.product_snapshot`
  - catalog identity var ama çözülen tenant catalog product/variant yok -> `items.{n}.product_snapshot`
  - resolved snapshot'ta `product_name` boş -> `items.{n}.product_snapshot`
- User-facing message: `Seçilen ürün bilgisi eksik kaldı. Lütfen ürünü katalogdan yeniden seçin.`

## 3. Historical checkpoint comparison
- Read-only checkpoints used:
  - `8eafa19`
  - `b8729d5`
- Findings:
  - hidden input names (`tenant_catalog_product_id`, `tenant_catalog_product_variant_id`, `selected_catalog_identity`, `product_snapshot`, `price_snapshot`, `stock_snapshot`) aynı kaldı.
  - current drift, item state rebuild sırasında oluştu.
  - old `normalizeItem()` fallback block, `warning_tone` / `warning_summary` taşıyor ve `_row_error` / `_error_path` top-level kalıyordu.

## 4. Targeted fix
- Blade:
  - `collectItems()` artık `product_snapshot` değerini normalize zincirine geri taşıyor.
  - `normalizeItem()` fallback `selected_catalog_identity` eski çalışan sözleşmeyle hizalandı.
  - `_error_path` top-level korunuyor.
- Controller:
  - quote-hidden / variant-hidden / supplier-access-closed catalog item save reject guard eklendi.
  - access enforcement, tenant düzeyinde supplier access kuralı varsa çalışıyor; rule yoksa search/live-info davranışıyla uyumlu allow kalıyor.

## 5. Targeted tests
- Added:
  - `tests/Feature/PromotionQuoteCatalogItemAttributionTest.php`
- Existing targeted suites passing:
  - `PromotionQuoteCatalogItemAttribution`
  - `PromotionQuoteFormOwnership`
  - `PromotionQuoteItemSubmissionContract`
  - `PromotionQuoteValidationRerenderItems`
  - `PromotionQuoteExactVariantSnapshotAttribution`
  - `PromotionQuoteStalePriceSaveGuard`

## 6. Broad tests
- PASS:
  - `PromotionQuote`
  - `ProductDataHub`
  - `TenantCatalog`
  - `CatalogSearch`
- FAIL (separate attribution, not caused by current quote attribution fix):
  - `Order` broad -> `OrderShowFinanceCardRegressionTest::test_order_show_keeps_short_finance_card_and_finance_summary_link`
- FAIL (legacy smoke fixture drift still open):
  - `AdminSmokeTest::test_promotion_quote_can_store_catalog_item_snapshots`

## 7. Browser / snapshot status
- Chrome automation attempt failed locally because Codex Chrome bridge startup hit Windows ACL sandbox error before session bootstrap.
- Therefore exact browser FormData table, ET-0506-S save resmoke, EL-KOD-35 / PZ-CH60SY read-only snapshot compare were not auto-verified in this run.
- Manual/browser gate remains pending.

## 8. Temp cleanup
- Removed: `.tmp/live_a3`
- Did not stage or commit.

## 9. Live quote gate
- Code/test status: ready for manual browser resmoke.
- Gate: NOT OPEN yet, because browser save + snapshot verification is still pending.
