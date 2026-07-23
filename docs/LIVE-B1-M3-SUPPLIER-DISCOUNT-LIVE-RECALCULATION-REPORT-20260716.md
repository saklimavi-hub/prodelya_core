# LIVE-B1-M3 — Supplier Discount Live Recalculation Report — 2026-07-16

## 1. Browser evidence

Blocked live record:
- request: `TS-2026-0014`
- supplier: `Pozitron Promosyon`

Observed mismatch:
- `PZ-CH30SY`: `12.50 USD × 47.0098 = 587.62 TL` gross stayed visible as final unit / total under `%55` discount
- `PZ-CH60SY`: `3.50 USD × 47.0098 = 164.53 TL` gross stayed visible as final unit under `%55` discount

Expected:
- `PZ-CH30SY`: gross `587.6225` -> calculated `264.430125` -> UI `264,43 TL` -> qty10 total `2.644,30 TL`
- `PZ-CH60SY`: gross `164.5343` -> calculated `74.040435` -> UI `74,04 TL`

## 2. Root cause

Exact drop point result:
- `ProcurementPurchasePricingService` already applies discount to gross TRY correctly.
- `SupplierProcurementRequestService` already recomputes canonical final unit and total on save, and does not trust client-side calculated/total payloads.
- The live mismatch was in the edit screen contract:
  - Blade rendered stored `purchase_unit_price` / `purchase_total`
  - there was no live recalculation on discount / quantity input
  - `Hesaplananı kullan` only copied an old value into the input and did not update calculated/final/total as a coherent state machine
- Additional backend gap found:
  - completed price-correction validation did not accept `use_calculated_price`, so completed rows could fail to clear manual override through the canonical helper path

Classification:
- `A. service discount missing` => `FALSE`
- `B. Blade/JS binds gross or stale final without live recalculation` => `TRUE`
- `C. mixed old-field drift` => `PARTIAL TRUE` because completed correction path also lacked `use_calculated_price` validation

## 3. Canonical pricing contract

Canonical fields preserved:
- `purchase_source_amount`
- `purchase_source_currency`
- `purchase_fx_rate`
- `purchase_list_price_try`
- `discount_rate`
- `purchase_calculated_unit_price`
- `purchase_manual_unit_price`
- `purchase_manual_override`
- `purchase_unit_price`
- `purchase_total`

Formula:
- `gross_try = source_amount × fx_rate`
- `calculated_unit = gross_try × (1 - discount_rate / 100)`
- `final_unit = manual_override ? manual_unit : calculated_unit`
- `purchase_total = final_unit × quantity`

## 4. Frontend live calculation

Applied:
- supplier-request edit row now exposes row-level raw gross TRY payload through `data-purchase-row` + `data-list-price-try`
- discount input, quantity input, unit input, calculated display and total cell are all bound with explicit selectors
- `DOMContentLoaded` bootstrap recalculates rows immediately, so stale gross stored finals are corrected in the browser before save when override is inactive
- `input` + `change` on discount and quantity update:
  - calculated unit
  - final unit when override inactive
  - total
- manual unit edit flips override active and preserves final price while recalculating the calculated reference
- unresolved source edge-case keeps calculated display as `-` but still recomputes total from manual price if the user enters one

## 5. Backend recalculation

Confirmed:
- controller normalizes decimals and forwards only canonical editable inputs
- service ignores client `purchase_calculated_unit_price` / `purchase_total` tampering
- save path recomputes from source amount / currency / FX / discount / quantity / manual override

## 6. Manual override

Preserved contract:
- manual unit edit sets override active
- later discount changes refresh calculated reference only
- final unit and total remain on manual price
- `Hesaplananı kullan` clears override and restores final unit to calculated

Browser truth:
- automated manual-override test PASS
- manual browser override flow `250,00 -> Hesaplananı kullan` is still `PENDING`

## 7. Snapshot parity

Confirmed snapshot fields remain aligned:
- source amount
- source currency
- FX rate/date/source
- gross TRY
- discount
- calculated unit
- manual override state
- final unit
- quantity basis
- purchase total

## 8. Currency isolation

Confirmed:
- discount changes do not mutate original source amount
- discount changes do not mutate source currency
- discount changes do not mutate FX rate
- discount changes do not mutate gross TRY truth

## 9. Completed row behavior

Applied:
- completed correction validation now accepts `use_calculated_price`
- quantity lock remains intact
- completed rows can clear manual override back to calculated price without unlocking received/remaining quantities

## 10. Current-account boundary

Confirmed by targeted + broad tests:
- no duplicate current-account side effect introduced
- procurement price updates continue to sync canonical final total only through existing lifecycle boundary
- this phase did not rewrite current-account algorithm

## 11. Supplier print security

Preserved:
- supplier-facing print remains price-free
- no source price / discount / purchase unit / purchase total leak was reintroduced

## 12. Targeted tests

PASS:
- `ProcurementPurchaseDiscountCalculationTest`
- `SupplierProcurementDiscountLiveUiContractTest`
- `ProcurementPurchaseManualOverrideTest`
- `ProcurementPurchaseUseCalculatedPriceTest`
- `ProcurementPurchaseServerRecalculationTest`
- `ProcurementPurchaseSnapshotParityTest`
- `ProcurementPurchaseDiscountCurrencyIsolationTest`
- `CompletedSupplierProcurementDiscountCorrectionTest`

Existing PASS after alignment:
- `SupplierProcurementRequestPriceReferenceTest`
- `SupplierProcurementRequestEditFixTest`
- `ProcurementPurchasePriceSnapshotTest`
- `ProcurementPurchasePriceCurrencyIsolationTest`
- `CompletedSupplierProcurementPurchasePriceUpdateTest`
- `SupplierRequestPriceFreePrintReferenceTest`

## 13. Broad tests

PASS:
- `Procurement`
- `CurrentAccount`
- `AdminSmokeTest`

Note:
- one temporary failure during targeted execution came from parallel artisan cache-file rename contention, not business logic. The affected test passed on sequential rerun.

## 14. Manual TS-2026-0014 resmoke

Status: `PARTIAL PASS — READ-ONLY SNAPSHOT MATCHES CALCULATED STATE, FINAL BROWSER OVERRIDE STEP PENDING`

User-confirmed PASS:
1. Akdeniz TRY liste `16,78` / `%55` -> `7,55 TL`, qty10 -> `75,51 TL`
2. Pozitron `12,50 USD` / `%55` -> `264,43 TL`, qty10 -> `2.644,30 TL`
3. Pozitron `3,50 USD` / `%55` -> `74,04 TL`
4. save + reopen normal calculated values korunuyor

Still required to close browser smoke completely:
1. first row manual override `250,00` should keep final `250,00 TL` and total `2.500,00 TL`
2. `Hesaplananı kullan` should restore final `264,43 TL` and total `2.644,30 TL`

## 15. Worktree / staging / commit

- staging yapılmadı
- commit yapılmadı
- supplier-facing print fiyat-free kaldı
- lifecycle / completed quantity lock / current-account core bozulmadı

## 16. Gate

Current status:
- `RECOVERED — SUPPLIER DISCOUNT LIVE RECALCULATION AND PURCHASE SNAPSHOT PARITY — MANUAL OVERRIDE BROWSER STEP PENDING`
