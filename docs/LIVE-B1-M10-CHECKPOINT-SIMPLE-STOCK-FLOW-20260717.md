# LIVE-B1-M10 CHECKPOINT SIMPLE STOCK FLOW REPORT - 2026-07-17

## Status
BLOCKED - MIXED HUNK REQUIRES MANUAL SPLIT

## Scope
- This phase performed a strict stock-only checkpoint audit.
- No new feature work was started.
- No commit was created.
- No local tag was created.
- No push was performed.

## Preflight
- Branch: `feature/master-restructure-phase-2-order-flow`
- Starting HEAD: `921e483`
- Staged changes before checkpoint work: none
- Backup path: `C:\laragon\www\_prodelya_checkpoints\20260717-simple-stock-flow`
- Local migration status:
  - `2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table` -> Ran
  - `2026_07_16_120100_create_tenant_stock_reservations_table` -> Ran
  - `2026_07_17_120000_add_catalog_fast_stock_fields` -> Ran

## Repo-External Backup
- Created and preserved:
  - `status-before.txt`
  - `tracked-before.patch`
  - `staged-before.patch`
  - `untracked-before.txt`
  - `head-before.txt`
- Backup remains intentionally untouched for later controlled split work.

## Worktree Audit Summary
- The worktree is heavily mixed across stock, catalog, procurement, quote, order, Product Hub, local product template, and docs surfaces.
- `git diff --cached --stat` was empty during this checkpoint audit.
- `git diff --check` reports broad historical formatting noise across unrelated files, so blind staging is unsafe.

## Safe Stock Core
These files were identified as the stock-only core that can be separated safely in a later controlled checkpoint:

| File | Related work | Mixed diff? | Commit group | Safe stage? |
|---|---|---:|---|---:|
| `app/Http/Controllers/Admin/StockPurchaseController.php` | Simple stock entry / purchase UI + save/cancel flow | No | `stock` | Yes |
| `app/Services/TenantCatalog/CatalogFastStockActionService.php` | Canonical purchase/opening stock domain logic | No | `stock` | Yes |
| `app/Services/Stock/TenantLocalStockResolver.php` | Exact variant operational stock truth | No | `stock` | Yes |
| `app/Services/Stock/TenantStockReservationService.php` | Exact reservation / release logic | No | `stock` | Yes |
| `app/Services/Stock/TenantLocalStockPresentationService.php` | Stock provenance presentation helper | No | `stock` | Yes |
| `app/Services/Stock/LocalStockExactVariantCorrectionService.php` | Controlled ET-0506 exact variant correction | No | `stock` | Yes |
| `app/Models/TenantStockReservation.php` | Reservation model | No | `stock` | Yes |
| `app/Models/TenantSupplierPurchaseEntry.php` | Purchase entry snapshot fields | No | `stock` | Yes |
| `app/Models/TenantLocalStock.php` | Variant scope / reservation fields | No | `stock` | Yes |
| `app/Models/StockMovement.php` | User-facing movement mapping support | No | `stock` | Yes |
| `database/migrations/2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table.php` | Variant stock scope schema | No | `stock` | Yes |
| `database/migrations/2026_07_16_120100_create_tenant_stock_reservations_table.php` | Reservation schema | No | `stock` | Yes |
| `database/migrations/2026_07_17_120000_add_catalog_fast_stock_fields.php` | Fast stock / purchase schema | No | `stock` | Yes |
| `resources/views/admin/stock-purchases/create.blade.php` | Stock purchase create UI | No | `stock` | Yes |
| `resources/views/admin/stock-purchases/index.blade.php` | Stock purchase list UI | No | `stock` | Yes |
| `resources/views/admin/stock-purchases/show.blade.php` | Stock purchase detail / reversal UI | No | `stock` | Yes |
| `tests/Feature/StockPurchaseFlowTest.php` | Stock purchase feature coverage | No | `stock` | Yes |
| `tests/Feature/CatalogFastStockFlowTest.php` | Fast stock domain coverage | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionApplyTest.php` | Controlled correction coverage | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionDryRunTest.php` | Controlled correction dry-run coverage | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionIdempotencyTest.php` | Controlled correction idempotency | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionNoSideEffectsTest.php` | Controlled correction side-effect guard | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionReservationGuardTest.php` | Controlled correction reservation guard | No | `stock` | Yes |
| `tests/Feature/Et0506ExactVariantCorrectionSumGuardTest.php` | Controlled correction sum guard | No | `stock` | Yes |

## Mixed Files Explicitly Excluded
The following files contain required stock-related hunks, but those hunks are inseparable from non-stock local product / template / gallery scope in the current worktree. They were intentionally not staged:

| File | Why mixed | Decision |
|---|---|---|
| `app/Http/Controllers/Admin/TenantCatalogController.php` | Combines stock entry/cancel, supplier-stock redirect, exact variant detail, local product create/edit/import, image upload, import template, and template-facing logic | Exclude from stock-only checkpoint |
| `resources/views/admin/catalog/show.blade.php` | Combines exact variant detail route support with approved compact gallery/template redesign | Exclude from stock-only checkpoint |

Additional mixed files that were not safe to include in this strict checkpoint:

| File | Why mixed | Decision |
|---|---|---|
| `routes/web.php` | Stock purchase routes are interleaved with local-products, exact variant detail, and legacy catalog stock-entry routes | Defer until controlled split |
| `config/admin_menu.php` | Local products navigation restructure is bundled with stock-adjacent navigation | Defer until local products phase |
| `app/Services/AdminMenuService.php` | Menu accordion behavior fix is broader than stock-only scope | Defer |
| `app/Http/Controllers/Admin/SupplierProcurementRequestController.php` | Catalog procurement prefill is mixed with procurement price-refresh and completed-edit behavior | Defer |
| `app/Services/TenantCatalog/TenantCatalogListRowQueryService.php` | Contains local stock truth plus broader catalog row and quote-facing changes | Defer |
| `resources/views/admin/catalog/index.blade.php` | Contains `Stoğa Al` / procurement CTA plus broader catalog action cleanup | Defer |
| `resources/views/admin/procurements/supplier-requests/create.blade.php` | Catalog prefill is mixed with procurement reference-family UI redesign | Defer |

## Excluded Unrelated Dirty Areas
- Quote freshness / quote form / catalog search work
- Order workflow / no-print / procurement admission work
- Product Hub onboarding and source UI work
- Local product create/import/image/template work
- Process depth, procurement pricing, public/customer, and finance surface changes
- Global CSS and broad admin layout changes
- Historical docs and prompt archives outside the stock checkpoint closeout

## Commit/Tag Outcome
- Commit 1 `stock: add exact-variant stock entry and purchase flow`: not attempted
- Commit 2 `catalog: connect exact variants to stock and procurement`: not attempted
- Commit 3 `docs: close simple stock entry purchase flow`: not attempted as part of git checkpointing
- Local tag `checkpoint-simple-stock-flow-20260717`: not created

## Staging Safety
- No staged changes were left behind by this checkpoint audit.
- No `reset`, `restore`, `stash`, `clean`, or destructive git command was used.
- Mixed files remain unstaged in the worktree for the next controlled split phase.

## Next Controlled Phase
- `Kendi Ürünlerim - exact varyant create/edit/import`
- In that phase, `TenantCatalogController.php` and `resources/views/admin/catalog/show.blade.php` must be split deliberately and committed under the correct local products scope, not under this stock-only checkpoint.

## Final
BLOCKED - MIXED HUNK REQUIRES MANUAL SPLIT

## Addendum - D1 Mixed Split Readiness Update (2026-07-17)
- admin.catalog.local-products* route ownership artık dedicated controller'lara ayrıldı:
  - LocalProductController
  - LocalProductImportController
- Own product detail için dedicated view aktif:
  -
esources/views/admin/catalog/local-products/show.blade.php
- Generic /admin/catalog/{product} ve /admin/catalog/{product}/variants/{variant} istekleri own product ise dedicated local-product detail surface'ına delegate ediliyor.
- TenantCatalogController içindeki route almayan local-product create/store/update/import public write surface kaldırıldı.
- Safe split sonrası stock checkpoint için önceki iki ana mixed dosya durumu güncellendi:
  - app/Http/Controllers/Admin/TenantCatalogController.php -> local-product write yükü ayrıştırıldı; stock/generic catalog sorumluluğu kaldı.
  -
esources/views/admin/catalog/show.blade.php -> generic catalog detail yüzeyi olarak kaldı; own-product detail yükü dedicated surface'a taşındı.
- Bu nedenle sonraki kontrollü checkpoint denemesinde stock çekirdeği ile local-product create/edit/import alanı artık daha temiz sınırlarla ayrıştırılabilir.
- Staging veya commit bu addendum fazında da yapılmadı.
