# PROCUREMENT CANONICAL PRICE / CURRENCY SNAPSHOT FOUNDATION REPORT

Date: 2026-07-14
Phase: `PRODELYA_V1_10.16.5_F1P1`
Decision: `IMPLEMENTED — CANONICAL PROCUREMENT PRICE / CURRENCY SNAPSHOT FOUNDATION READY — TESTS PASS — MANUAL UI NOT REQUIRED`

## 1. Preflight

- `git diff --cached --stat`: empty
- Audit input present: `docs/SALES-PROCUREMENT-PRICE-CURRENCY-TRUTH-AUDIT-REPORT-20260714.md`
- Worktree dirty and preserved
- F1R/F1H2 procurement worktree changes preserved
- No staging
- No commit

## 2. Migration

Added:

- `database/migrations/2026_07_14_120000_add_purchase_price_snapshot_fields_to_supplier_procurement_request_items.php`

Applied locally:

- `2026_07_14_120000_add_purchase_price_snapshot_fields_to_supplier_procurement_request_items .. Ran`

## 3. New Fields

Added to `supplier_procurement_request_items`:

- `purchase_source_amount`
- `purchase_source_currency`
- `purchase_fx_rate`
- `purchase_fx_rate_date`
- `purchase_fx_rate_source`
- `purchase_list_price_try`
- `purchase_calculated_unit_price`
- `purchase_manual_unit_price`
- `purchase_manual_override`
- `purchase_manual_override_reason`
- `purchase_settlement_currency`
- `purchase_price_snapshot`
- `purchase_price_snapshot_version`

Legacy scalar compatibility preserved:

- `purchase_list_price`
- `discount_rate`
- `purchase_unit_price`
- `purchase_total`

## 4. Canonical Supplier Source Order

Implemented in:

- `app/Services/Procurement/SupplierPurchasePriceSourceResolver.php`

Resolution order:

1. Existing procurement snapshot source if already present
2. Supplier variant raw -> parent raw purchase truth
3. Supplier raw `purchase_price`
4. Supplier raw `source_price`
5. Standard product `purchase_price`
6. Historical `ProductPriceSnapshot.purchase_price`

Resolution outputs:

- source type
- source id
- supplier product code
- supplier variant code
- original amount
- original currency
- price updated at
- resolution status
- warning code

## 5. Forbidden Sales Fallback List

Procurement source resolution no longer uses:

- `OrderItem.list_price`
- `OrderItem.unit_price`
- `OrderItem.price_snapshot.list_price`
- quote sales `source_price/source_currency`
- tenant catalog display/sale price
- standard product selling price
- quote/customer-facing sales price

Removed from procurement suggestion flow by replacing mixed candidate fallback logic in:

- `app/Services/SupplierProcurementRequestDataBuilder.php`

## 6. Resolver

Implemented:

- `SupplierPurchasePriceSourceResolver`

Key behaviors:

- Sales-side fallback chain removed
- Supplier original amount/currency resolved together
- Missing supplier purchase source returns `unresolved`
- Legacy snapshot returns `legacy_snapshot`
- Silent sales fallback prohibited

## 7. Pricing Service

Implemented:

- `app/Services/Procurement/ProcurementPurchasePricingService.php`

Responsibilities:

- source resolve
- FX snapshot resolve
- TRY equivalent calculate
- discount calculation
- manual override merge
- final unit / total calculation
- immutable snapshot payload build

## 8. TRY Case

Supported contract:

- source currency `TRY`
- fx rate `1`
- list TRY = source amount
- calculated unit uses 6-decimal precision
- legacy scalar fields still receive rounded compatibility values

## 9. USD Case

Supported contract:

- original supplier amount/currency persisted
- FX rate/date/source persisted
- TRY equivalent persisted
- calculated unit persisted separately
- manual/final unit separated
- settlement currency remains `TRY`

Verified by:

- `ProcurementPurchasePriceSnapshotTest`

## 10. EUR Case

Supported with same contract as USD.

Verified by:

- `ProcurementPurchasePriceSnapshotTest`

## 11. Manual Override

Behavior:

- manual unit does not erase source amount/currency/rate
- `purchase_manual_override = true`
- `purchase_manual_unit_price` stores manual TRY unit
- `purchase_unit_price` keeps final compatibility value
- `purchase_calculated_unit_price` remains preserved

## 12. Unresolved / Legacy Behavior

Unresolved:

- no supplier purchase source
- no sales fallback
- `resolution_status = unresolved`
- warning code stored

Legacy:

- old scalar-only row not backfilled from sales snapshot
- `resolution_status = legacy_snapshot`
- `warning_code = legacy_unknown`

## 13. Historical Lock

Implemented rule:

- existing canonical procurement snapshot does not live-refresh source amount
- existing canonical procurement snapshot does not live-refresh FX
- update path recalculates from stored snapshot + current edit inputs only

Verified by:

- changing raw purchase price and exchange rate after draft creation
- updating request item
- asserting original source amount and FX remained unchanged

## 14. Precision

Implemented precision split:

- source amount: 6 decimal
- FX rate: 8 decimal
- TRY list equivalent: 6 decimal
- calculated/manual unit: 6 decimal
- legacy scalar unit/list: 2 decimal compatibility
- total: 2 decimal

Discount calculation updated to decimal-safe math path.

## 15. Changed Files

Foundation files:

- `database/migrations/2026_07_14_120000_add_purchase_price_snapshot_fields_to_supplier_procurement_request_items.php`
- `app/Models/SupplierProcurementRequestItem.php`
- `app/Services/Procurement/SupplierPurchasePriceSourceResolver.php`
- `app/Services/Procurement/ProcurementPurchasePricingService.php`
- `app/Services/SupplierProcurementRequestService.php`
- `app/Services/SupplierProcurementRequestDataBuilder.php`
- `tests/Feature/ProcurementPurchasePriceSourceResolverTest.php`
- `tests/Feature/ProcurementPurchasePriceSnapshotTest.php`
- `tests/Feature/ProcurementPurchasePriceCurrencyIsolationTest.php`

## 16. Tests

Passed:

- `php artisan test --filter=ProcurementPurchasePriceSourceResolver --stop-on-failure`
- `php artisan test --filter=ProcurementPurchasePriceSnapshot --stop-on-failure`
- `php artisan test --filter=ProcurementPurchasePriceCurrencyIsolation --stop-on-failure`
- `php artisan test --filter=CompletedSupplierProcurementPurchasePriceUpdate --stop-on-failure`
- `php artisan test --filter=ProcurementNewReferenceFamily --stop-on-failure`
- `php artisan test --filter=ProcurementProcessDepthUi --stop-on-failure`
- `php artisan test --filter=SupplierProcurementRequestUiTest --stop-on-failure`
- `php artisan test --filter=ProcurementCoreTest --stop-on-failure`
- `php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure`
- `php artisan test --filter=ProcessDepth --stop-on-failure`
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`

## 17. Migration Status

- Local migration applied successfully
- `migrate:status` shows new migration as `Ran`

## 18. Worktree / Staging

- Staging: empty
- Commit: none
- Worktree: still dirty, preserved
- Unrelated changes: untouched

## 19. F1P2 Gate

F1P2 can start because:

- procurement source no longer falls back to sales list
- original amount/currency contract exists
- FX snapshot contract exists
- unresolved and legacy behavior are explicit
- current account behavior was not changed
- quote behavior was not changed

## Console Summary

- A) Preflight: `PASS`
- B) Canonical supplier source: `Implemented`
- C) Forbidden sales fallback removed: `Yes`
- D) Migration: `Ran`
- E) Original amount/currency: `Persisted`
- F) FX snapshot: `Persisted`
- G) TRY equivalent: `Persisted`
- H) Discount calculation: `Decimal-safe`
- I) Manual override: `Separated and preserved`
- J) Legacy behavior: `Explicit legacy_snapshot / legacy_unknown`
- K) Historical lock: `Implemented`
- L) Precision: `6/8/2 split implemented`
- M) Current account changed: `No`
- N) Quote changed: `No`
- O) New tests: `3 added`
- P) Procurement regressions: `PASS`
- Q) Quote regression: `PASS`
- R) ProcessDepth: `PASS`
- S) AdminSmokeTest: `PASS`
- T) Full suite: `Not run`
- U) New failures: `None in targeted scope`
- V) Production files: `Foundation-only procurement backend files changed`
- W) Staging: `Empty`
- X) Commit: `No`
- Y) Report: `docs/PROCUREMENT-CANONICAL-PRICE-CURRENCY-SNAPSHOT-FOUNDATION-REPORT-20260714.md`
- Z) F1P2 gate: `Open`
- AA) Final decision: `IMPLEMENTED — CANONICAL PROCUREMENT PRICE / CURRENCY SNAPSHOT FOUNDATION READY — TESTS PASS — MANUAL UI NOT REQUIRED`
