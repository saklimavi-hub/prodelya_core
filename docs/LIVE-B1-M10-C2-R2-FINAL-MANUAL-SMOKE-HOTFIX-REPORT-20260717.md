# LIVE-B1-M10-C2-R2 FINAL MANUAL SMOKE HOTFIX REPORT - 2026-07-17

## Scope
- Applied only the final manual-smoke hotfixes for the simple Stock Entry / Purchase flow.
- No new architecture.
- No schema change.
- No new module.
- No staging.
- No commit.

## Root Cause
- Opening stock still reused purchase-facing snapshot fields and movement cost output.
- Opening stock detail view still rendered purchase/cari sections with placeholder dashes.
- Technical labels such as `Stock movement`, `adjustment`, and `LOCAL-MAIN` leaked directly into the user-facing detail surface.

## Implemented
- `app/Services/TenantCatalog/CatalogFastStockActionService.php`
  - `priceSnapshot()` now branches by `entry_type`.
  - `existing_stock` now persists a non-financial zero snapshot.
  - opening stock movement `unit_cost` is now written as non-financial `null/0` behavior instead of catalog list cost.
- `resources/views/admin/stock-purchases/show.blade.php`
  - opening stock detail now hides purchase-only finance fields.
  - opening stock detail now hides the cari section completely.
  - user-facing movement labels are mapped to Turkish.
  - warehouse label now renders `Ana Depo · LOCAL-MAIN`.
- `tests/Feature/StockPurchaseFlowTest.php`
  - added opening-stock no-price / no-unit-cost coverage.
  - added opening-stock detail hide-finance / hide-cari coverage.
  - added Turkish movement-label coverage.
  - added `224 / 54 / 100 -> 103.04 / 10304.00` purchase fixture proof.
  - added opening-stock cancel proof.
  - added quantity=1 purchase create/cancel proof.

## Automated Proof
- `php artisan test --filter=StockPurchase --stop-on-failure`: PASS
- `php artisan test --filter=CatalogFastStockFlowTest --stop-on-failure`: PASS
- `php artisan test --filter=TenantCatalog --stop-on-failure`: PASS
- `php artisan test --filter=Stock --stop-on-failure`: PASS
- `php artisan test --filter=CurrentAccount --stop-on-failure`: PASS
- `php artisan test --filter=PromotionQuote --stop-on-failure`: PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS
- `php artisan view:cache`: PASS

## Manual Status
- Status: MANUAL PASS
- Confirmed browser proofs:
  - exact dropdown selection hydrates row and triggers calculation on /admin/stock/purchases/create?variant=32244
  - 224 / 54 / 100 visibly shows 103,04 and 10.304,00
  - opening stock detail no longer shows finance/cari sections
  - cancel/reversal browser proof completes cleanly

## Final
MANUAL PASS - CLOSED - SIMPLE STOCK ENTRY / PURCHASE FLOW

## R3 Supplier Debit + Procurement CTA Addendum (2026-07-17)
- Opening-stock hotfix remains intact.
- Additional recovery fixed completed-purchase supplier debit atomicity and catalog procurement CTA routing/prefill.
- Manual browser proof for record 10 cancellation and new quantity=1 purchase/reversal is confirmed PASS in the final closeout.
