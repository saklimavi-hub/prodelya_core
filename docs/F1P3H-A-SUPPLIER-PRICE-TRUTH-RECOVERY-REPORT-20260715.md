# F1P3H-A Supplier Price Truth Recovery Report — 2026-07-15

## 1. Scope
This phase closed only the supplier price truth and legacy draft refresh gap.

Out of scope and untouched:
- cancelled procurement restore / `İptali Geri Al`
- current-account production algorithm refactor
- quote pricing
- Product Hub sync/import
- public/customer changes
- staging / commit

## 2. Request 10 identity correction
Audit truth used in this phase:

- Request: `10`
- Status: `taslak`
- Actual SKU: `PZ-CH60SY`
- Canonical exact raw supplier source: `3.5 USD`
- Canonical FX: `46.9966 TRY`
- Canonical TRY equivalent: `164.4881 TRY`
- Canonical final unit display: `164.49 TL`
- Canonical total display: `1.644,88 TL`

The previously mentioned `PZ-CH30SY` identity was not used.

## 3. Legacy scalar diagnosis
The audited legacy failure mode was:

- original source amount/currency/rate fields absent on the request row
- presenter could only show scalar TL fallback
- visible `Alış Birim Fiyatı` input was blank unless a manual override existed
- no explicit canonical refresh action existed
- edit GET did not silently mutate the row

## 4. Effective purchase unit binding
Updated files:

- `app/Services/SupplierProcurementRequestDataBuilder.php`
- `resources/views/admin/procurements/supplier-requests/edit.blade.php`

What changed:

- presenter now exposes `effective_unit_value` and `calculated_unit_value`
- visible `Alış Birim Fiyatı` input now binds to effective final unit
- `Hesaplananı kullan` now restores the calculated value in the input instead of blanking it
- manual override semantics are still controlled by hidden `use_calculated_price`

## 5. Refresh route, service and guards
Updated files:

- `routes/web.php`
- `app/Http/Controllers/Admin/SupplierProcurementRequestController.php`
- `app/Services/SupplierProcurementRequestService.php`
- `app/Services/Procurement/ProcurementPurchasePricingService.php`

Added explicit mutation path:

- Route: `admin.procurements.supplier-requests.refresh-prices`
- Method: `POST`
- UI label: `Tedarikçi Fiyatını Yenile`

Backend guards enforced:

- same tenant
- `manage_procurement_requests`
- `view_procurement_purchase_prices`
- draft request only
- received quantity must remain zero
- completed/cancelled flow not allowed through draft guard
- finalized current-account transaction blocked (`paid`, `partially_paid`, `closed`)

Edit GET remains non-mutating.

## 6. Snapshot before/after contract
Before explicit refresh on a legacy row:

- canonical source fields can be absent/incomplete
- page load does not rewrite them
- scalar TL values may still render from legacy state

After explicit refresh on an eligible draft row:

- exact supplier original amount/currency is written
- FX rate/date/source are written
- TRY equivalent is written
- calculated unit is written
- final unit is calculated unit unless a deliberate manual override already exists
- canonical snapshot version `1` is materialized

## 7. Exact variant proof and no sales fallback
No sales fallback was introduced.

Proof points implemented and tested:

- exact supplier raw source attribution test
- sibling variant does not replace exact variant truth
- UI labels show supplier original amount/currency and procurement FX, not sales wording
- unresolved source keeps explicit procurement warning and does not invent `0,00` source truth

## 8. Current-account contract recovery
The failing drift `765.00` vs `828.00` was recovered in test layer only.

What changed:

- `tests/Feature/SupplierProcurementCurrentAccountTransactionTest.php` now expresses the correction as explicit manual override (`7.65`) instead of pretending scalar `purchase_list_price` mutation alone changes canonical source truth.

What did not change:

- production current-account sync algorithm
- production procurement amount calculation contract

## 9. Targeted tests added
New files created:

- `tests/Feature/ProcurementSupplierPriceSourceAttributionTest.php`
- `tests/Feature/ProcurementSupplierExactVariantPriceTest.php`
- `tests/Feature/ProcurementSupplierPricePresenterBindingTest.php`
- `tests/Feature/ProcurementDraftPriceRefreshTest.php`
- `tests/Feature/ProcurementSupplierPriceLabelIntegrityTest.php`

## 10. Exact test results
Narrow filters:

- `ProcurementSupplierPriceSourceAttribution` => PASS, 1 test, 7 assertions
- `ProcurementSupplierExactVariantPrice` => PASS, 1 test, 3 assertions
- `ProcurementSupplierPricePresenterBinding` => PASS, 1 test, 4 assertions
- `ProcurementDraftPriceRefresh` => PASS, 4 tests, 30 assertions
- `ProcurementSupplierPriceLabelIntegrity` => PASS, 2 tests, 13 assertions
- `SupplierProcurementCurrentAccountTransaction` => PASS, 2 tests, 34 assertions
- `SupplierProcurementRequestPriceReference` => PASS, 5 tests, 41 assertions
- `ProcurementPurchasePriceSnapshot` => PASS, 2 tests, 18 assertions
- `ProcurementPurchasePriceCurrencyIsolation` => PASS, 2 tests, 10 assertions
- `CompletedSupplierProcurementPurchasePriceUpdate` => PASS, 4 tests, 37 assertions
- `SupplierRequestPriceFreePrintReference` => PASS, 1 test, 16 assertions

Broad procurement filters:

- `SupplierProcurementRequest` => PASS, 30 tests, 259 assertions
- `ProcurementPurchasePrice` => PASS, 9 tests, 70 assertions
- `Procurement` => PASS, 103 tests, 1575 assertions

## 11. Manual status
Automated browser smoke was not fabricated.

Current manual state:

- implementation complete
- broad procurement pass complete
- user manual browser smoke still pending

Manual checklist still required on Request `10`:

- legacy draft row visible before refresh
- no GET silent mutation
- refresh confirmation appears
- post-refresh values show `3,50 USD / 46,9966 / 164,49 / 1.644,88`
- manual unit edit persists
- `Hesaplananı kullan` restores calculated value
- unauthorized/cross-tenant refresh stays blocked
- supplier print remains price-free

## 12. Staging / commit
- No staging performed
- No commit performed

## 13. F1P3H-B gate
`İptali Geri Al` / cancelled procurement restore remains unopened in this phase.
That lifecycle should continue only as separate `F1P3H-B` work after manual smoke.

Final status:

IMPLEMENTED — SUPPLIER PRICE TRUTH AND LEGACY DRAFT REFRESH RECOVERED — BROAD PROCUREMENT PASS — USER MANUAL SMOKE PENDING
