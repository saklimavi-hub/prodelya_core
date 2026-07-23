# LIVE-B1-M9C-R2 Compact Sales Metadata Test Attribution Report — 2026-07-16

Update on 2026-07-17:
- `PromotionQuoteSalesListCurrencyUiTest` no longer asserts removed verbose `bits.push(...)` sales-meta strings.
- The canonical contract is now:
  - selected row does not repeat visible price metadata
  - dropdown result may include compact price metadata
  - hidden sales/currency/FX snapshot payload remains visible in response content
- Current result: PASS (`tests=1`, `assertions=30`)
