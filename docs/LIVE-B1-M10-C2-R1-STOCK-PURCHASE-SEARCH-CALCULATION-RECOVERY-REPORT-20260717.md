# LIVE-B1-M10-C2-R1 STOCK PURCHASE SEARCH AND CALCULATION RECOVERY REPORT - 2026-07-17

## Scope
- Fixed only the remaining simple stock purchase form blockers.
- No schema change.
- No new stock engine.
- No staging.
- No commit.

## Root Cause
- Exact variant preselection hydration was still broken in StockPurchaseController.
- findCandidateBySelectionKey() loaded catalogProduct but still tried to read $variant->product, so ?variant= preselection could fall through and leave the row empty.
- create() did not use the direct-request fallback resolver, so canonical row hydration could still start with an empty candidate.
- The stock purchase create page already contained the single-row calculator and canonical search UI contract, but they depended on the selected candidate state being hydrated correctly.

## Implemented Recovery
- app/Http/Controllers/Admin/StockPurchaseController.php
  - create() now falls back from canonical selection-key resolution to direct requested candidate resolution.
  - exact variant candidate resolution now uses catalogProduct consistently.
  - canonical /admin/catalog/search reuse remains intact through admin.stock-purchases.search.
- resources/views/admin/stock-purchases/create.blade.php
  - retained single Ürün / Varyant cell structure.
  - retained canonical search endpoint binding.
  - retained row-level recalculateRow(row) contract for quantity / list / discount / final unit changes.
  - retained opening-stock finance hide/show behavior.

## Verified Contracts
- Canonical search endpoint is still route('admin.stock-purchases.search') and reuses catalog search DTO output.
- Duplicate legacy Ürün Adı column is not present in the Blade template.
- Purchase calculation contract in UI script remains:
  - calculated unit = list_price * (1 - discount / 100)
  - line total = quantity * final_unit_price
- Manual override contract remains checkbox-free:
  - direct edit on Alış Birim Fiyatı sets manual state.

## Automated Proof
- php artisan test --filter=test_stock_purchase_create_supports_exact_variant_preselection --stop-on-failure: PASS
- php artisan test --filter=StockPurchase --stop-on-failure: PASS
- php artisan test --filter=CatalogFastStockFlowTest --stop-on-failure: PASS
- php artisan test --filter=TenantCatalog --stop-on-failure: PASS
- php artisan test --filter=CatalogSearch --stop-on-failure: PASS
- php artisan test --filter=Stock --stop-on-failure: PASS
- php artisan test --filter=CurrentAccount --stop-on-failure: PASS
- php artisan test --filter=PromotionQuote --stop-on-failure: PASS
- php artisan test --filter=AdminSmokeTest --stop-on-failure: PASS
- php artisan view:cache: PASS

## Manual Smoke Gate
- Status: MANUAL SMOKE REQUIRED
- Route:
  - /admin/stock/purchases/create?variant=32244
- Expected browser checks:
  - canonical product search works
  - duplicate product-name column absent
  - local stock, supplier stock and list price visible in search/selection
  - 224 / 54 / 100 -> 103,04 / 10.304,00
  - opening stock hides finance fields
  - no 404/405/500 and no JS console error

## Final
RECOVERED - SIMPLE STOCK PURCHASE SEARCH AND CALCULATION - MANUAL SMOKE REQUIRED
## R2 Hotfix Addendum (2026-07-17)
- Opening stock now persists non-financial zero snapshot values.
- Opening stock movement unit cost no longer mirrors catalog list price.
- Opening stock detail now hides purchase/cari-only sections.
- User-facing movement labels are mapped to Turkish.
- Manual browser smoke is still required for final closure.
