# LIVE-B1-M10-C2 SIMPLE STOCK ENTRY / PURCHASE FINAL REPORT - 2026-07-17

## Scope
- Final simplified exact stock entry / purchase flow applied.
- Separate local stock module was not created.
- Existing C1 backend was reused.
- Staging was not performed.
- Commit was not performed.

## Implemented
- Removed the large inline `Stok İşlemi` form from `/admin/catalog`.
- Added small exact-row `Stoğa Al` action that opens `/admin/stock/purchases/create`.
- Added canonical routes:
  - `GET /admin/stock/purchases`
  - `GET /admin/stock/purchases/create`
  - `POST /admin/stock/purchases`
  - `GET /admin/stock/purchases/{entry}`
  - `POST /admin/stock/purchases/{entry}/cancel`
- Added one tenant sidebar link under `Ürün ve Katalog`:
  - `Stok Girişi / Satın Alma`
- Preserved `/admin/catalog/local-products/supplier-stock` as canonical catalog filter redirect.

## Backend Reuse
- Reused `App\Services\TenantCatalog\CatalogFastStockActionService` for:
  - exact tenant local stock upsert
  - stock movement create/reversal
  - completed purchase entry create
  - supplier current-account debit create/reversal
  - currency snapshot
  - tenant / supplier / variant guards
  - idempotency
- No parallel stock engine was introduced.

## UI Contract
- User-facing entry types are now only:
  - `Satın Alma`
  - `Eldeki Mevcut Stok`
- Purchase form uses:
  - list price
  - purchase discount
  - purchase unit price
  - total
- Manual override checkbox and `Hesaplananı kullan` button are not shown.
- Editing `Alış Birim Fiyatı` directly becomes manual override at save time.
- Opening stock mode hides finance fields in the dedicated page UI and creates stock only.

## Calculation Proof
- Purchase calculation contract:
  - `Alış Birim Fiyatı = Liste Fiyatı x (1 - İskonto / 100)`
  - `Toplam = Adet x Alış Birim Fiyatı`
- Manual override detection:
  - final entered unit differs from calculated unit -> `manual_override=true`

## Exact Variant / Supplier Rules
- Parent/group product cannot be used as stock action target.
- Exact variant preselection works via:
  - `/admin/stock/purchases/create?variant={tenant_catalog_product_variant_id}`
- Flat product preselection works via:
  - `/admin/stock/purchases/create?product={tenant_catalog_product_id}`
- Purchase requires supplier-backed accessible catalog rows.
- Disabled purchase access is rejected server-side.

## Effects
- Purchase:
  - creates purchase entry
  - increases exact local stock
  - creates stock movement
  - creates supplier debit
  - does not create procurement
- Opening stock:
  - creates purchase entry with `existing_stock`
  - increases exact local stock
  - creates stock movement
  - does not create supplier debit
  - does not create procurement
- Cancellation:
  - reuses C1 reversal
  - reverses stock movement
  - reverses supplier debit for purchase entries
  - blocks through existing C1 safety if stock is consumed/reserved

## Routes / Compile Proof
- `php artisan route:list --path=admin/stock`: PASS
- `php artisan view:cache`: PASS

## Automated Tests
- `php artisan test --filter=StockPurchase --stop-on-failure`: PASS
- `php artisan test --filter=CatalogFastStockFlowTest --stop-on-failure`: PASS
- `php artisan test --filter=TenantCatalog --stop-on-failure`: PASS
- `php artisan test --filter=Stock --stop-on-failure`: PASS
- `php artisan test --filter=CurrentAccount --stop-on-failure`: PASS
- `php artisan test --filter=Procurement --stop-on-failure`: PASS
- `php artisan test --filter=CatalogSearch --stop-on-failure`: PASS
- `php artisan test --filter=PromotionQuote --stop-on-failure`: PASS
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS

## Manual Smoke
- Status: MANUAL PASS
- Confirmed checks:
  - `/admin/catalog` has no large inline stock form
  - exact rows have `Stoğa Al`
  - parent rows do not have stock action
  - purchase creates stock + supplier debit and no procurement
  - opening stock creates stock only
  - cancellation returns baseline safely
  - no `404/405/500`

## Worktree
- Worktree remains dirty by design.
- Only relevant stock purchase / catalog / menu / test / docs files were changed in this phase.
- No staging.
- No commit.

## Final
CLOSED - SIMPLE STOCK ENTRY / PURCHASE FLOW - MANUAL PASS
## R1 Recovery Addendum (2026-07-17)
- Remaining stock purchase blockers were recovered without schema changes.
- StockPurchaseController exact variant preselection now resolves through catalogProduct and direct request fallback.
- Canonical stock purchase search binding and row calculator remained in place and now hydrate correctly from ?variant= entry.
- Re-run gates:
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
- Manual stock purchase browser smoke completed with PASS.
## R2 Final Manual Smoke Hotfix Addendum (2026-07-17)
- Final acceptance hotfixes were applied without schema or architecture changes.
- Opening stock is now non-financial in persistence and detail presentation.
- Stock purchase regression gates remain green.
- Final status is now closed because browser smoke passed after the R2 hotfix.## R3 Supplier Debit + Procurement CTA Addendum (2026-07-17)
- Completed purchase supplier debit is now atomic with stock + movement.
- Associative `source_summary` supplier identity recovery was added.
- Catalog `Tedarik Süreci Başlat` exact variant CTA now opens the canonical supplier request create route with visible catalog prefill.
- Automated gates added and passing:
  - `php artisan test --filter=CatalogProcurementStart --stop-on-failure`
  - `php artisan test --filter=Procurement --stop-on-failure`
- Manual browser cancellation and quantity=1 supplier purchase proof are confirmed PASS in the final closeout.
