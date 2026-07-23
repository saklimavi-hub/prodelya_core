# LIVE-B1-M10-C2-R3 SUPPLIER DEBIT AND PROCUREMENT CTA RECOVERY REPORT - 2026-07-17

## Scope
- Fixed only the remaining simple stock P1 blockers.
- No schema change.
- No new stock / procurement / current-account engine.
- No staging.
- No commit.

## Proven Root Cause
- Completed stock purchase could persist exact stock and stock movement without supplier debit because supplier identity fell to `null` on associative `source_summary` payloads.
- Record 10 proof before fix:
  - `tenant_supplier_purchase_entries.id = 10`
  - `supplier_id = null`
  - `purchase_total_try = 10080.0000`
  - linked `current_account_transactions = 0`
- Catalog `Tedarik Süreci Başlat` CTA used the same brittle supplier extraction pattern and therefore rendered a dead / incomplete create link in exact variant rows.

## Implemented
- `app/Services/TenantCatalog/CatalogFastStockActionService.php`
  - normalized list vs associative `source_summary` handling.
  - completed purchase now requires safe supplier identity.
  - completed purchase now requires positive `purchase_total_try`.
  - supplier debit now reuses canonical supplier current-account semantics via `CurrentAccountSyncService::ensureForSupplier()` and supplier link lookup, not name matching.
  - completed purchase now throws actionable validation and rolls back if supplier debit cannot be created.
- `app/Services/TenantCatalog/TenantCatalogListRowQueryService.php`
  - added exact `catalog_row_supplier_id` hydration from row/source summary truth.
- `resources/views/admin/catalog/index.blade.php`
  - `Tedarik Süreci Başlat` now uses canonical create route with:
    - `tenant_catalog_product_id`
    - `tenant_catalog_product_variant_id`
    - `supplier_id`
    - `requested_quantity`
    - `source=catalog`
  - dead CTA replaced with explicit disabled state only when supplier identity is missing.
- `app/Http/Controllers/Admin/SupplierProcurementRequestController.php`
  - create screen now accepts catalog prefill query contract.
  - exact catalog selection is validated tenant-safely.
  - visible prefill card is passed to the create view.
- `resources/views/admin/procurements/supplier-requests/create.blade.php`
  - added visible catalog-origin prefill card.
- `resources/views/admin/stock-purchases/show.blade.php`
  - purchase detail now shows source-linked supplier debit rows with Turkish labels and `Cari Kaydını Aç`.
  - active purchase with zero linked debit now shows `Cari kaydı eksik` warning.
- `tests/Feature/StockPurchaseFlowTest.php`
  - added rollback-on-debit-failure coverage.
  - added linked debit detail coverage.
- `tests/Feature/CatalogProcurementStartActionTest.php`
  - added working route / visible prefill / no stock write / no debit write / permission guard coverage.

## Automated Proof
- `php artisan test --filter=StockPurchase --stop-on-failure`: PASS
- `php artisan test --filter=CatalogProcurementStart --stop-on-failure`: PASS
- `php artisan test --filter=CatalogFastStockFlowTest --stop-on-failure`: PASS
- `php artisan test --filter=Procurement --stop-on-failure`: PASS
- `php artisan test --filter=CurrentAccount --stop-on-failure`: PASS
- `php artisan test --filter=TenantCatalog --stop-on-failure`: PASS
- `php artisan test --filter=Stock --stop-on-failure`: PASS
- `php artisan test --filter=PromotionQuote --stop-on-failure`: PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS
- `php artisan view:cache`: PASS

## Manual Status
- User-confirmed manual browser smoke: PASS.
- Purchase/reversal and procurement CTA browser proof: PASS.
- Simple stock entry / purchase flow is manually validated and closed.

## Final Status
MANUAL PASS - CLOSED - SIMPLE STOCK ENTRY / PURCHASE FLOW
