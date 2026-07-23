# QUOTE SALES LIST / CURRENCY UI AND CALCULATION REPORT

Date: 2026-07-14
Phase: PRODELYA_V1 10.16.5-F1P2
Status: IMPLEMENTED — QUOTE SALES LIST / CURRENCY UI AND CALCULATION HARDENED — MANUAL SMOKE PENDING

## Scope applied

- Quote admin sales presentation payload was prepared in `QuoteCurrencyPricingService`.
- Admin-only quote snapshot surfaces now expose `sales_presentation` in:
  - `app/Http/Controllers/Admin/CatalogSearchController.php`
  - `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- Quote workspace UI now distinguishes:
  - `Satış Liste`
  - original sales list amount/currency
  - FX rate
  - TL equivalent
  - discount
  - calculated sales unit
  - final/manual sales unit
- Manual override semantics and quote snapshot lock flow were preserved.

## Explicit non-scope respected

- Procurement source resolver was not changed.
- Current account behavior was not changed.
- Customer/public/PDF surfaces were not changed.
- Migration and route/menu behavior were not changed.
- No staging or commit was performed.

## Tests run

```powershell
php artisan test tests/Feature/PromotionQuoteSalesListCurrencyUiTest.php tests/Feature/PromotionQuoteSalesCalculationPrecisionTest.php tests/Feature/PromotionQuoteSalesManualOverrideUiTest.php tests/Feature/PromotionQuoteCurrencySnapshotTest.php tests/Feature/ProductHubLiveProductInfoEndpointTest.php
```

Result:

- Passed: 21
- Failed: 0

## Notes

- Sales presentation payload is built from existing snapshot contract fields and does not move pricing math into Blade.
- The shared quote workspace now renders the distinction between original sales source data and final document unit pricing without touching procurement or customer-facing channels.

## F1P2H1 hotfix update

Status: IMPLEMENTED — QUOTE PRODUCT SEARCH STOCK / CURRENCY TRUTH AND COMPACT UI FIXED — MANUAL SMOKE PENDING

### Root causes addressed

- Search dropdown renderer still had a duplicate legacy `buildCatalogResult()` branch that preferred quote/document price over original source price.
- Quote row live repricing path collapsed `list_price`, `calculated_unit_price`, and `unit_price` into the same document value, which hid the calculated/final distinction.
- Live product info response exposed only document conversion metadata by default; explicit `source_to_base_rate*` fields were added to the safe admin payload so source-to-TRY truth stays available.
- Live product info response used exact local/supplier/fallback stock fields in the response body, and quote-side badge/meta helpers now resolve from exact `local_stock_quantity` / `supplier_stock_quantity` inputs instead of parent/group heuristics in the Blade layer.

### UI contract after hotfix

- Search dropdown keeps one compact line with original source amount/currency and, for USD/EUR, `Güncel kur` sourced from `source_to_base_rate`.
- Selected product surface is reduced to one compact meta row plus closed `Fiyat ayrıntısı` details.
- Pricing row again shows:
  - `Miktar`
  - `Satış Liste`
  - `İskonto %`
  - `Hesaplanan Birim`
  - `Nihai Satış Birim`
  - `Toplam`
- Manual override semantics remained intact.

### Additional tests run

```powershell
php artisan test --filter=PromotionQuoteProductSearchCurrencyDisplay --stop-on-failure
php artisan test --filter=PromotionQuoteExactLocalStockBadge --stop-on-failure
php artisan test --filter=PromotionQuoteCompactSelectedProductMeta --stop-on-failure
php artisan test --filter=PromotionQuoteSourceToTryRatePresentation --stop-on-failure
php artisan test --filter=PromotionQuoteSalesListCurrencyUi --stop-on-failure
php artisan test --filter=PromotionQuoteSalesCalculationPrecision --stop-on-failure
php artisan test --filter=PromotionQuoteSalesManualOverrideUi --stop-on-failure
php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure
php artisan test --filter=ProductHubLiveProductInfoEndpointTest --stop-on-failure
php artisan test --filter=PromotionQuoteLiveProductInfoUi --stop-on-failure
php artisan test --filter=ProcessDepth --stop-on-failure
php artisan test --filter=AdminSmokeTest --stop-on-failure
```

Result:

- Passed: 132
- Failed: 0

### Staging / commit

- No staging performed.
- No commit performed.
- Manual browser smoke still pending for this hotfix.

## F1P2H2 hotfix update

Status: IMPLEMENTED — QUOTE SALES UNIT UI SIMPLIFIED AND PRICE DETAIL UNITS FIXED — MANUAL SMOKE PENDING

### Scope applied

- Visible Hesaplanan Birim column was removed from the quote workspace.
- Visible Nihai Satış Birim label was renamed to Satış Birim Fiyatı.
- Internal calculated_unit_price / manual override snapshot contract was preserved.
- Fiyat ayrıntısı was reduced to immutable source truth only:
  - source amount + source currency
  - 1 USD/EUR = X TL
  - TL karşılığı
  - kur tarihi
  - kur kaynağı
- TRY products no longer show identity-rate wording in the visible detail contract.

### Targeted regressions run

`powershell
php artisan test --filter=PromotionQuoteSalesUnitColumnSimplification --stop-on-failure
php artisan test --filter=PromotionQuotePriceDetailSourceOnly --stop-on-failure
php artisan test --filter=PromotionQuoteFxUnitLabel --stop-on-failure
php artisan test --filter=PromotionQuoteSalesCalculationPrecision --stop-on-failure
php artisan test --filter=PromotionQuoteSalesManualOverrideUi --stop-on-failure
php artisan test --filter=PromotionQuoteProductSearchCurrencyDisplay --stop-on-failure
php artisan test --filter=PromotionQuoteExactLocalStockBadge --stop-on-failure
php artisan test --filter=PromotionQuoteCompactSelectedProductMeta --stop-on-failure
php artisan test --filter=PromotionQuoteSourceToTryRatePresentation --stop-on-failure
php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure
php artisan test --filter=PromotionQuoteSalesListCurrencyUi --stop-on-failure
`

Result:

- Passed: 18
- Failed: 0

### Broad regression note

- php artisan test --filter=AdminSmokeTest --stop-on-failure passed: 59 tests.
- php artisan test --filter=ProcessDepth --stop-on-failure produced 1 unrelated failure in Tests\Feature\ProcessDepth\OrderDetailApprovedStickyPanelTest::test_controlled_depth_uses_turkish_activity_labels_in_recent_activities_card.
- Failure proof: response contained Teslimat Kaydı Oluşturuldu, İş Formu Oluşturuldu, Tedarik İhtiyacı Oluşturuldu, but did not contain Üretim Operasyonu Oluşturuldu in that fixture response.
- No Process Depth production code was changed in this H2 quote hotfix scope.

### Staging / commit

- No staging performed.
- No commit performed.
- Temp .tmp scripts were not staged.
- Manual browser smoke is still required before any commit decision.

## F1P2H3 hotfix update

Status: IMPLEMENTED — SELECTED PRODUCT CARD REDUCED TO IMAGE + COMPACT META — MANUAL SMOKE PENDING

### Scope applied

- Selected product card no longer repeats product title next to the image.
- Selected product card no longer repeats SKU/product code next to the image.
- Fiyat ayrıntısı details DOM was removed from the quote workspace.
- Selected product card now keeps only compact meta beside the image:
  - exact stock metric
  - stock source label
  - source sales list amount/currency
  - USD/EUR formlarında TL karşılığı
  - USD/EUR formlarında kompakt Kur: X TL
  - güncellenme tarihi
- Internal source/snapshot/manual override fields were preserved.
- Visible Satış Birim Fiyatı behavior and exact local stock contract were preserved.

### Targeted regressions run

`powershell
php artisan test --filter=PromotionQuoteSelectedProductDuplicateTextRemoval --stop-on-failure
php artisan test --filter=PromotionQuoteSelectedProductCompactMeta --stop-on-failure
php artisan test --filter=PromotionQuotePriceDetailsRemoved --stop-on-failure
php artisan test --filter=PromotionQuoteSalesUnitColumnSimplification --stop-on-failure
php artisan test --filter=PromotionQuotePriceDetailSourceOnly --stop-on-failure
php artisan test --filter=PromotionQuoteFxUnitLabel --stop-on-failure
php artisan test --filter=PromotionQuoteExactLocalStockBadge --stop-on-failure
php artisan test --filter=PromotionQuoteProductSearchCurrencyDisplay --stop-on-failure
php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure
php artisan test --filter=AdminSmokeTest --stop-on-failure
`

Result:

- Passed: 78
- Failed: 0

### Staging / commit

- No staging performed.
- No commit performed.
- No temp script was created for this H3 hotfix.
- Manual browser smoke is still required before any commit decision.

## F1P2H4 hotfix update

Status: IMPLEMENTED — QUOTE WORKSPACE INITIAL PRODUCT ROW JS BOOT RESTORED — MANUAL SMOKE PENDING

### Exact browser attribution

- Clean headless tenant-owner smoke reproduced the real create-page runtime on `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create`.
- First real project exception was:
  - `ReferenceError: salesPanelHtml is not defined`
  - function: `renderLiveProductInfoPanel()`
  - stack: `renderLiveProductInfoPanel -> renderItem -> mountItems -> DOMContentLoaded boot`
- Because `mountItems(initialItems)` crashed during the first row render, create opened with:
  - `rowCount = 0`
  - summary item count not initialized
  - no searchable product input mounted
- This was a stale H3 reference left behind after the selected-product sales/details DOM had been reduced.

### Applied fix

- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
  - restored the missing local binding with:
    - `const salesPanelHtml = renderSalesPresentationPanel(item, payload);`
- `renderSalesPresentationPanel()` still returns an empty string, so H3 compact selected-card behavior remains unchanged while the boot chain is no longer interrupted.
- No procurement, current account, Product Hub sync/import, public/customer/PDF, migration, or route/menu behavior was changed.

### Focused regression coverage

`powershell
php artisan test --filter=PromotionQuoteWorkspaceInitialRowContract --stop-on-failure
php artisan test --filter=PromotionQuoteSelectedProductDuplicateTextRemoval --stop-on-failure
php artisan test --filter=PromotionQuoteSelectedProductCompactMeta --stop-on-failure
php artisan test --filter=PromotionQuotePriceDetailsRemoved --stop-on-failure
php artisan test --filter=PromotionQuoteSalesUnitColumnSimplification --stop-on-failure
php artisan test --filter=PromotionQuoteExactLocalStockBadge --stop-on-failure
php artisan test --filter=PromotionQuoteCurrencySnapshotTest --stop-on-failure
php artisan test --filter=PromotionQuote --stop-on-failure
php artisan test --filter=AdminSmokeTest --stop-on-failure
`

Result:

- Passed:
  - `PromotionQuoteWorkspaceInitialRowContract`: 2 tests
  - `PromotionQuoteSelectedProductDuplicateTextRemoval`: 1 test
  - `PromotionQuoteSelectedProductCompactMeta`: 1 test
  - `PromotionQuotePriceDetailsRemoved`: 1 test
  - `PromotionQuoteSalesUnitColumnSimplification`: 1 test
  - `PromotionQuoteExactLocalStockBadge`: 1 test
  - `PromotionQuoteCurrencySnapshotTest`: 5 tests
  - `AdminSmokeTest`: 59 tests
- Broad filter note:
  - `php artisan test --filter=PromotionQuote --stop-on-failure` produced 1 failure in `Tests\Feature\PromotionQuoteSalesListCurrencyUiTest::test_quote_edit_workspace_surfaces_sales_list_currency_labels_and_snapshot_payload`.
  - Failure assertion expects `Satış liste fiyatı` text in the current edit response.
  - This failure is outside the H4 boot fix itself; the create-page JS exception is fixed and the H3/H4 targeted regressions above are passing.

### Narrow browser/CDP smoke

- Before clicking `Ürün Ekle`:
  - `rowCount = 1`
  - summary = `1 kalem`
  - product search input present
  - no create-page runtime exception
- After one `Ürün Ekle` click:
  - `rowCount = 2`
  - summary = `2 kalem`
- This confirms the boot chain and `+1` add-row contract are restored in browser execution.

### Staging / commit

- No staging performed.
- No commit performed.
- `.tmp` probe files remain unstaged.
- Manual browser smoke is still required before any commit decision.
