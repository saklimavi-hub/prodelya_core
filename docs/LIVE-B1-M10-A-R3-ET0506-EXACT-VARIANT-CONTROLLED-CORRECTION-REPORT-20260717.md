# LIVE B1 M10-A R3 ET-0506 Controlled Variant Correction Report

Date: 2026-07-17
Scope: ET-0506 exact variant stock identity correction only
Status: APPLIED

## Business confirmation basis

- Tenant/product scope: tenant `2`, product `7817` (`ET-0506`)
- Exact variant truth:
  - `27668` => `ET-0506-MV` => `1000`
  - `27676` => `ET-0506-K` => `1000`
- Historical evidence:
  - supplier purchase entry `1` confirms `ET-0506-MV = 1000`
  - supplier purchase entry `2` confirms `ET-0506-K = 1000`
- Legacy operational row:
  - `tenant_local_stocks.id = 1`
  - product scope, no variant binding
  - `quantity_on_hand = 2000`

## Implemented control surface

- New service: `app/Services/Stock/LocalStockExactVariantCorrectionService.php`
- New command: `app/Console/Commands/RepairLocalStockVariantsCommand.php`
- Command contract:
  - dry-run or apply
  - idempotent
  - no StockMovement write
  - no current-account write
  - no notification write
  - no procurement write
  - no quote write
- Guards:
  - active reservation block
  - duplicate exact row block
  - tenant/product/variant binding block
  - sum mismatch block

## Dry-run evidence

Command:

```powershell
php artisan prodelya:repair-local-stock-variants --tenant=2 --product=7817 --legacy-stock=1 --map=27668:1000 --map=27676:1000 --dry-run
```

Observed result:

- `Status: dry_run`
- `Message: DRY-RUN READY`
- `Writes: 0`
- legacy row `1` still `on_hand=2000`, `reserved=0`, `available=2000`
- exact rows to create:
  - row for `27668 / ET-0506-MV / 1000`
  - row for `27676 / ET-0506-K / 1000`
- totals:
  - before operational `2000.0000`
  - after operational exact `2000.0000`
  - double count `0.0000`
- all guards `PASS`
- side effect deltas all `0`
- remaining legacy warning:
  - count `3`
  - quantity `28.0000`

## Apply evidence

Apply command used:

```powershell
php artisan prodelya:repair-local-stock-variants --tenant=2 --product=7817 --legacy-stock=1 --map=27668:1000 --map=27676:1000 --apply --actor=codex
```

Post-apply confirmation from follow-up dry-run:

- `Status: already_corrected`
- `Message: already_corrected`
- `Writes: 0`
- legacy row `1` retained but neutralized:
  - `on_hand=0.0000`
  - `reserved=0.0000`
  - `available=0.0000`
  - `status=resolved_exact_variant`
- exact operational rows now present:
  - existing row `7` => `27668 / ET-0506-MV / 1000.0000`
  - existing row `8` => `27676 / ET-0506-K / 1000.0000`
- totals preserved:
  - before operational `2000.0000`
  - after operational exact `2000.0000`
  - double count `0.0000`
- side effect deltas still all `0`
- remaining legacy warning still:
  - count `3`
  - quantity `28.0000`

## Idempotency note

- A parallel second `--apply` verification call produced a SQLite `database is locked` error.
- This did not change business state.
- Canonical single-thread proof is the immediate follow-up dry-run returning `already_corrected` with `Writes: 0`.
- Therefore the correction is treated as successfully applied and idempotent under normal single-call usage.

## UI and list impact

Expected supplier-local list state after correction:

- show `ET-0506-MV Plastik Kalem Mavi — 1000`
- show `ET-0506-K Plastik Kalem Kırmızı — 1000`
- do not show parent aggregate row `ET-0506 ... 2000`
- remaining warning area reflects only other unresolved legacy records

Exact variant detail coverage:

- `İncele` from exact variant rows resolves to the exact variant detail route
- parent technical detail route remains available for catalog context

## Non-target records explicitly untouched

- `AK-850`
- `AK-2420`
- `PZ-KL01`
- `TS-2026-0015`
- ET-0506 stock movement history
- reservations outside ET-0506 correction guards

## Test coverage completed

Targeted:

- `Et0506ExactVariantCorrectionDryRunTest`
- `Et0506ExactVariantCorrectionApplyTest`
- `Et0506ExactVariantCorrectionIdempotencyTest`
- `Et0506ExactVariantCorrectionSumGuardTest`
- `Et0506ExactVariantCorrectionReservationGuardTest`
- `Et0506ExactVariantCorrectionNoSideEffectsTest`
- `Et0506SupplierLocalListAfterCorrectionTest`
- `Et0506VariantDetailAfterCorrectionTest`

Regression and broad:

- supplier-local exact variant list tests
- supplier-local legacy warning tests
- exact variant detail route tests
- `TenantCatalog`
- `Stock`
- `LocalProducts`
- `CatalogSearch`
- `PromotionQuote`
- `AdminSmokeTest`
- `view:cache`

## Closeout

Result: ET-0506 exact variant identity correction is now live, controlled, idempotent on re-run, and preserves total operational stock at `2000` without creating financial, procurement, notification, quote, or movement side effects.
